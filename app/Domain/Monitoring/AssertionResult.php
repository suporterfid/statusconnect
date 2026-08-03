<?php

namespace App\Domain\Monitoring;

final readonly class AssertionResult
{
    public function __construct(
        public bool $passed,
        public bool $isDegraded,
        public ?string $reason,
        public AssertionDefinition $assertion,
    ) {
    }

    public static function pass(AssertionDefinition $assertion): self
    {
        return new self(passed: true, isDegraded: false, reason: null, assertion: $assertion);
    }

    public static function fail(AssertionDefinition $assertion, string $reason, bool $isDegraded = false): self
    {
        return new self(passed: false, isDegraded: $isDegraded, reason: $reason, assertion: $assertion);
    }
}
