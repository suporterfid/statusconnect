<?php

namespace App\Domain\Monitoring;

final readonly class EvaluationResult
{
    /**
     * @param  list<AssertionResult>  $assertionResults
     */
    public function __construct(
        public CheckState $state,
        public ?string $reason = null,
        public ?AssertionResult $failedAssertion = null,
        public array $assertionResults = [],
    ) {
    }
}
