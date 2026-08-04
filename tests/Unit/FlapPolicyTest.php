<?php

namespace Tests\Unit;

use App\Domain\Incidents\FlapPolicy;
use PHPUnit\Framework\TestCase;

class FlapPolicyTest extends TestCase
{
    public function test_more_than_five_resolved_cycles_marks_a_monitor_flapping(): void
    {
        $policy = new FlapPolicy();

        $this->assertFalse($policy->evaluate(recentCycles: 5, threshold: 5));
        $this->assertTrue($policy->evaluate(recentCycles: 6, threshold: 5));
    }
}
