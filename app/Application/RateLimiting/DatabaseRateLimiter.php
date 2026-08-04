<?php

// Ported / adapted from TaskConnect: app/Application/RateLimiting/DatabaseRateLimiter.php

namespace App\Application\RateLimiting;

use App\Domain\Shared\Clock;
use App\Infrastructure\Persistence\Eloquent\RateLimitBucket;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class DatabaseRateLimiter
{
    public function __construct(private readonly Clock $clock)
    {
    }

    public function hitOrFail(string $bucketKey, int $maxAttempts, int $windowSeconds = 60): void
    {
        $retryAfter = $this->tryHit($bucketKey, $maxAttempts, $windowSeconds);

        if ($retryAfter !== null) {
            throw new TooManyRequestsHttpException($retryAfter, 'Too many requests.');
        }
    }

    public function tryHit(string $bucketKey, int $maxAttempts, int $windowSeconds = 60): ?int
    {
        $maxAttempts = max(1, $maxAttempts);
        $windowSeconds = max(1, $windowSeconds);
        $now = $this->clock->nowUtc();

        return DB::transaction(function () use ($bucketKey, $maxAttempts, $windowSeconds, $now): ?int {
            $bucket = RateLimitBucket::query()
                ->where('bucket_key', $bucketKey)
                ->lockForUpdate()
                ->first();

            if ($bucket === null) {
                RateLimitBucket::query()->create([
                    'bucket_key' => $bucketKey,
                    'hits' => 1,
                    'resets_at' => $this->format($now->modify(sprintf('+%d seconds', $windowSeconds))),
                ]);

                return null;
            }

            $resetsAt = DateTimeImmutable::createFromInterface($bucket->resets_at);
            if ($resetsAt <= $now) {
                $bucket->update([
                    'hits' => 1,
                    'resets_at' => $this->format($now->modify(sprintf('+%d seconds', $windowSeconds))),
                ]);

                return null;
            }

            if ($bucket->hits >= $maxAttempts) {
                return max(1, $resetsAt->getTimestamp() - $now->getTimestamp());
            }

            $bucket->increment('hits');

            return null;
        });
    }

    private function format(DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s');
    }
}
