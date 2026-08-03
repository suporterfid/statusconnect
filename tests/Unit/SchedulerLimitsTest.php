<?php

namespace Tests\Unit;

use App\Application\Scheduling\SchedulerLimits;
use Tests\TestCase;

class SchedulerLimitsTest extends TestCase
{
    public function test_concurrency_is_capped_at_fifty(): void
    {
        config()->set('scheduler.check_concurrency', 99);

        $this->assertSame(50, SchedulerLimits::fromConfig()->concurrency);
    }

    public function test_wave_size_defaults_to_concurrency(): void
    {
        config()->set('scheduler.check_concurrency', 7);
        config()->set('scheduler.check_wave_size', null);

        $this->assertSame(7, SchedulerLimits::fromConfig()->waveSize);
    }

    public function test_wave_size_is_never_larger_than_concurrency(): void
    {
        config()->set('scheduler.check_concurrency', 4);
        config()->set('scheduler.check_wave_size', 10);

        $this->assertSame(4, SchedulerLimits::fromConfig()->waveSize);
    }
}
