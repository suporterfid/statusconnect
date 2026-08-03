<?php

namespace App\Domain\Monitoring;

final readonly class AssertionDefinition
{
    public function __construct(
        public AssertionType $type,
        public AssertionOperator $operator,
        public ?string $targetProperty = null,
        public ?string $expectedValue = null,
        public bool $caseSensitive = false,
        public int $order = 0,
    ) {
    }

    public static function defaultStatusCode(): self
    {
        return new self(
            type: AssertionType::STATUS_CODE,
            operator: AssertionOperator::BETWEEN,
            expectedValue: '200..299',
            order: 0,
        );
    }
}
