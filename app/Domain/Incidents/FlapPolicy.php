<?php

namespace App\Domain\Incidents;

final class FlapPolicy
{
    public function evaluate(int $recentCycles, int $threshold): bool
    {
        return $recentCycles > $threshold;
    }
}
