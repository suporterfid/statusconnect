<?php

namespace App\Application\Scheduling;

final readonly class SchedulerLimits
{
    public function __construct(
        public int $concurrency,
        public int $waveSize,
    ) {
    }

    public static function fromConfig(): self
    {
        $concurrency = min(50, max(1, (int) config('scheduler.check_concurrency', 10)));
        $configuredWaveSize = config('scheduler.check_wave_size');
        $waveSize = min($concurrency, max(1, (int) ($configuredWaveSize ?? $concurrency)));

        return new self($concurrency, $waveSize);
    }
}
