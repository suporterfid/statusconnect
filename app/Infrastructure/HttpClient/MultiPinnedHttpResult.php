<?php

namespace App\Infrastructure\HttpClient;

final readonly class MultiPinnedHttpResult
{
    public function __construct(
        public int $monitorId,
        public PinnedHttpResponse $response,
        public int $latencyMs,
    ) {
    }
}
