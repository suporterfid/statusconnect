<?php

namespace App\Domain\Monitoring;

use App\Domain\Shared\Clock;

final class AssertionEvaluator
{
    public function __construct(private readonly Clock $clock)
    {
    }

    /**
     * @param  list<AssertionDefinition>  $assertions
     */
    public function evaluate(CheckOutcome $outcome, array $assertions = []): EvaluationResult
    {
        if ($outcome->blockedReason !== null) {
            return new EvaluationResult(
                state: CheckState::BLOCKED,
                reason: sprintf('Outbound policy blocked request: %s', $outcome->blockedReason),
            );
        }

        if ($outcome->transportError !== null) {
            return new EvaluationResult(
                state: CheckState::DOWN,
                reason: sprintf('Transport failure: %s', $outcome->transportError),
            );
        }

        if ($assertions === []) {
            $assertions = [AssertionDefinition::defaultStatusCode()];
        }

        $results = [];
        $failedAssertion = null;
        $hasCorrectnessFailure = false;
        $hasLatencyFailure = false;

        foreach ($assertions as $assertion) {
            $result = $this->evaluateSingle($outcome, $assertion);
            $results[] = $result;

            if (! $result->passed) {
                if ($failedAssertion === null) {
                    $failedAssertion = $result;
                }

                if ($result->isDegraded) {
                    $hasLatencyFailure = true;
                } else {
                    $hasCorrectnessFailure = true;
                }
            }
        }

        if ($hasCorrectnessFailure) {
            return new EvaluationResult(
                state: CheckState::DOWN,
                reason: $failedAssertion?->reason ?? 'Assertion failed',
                failedAssertion: $failedAssertion,
                assertionResults: $results,
            );
        }

        if ($hasLatencyFailure) {
            return new EvaluationResult(
                state: CheckState::DEGRADED,
                reason: $failedAssertion?->reason ?? 'Latency threshold exceeded',
                failedAssertion: $failedAssertion,
                assertionResults: $results,
            );
        }

        return new EvaluationResult(
            state: CheckState::UP,
            assertionResults: $results,
        );
    }

    private function evaluateSingle(CheckOutcome $outcome, AssertionDefinition $assertion): AssertionResult
    {
        return match ($assertion->type) {
            AssertionType::STATUS_CODE => $this->evaluateStatusCode($outcome->statusCode, $assertion),
            AssertionType::LATENCY_MS => $this->evaluateLatency($outcome->latencyMs, $assertion),
            AssertionType::BODY_CONTAINS => $this->evaluateBodyContains($outcome->body, $assertion),
            AssertionType::BODY_MATCHES => $this->evaluateBodyMatches($outcome->body, $assertion),
            AssertionType::HEADER => $this->evaluateHeader($outcome->headers, $assertion),
            AssertionType::JSON_PATH => $this->evaluateJsonPath($outcome->body, $assertion),
            AssertionType::TLS_EXPIRES_IN_DAYS => $this->evaluateTlsExpires($outcome->tlsExpiresAt, $assertion),
        };
    }

    private function evaluateStatusCode(int $statusCode, AssertionDefinition $assertion): AssertionResult
    {
        $expected = trim((string) $assertion->expectedValue);

        $passed = match ($assertion->operator) {
            AssertionOperator::EQUALS => $statusCode === (int) $expected,
            AssertionOperator::IN => in_array($statusCode, array_map('intval', explode(',', $expected)), true),
            AssertionOperator::BETWEEN => $this->inRange($statusCode, ...$this->parseRange($expected)),
            AssertionOperator::LT => $statusCode < (int) $expected,
            AssertionOperator::GTE => $statusCode >= (int) $expected,
            default => false,
        };

        if (! $passed) {
            return AssertionResult::fail(
                $assertion,
                sprintf('Status code %d does not satisfy %s %s', $statusCode, $assertion->operator->value, $expected),
            );
        }

        return AssertionResult::pass($assertion);
    }

    private function evaluateLatency(int $latencyMs, AssertionDefinition $assertion): AssertionResult
    {
        $threshold = (int) $assertion->expectedValue;

        $passed = match ($assertion->operator) {
            AssertionOperator::LT => $latencyMs < $threshold,
            AssertionOperator::LTE => $latencyMs <= $threshold,
            default => false,
        };

        if (! $passed) {
            return AssertionResult::fail(
                $assertion,
                sprintf('Latency %dms exceeded threshold of %dms', $latencyMs, $threshold),
                isDegraded: true, // §7.6: Exceeding produces degraded, not down
            );
        }

        return AssertionResult::pass($assertion);
    }

    private function evaluateBodyContains(string $body, AssertionDefinition $assertion): AssertionResult
    {
        $expected = (string) $assertion->expectedValue;

        if ($assertion->caseSensitive) {
            $contains = str_contains($body, $expected);
        } else {
            $contains = stripos($body, $expected) !== false;
        }

        $passed = match ($assertion->operator) {
            AssertionOperator::CONTAINS => $contains,
            AssertionOperator::NOT_CONTAINS => ! $contains,
            default => false,
        };

        if (! $passed) {
            return AssertionResult::fail(
                $assertion,
                sprintf('Body check failed for substring "%s"', $expected),
            );
        }

        return AssertionResult::pass($assertion);
    }

