<?php

namespace App\Domain\Shared;

use DateTimeImmutable;

final class FrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function nowUtc(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
