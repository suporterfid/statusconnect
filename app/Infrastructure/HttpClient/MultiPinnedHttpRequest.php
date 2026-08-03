<?php

namespace App\Infrastructure\HttpClient;

final readonly class MultiPinnedHttpRequest
{
    public function __construct(
        public int $monitorId,
        public PinnedHttpRequest $request,
    ) {
    }
}