    private function evaluateBodyMatches(string $body, AssertionDefinition $assertion): AssertionResult
    {
        $pattern = (string) $assertion->expectedValue;

        if (strlen($pattern) > 500) {
            return AssertionResult::fail($assertion, 'Regex pattern exceeds maximum length of 500 characters');
        }

        $matchResult = @preg_match($pattern, $body);

        if ($matchResult === false) {
            $lastError = preg_last_error();
            if ($lastError === PREG_BACKTRACK_LIMIT_ERROR) {
                return AssertionResult::fail($assertion, 'regex_limit_exceeded: Regex backtrack limit reached');
            }

            return AssertionResult::fail($assertion, 'Invalid regex pattern');
        }

        if ($matchResult === 0) {
            return AssertionResult::fail($assertion, sprintf('Body does not match regex pattern %s', $pattern));
        }

        return AssertionResult::pass($assertion);
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    private function evaluateHeader(array $headers, AssertionDefinition $assertion): AssertionResult
    {
        $headerName = strtolower(trim((string) $assertion->targetProperty));
        $headerValue = null;

        foreach ($headers as $name => $val) {
            if (strtolower(trim((string) $name)) === $headerName) {
                $headerValue = is_array($val) ? implode(', ', $val) : (string) $val;
                break;
            }
        }

        if ($assertion->operator === AssertionOperator::EXISTS) {
            if ($headerValue === null) {
                return AssertionResult::fail($assertion, sprintf('Header "%s" does not exist', $assertion->targetProperty));
            }

            return AssertionResult::pass($assertion);
        }

        if ($headerValue === null) {
            return AssertionResult::fail($assertion, sprintf('Header "%s" was missing', $assertion->targetProperty));
        }

        $expected = (string) $assertion->expectedValue;

        $passed = match ($assertion->operator) {
            AssertionOperator::EQUALS => $assertion->caseSensitive ? $headerValue === $expected : strcasecmp($headerValue, $expected) === 0,
            AssertionOperator::CONTAINS => $assertion->caseSensitive ? str_contains($headerValue, $expected) : stripos($headerValue, $expected) !== false,
            default => false,
        };

        if (! $passed) {
            return AssertionResult::fail(
                $assertion,
                sprintf('Header "%s" value "%s" failed check against "%s"', $assertion->targetProperty, $headerValue, $expected),
            );
        }

        return AssertionResult::pass($assertion);
    }

    private function evaluateJsonPath(string $body, AssertionDefinition $assertion): AssertionResult
    {
        $data = json_decode($body, true);

        if (! is_array($data)) {
            return AssertionResult::fail($assertion, 'Response body is not valid JSON');
        }

        $dotPath = (string) $assertion->targetProperty;
        $foundValue = $this->getDotPathValue($data, $dotPath);

        if ($assertion->operator === AssertionOperator::EXISTS) {
            if ($foundValue === null) {
                return AssertionResult::fail($assertion, sprintf('JSON path "%s" does not exist', $dotPath));
            }

            return AssertionResult::pass($assertion);
        }

        if ($foundValue === null) {
            return AssertionResult::fail($assertion, sprintf('JSON path "%s" was missing', $dotPath));
        }

        $expected = (string) $assertion->expectedValue;
        $actualString = is_scalar($foundValue) ? (string) $foundValue : json_encode($foundValue);

        $passed = match ($assertion->operator) {
            AssertionOperator::EQUALS => $assertion->caseSensitive ? $actualString === $expected : strcasecmp($actualString, $expected) === 0,
            AssertionOperator::CONTAINS => $assertion->caseSensitive ? str_contains($actualString, $expected) : stripos($actualString, $expected) !== false,
            default => false,
        };

        if (! $passed) {
            return AssertionResult::fail(
                $assertion,
                sprintf('JSON path "%s" value "%s" failed check against "%s"', $dotPath, $actualString, $expected),
            );
        }

        return AssertionResult::pass($assertion);
    }

    private function evaluateTlsExpires(?\DateTimeImmutable $tlsExpiresAt, AssertionDefinition $assertion): AssertionResult
    {
        if ($tlsExpiresAt === null) {
            return AssertionResult::fail($assertion, 'TLS expiry date was not available');
        }

        $now = $this->clock->nowUtc();
        $daysRemaining = (int) floor(($tlsExpiresAt->getTimestamp() - $now->getTimestamp()) / 86400);
        $expectedDays = (int) $assertion->expectedValue;

        if ($daysRemaining < $expectedDays) {
            return AssertionResult::fail(
                $assertion,
                sprintf('TLS certificate expires in %d days, less than minimum threshold of %d days', $daysRemaining, $expectedDays),
            );
        }

        return AssertionResult::pass($assertion);
    }

    private function getDotPathValue(array $array, string $path): mixed
    {
        if ($path === '') {
            return null;
        }

        $keys = explode('.', $path);
        $current = $array;

        foreach ($keys as $key) {
            if (! is_array($current) || ! array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseRange(string $range): array
    {
        if (str_contains($range, '..')) {
            $parts = explode('..', $range);
        } else {
            $parts = explode('-', $range);
        }

        $min = isset($parts[0]) ? (int) trim($parts[0]) : 200;
        $max = isset($parts[1]) ? (int) trim($parts[1]) : 299;

        return [$min, $max];
    }

    private function inRange(int $val, int $min, int $max): bool
    {
        return $val >= $min && $val <= $max;
    }
}
