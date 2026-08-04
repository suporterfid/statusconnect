<?php

namespace Tests\Support;

use App\Application\GrandpaSson\IntrospectionClientInterface;
use App\Application\GrandpaSson\IntrospectionResult;

final class FakeGrandpaSsonIntrospectionClient implements IntrospectionClientInterface
{
    /** @var array<string, IntrospectionResult> */
    private array $results = [];

    public function withToken(string $token, IntrospectionResult $result): self
    {
        $this->results[$token] = $result;

        return $this;
    }

    public function introspect(string $token): IntrospectionResult
    {
        return $this->results[$token] ?? new IntrospectionResult(active: false);
    }
}
