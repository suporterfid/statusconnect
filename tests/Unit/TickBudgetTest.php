<?php

namespace Tests\Unit;

use App\Application\Scheduling\TickBudget;
use PHPUnit\Framework\TestCase;

class TickBudgetTest extends TestCase
{
    public function test_stops_claiming_when_the_wall_clock_limit_is_reached(): void
    {
        $now = 1_000.0;
        $budget = new TickBudget(
            startedAt: $now,
            limitSeconds: 2.0,
            now: static function () use (&$now): float {
                return $now;
            },
        );

        $this->assertTrue($budget->canClaimMore());

        $now = 1_002.0;

        $this->assertTrue($budget->exhausted());
        $this->assertFalse($budget->canClaimMore());
    }
}
