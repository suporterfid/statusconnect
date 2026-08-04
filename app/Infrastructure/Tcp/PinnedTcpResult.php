<?php

namespace App\Infrastructure\Tcp;

final readonly class PinnedTcpResult
{
    public function __construct(
        public int $monitorId,
        public bool $connected,
        public int $latencyMs,
        public ?string $error = null,
    ) {
    }
}
