<?php

namespace Tests\Feature;

use App\Application\Scheduling\DueMonitorClaimer;
use App\Domain\Shared\FrozenClock;
use DateTimeImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConcurrentDueMonitorClaimerTest extends TestCase
{
    public function test_two_overlapping_claimers_produce_exactly_one_claim(): void
    {
        $databasePath = tempnam(sys_get_temp_dir(), 'statusconnect-claim-race-');
        $barrierPath = $databasePath.'.start';
        $reportPaths = [$databasePath.'.one.json', $databasePath.'.two.json'];

        try {
            $this->configureRaceConnection($databasePath);
            $this->createRaceSchema();
            DB::table('tenants')->insert(['id' => 1]);
            DB::table('environments')->insert(['id' => 1, 'tenant_id' => 1]);
            DB::table('monitors')->insert([
                'id' => 1,
                'tenant_id' => 1,
                'environment_id' => 1,
                'name' => 'Contended monitor',
                'kind' => 'http',
                'target' => 'https://example.test',
                'enabled' => true,
                'interval_seconds' => 60,
                'timeout_ms' => 10_000,
                'next_check_at' => '2026-08-03 11:59:00',
            ]);

            $pids = [];
            foreach ($reportPaths as $reportPath) {
                $pid = pcntl_fork();

                if ($pid === 0) {
                    $this->runClaimerWorker($databasePath, $barrierPath, $reportPath);
                    exit(0);
                }

                $this->assertNotSame(-1, $pid, 'pcntl_fork must create the concurrent claimer worker.');
                $pids[] = $pid;
            }

            file_put_contents($barrierPath, 'start');
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            $reports = array_map(
                static fn (string $path): array => json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR),
                $reportPaths,
            );

            $this->assertSame([], array_filter($reports, static fn (array $report): bool => $report['error'] !== null));
            $this->assertSame([0, 1], collect($reports)->pluck('claimed')->sort()->values()->all());
            $this->assertSame(1, DB::table('monitors')->whereNotNull('claim_token')->count());
        } finally {
            DB::disconnect('scheduler_race');
            DB::purge('scheduler_race');
            DB::setDefaultConnection('sqlite');
            @unlink($barrierPath);
            foreach ($reportPaths as $reportPath) {
                @unlink($reportPath);
            }
            @unlink($databasePath);
        }
    }

    private function runClaimerWorker(string $databasePath, string $barrierPath, string $reportPath): void
    {
        $this->configureRaceConnection($databasePath);

        while (! file_exists($barrierPath)) {
            usleep(1_000);
        }

        try {
            $claimed = (new DueMonitorClaimer(
                new FrozenClock(new DateTimeImmutable('2026-08-03 12:00:00 UTC')),
            ))->claimDueMonitors(limit: 1);

            file_put_contents($reportPath, json_encode(['claimed' => $claimed->count(), 'error' => null], JSON_THROW_ON_ERROR));
        } catch (\Throwable $exception) {
            file_put_contents($reportPath, json_encode(['claimed' => 0, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
        }
    }

    private function configureRaceConnection(string $databasePath): void
    {
        config([
            'database.connections.scheduler_race' => [
                'driver' => 'sqlite',
                'database' => $databasePath,
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('scheduler_race');
        DB::setDefaultConnection('scheduler_race');
    }

    private function createRaceSchema(): void
    {
        $schema = Schema::connection('scheduler_race');
        $schema->create('tenants', function (Blueprint $table): void {
            $table->id();
        });
        $schema->create('environments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
        });
        $schema->create('monitors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('environment_id');
            $table->string('name');
            $table->string('kind');
            $table->string('target');
            $table->boolean('enabled');
            $table->integer('interval_seconds');
            $table->integer('timeout_ms');
            $table->timestamp('next_check_at')->nullable();
            $table->string('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->timestamps();
        });
        $schema->create('monitor_assertions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('monitor_id');
            $table->integer('order')->default(0);
        });
    }
}
