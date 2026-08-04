<?php

namespace App\Application\Api;

final readonly class IdempotencyResponse
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public int $statusCode,
        public array $body,
    ) {
    }
}
