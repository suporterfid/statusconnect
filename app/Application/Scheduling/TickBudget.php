<?php

namespace App\Application\Scheduling;

/**
 * Mirrors taskconnect app/Application/Scheduling/TickBudget.php.
 */
final class TickBudget
{
    /**
     * @param  (callable(): float)|null  $now
     */
    public function __construct(
        private readonly float $startedAt,
        private readonly float $limitSeconds,
        private readonly ?\Closure $now = null,
    ) {
    }

    public static function fromConfig(?\Closure $now = null): self
    {
        $target = max(1.0, (float) config('scheduler.target_duration_seconds', 45));
        $margin = max(0.0, (float) config('scheduler.budget_safety_margin_seconds', 5));
        $phpMaxExecutionTime = (int) ini_get('max_execution_time');
        $limit = $phpMaxExecutionTime > 0
            ? min($target, max(1.0, $phpMaxExecutionTime - $margin))
            : $target;
        $clock = $now ?? static fn (): float => microtime(true);

        return new self($clock(), $limit, $now);
    }

    public function canClaimMore(): bool
    {
        return $this->elapsedSeconds() < $this->limitSeconds;
    }

    public function exhausted(): bool
    {
        return ! $this->canClaimMore();
    }

    public function elapsedSeconds(): float
    {
        $now = ($this->now ?? static fn (): float => microtime(true))();

        return max(0.0, $now - $this->startedAt);
    }
}
