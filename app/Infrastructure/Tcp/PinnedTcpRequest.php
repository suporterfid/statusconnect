<?php

namespace App\Infrastructure\Tcp;

use App\Domain\Outbound\ValidatedEndpoint;

final readonly class PinnedTcpRequest
{
    public function __construct(
        public int $monitorId,
        public ValidatedEndpoint $endpoint,
        public int $timeoutMs,
    ) {
    }
}
