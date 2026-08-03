<?php

namespace App\Domain\Monitoring;

use DateTimeImmutable;

final readonly class CheckOutcome
{
    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public function __construct(
        public int $statusCode = 0,
        public int $latencyMs = 0,
        public array $headers = [],
        public string $body = '',
        public ?string $transportError = null,
        public ?string $blockedReason = null,
        public ?DateTimeImmutable $tlsExpiresAt = null,
    ) {
    }

    public static function blocked(string $reason): self
    {
        return new self(blockedReason: $reason);
    }

    public static function transportError(string $error, int $latencyMs = 0): self
    {
        return new self(latencyMs: $latencyMs, transportError: $error);
    }
}
