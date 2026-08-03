<?php

namespace App\Application\Scheduling;

use App\Domain\Shared\Clock;
use App\Infrastructure\Persistence\Eloquent\SystemHeartbeat;

// Mirrors taskconnect app/Application/Scheduling/HeartbeatWriter.php.
final class HeartbeatWriter
{
    public function __construct(private readonly Clock $clock)
    {
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(string $name, array $meta = []): SystemHeartbeat
    {
        return SystemHeartbeat::query()->updateOrCreate(
            ['name' => $name],
            [
                'last_seen_at' => $this->clock->nowUtc(),
                'meta_json' => $meta,
            ],
        );
    }
}
