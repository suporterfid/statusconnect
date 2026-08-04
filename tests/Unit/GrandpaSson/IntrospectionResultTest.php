<?php

namespace Tests\Unit\GrandpaSson;

use App\Application\GrandpaSson\IntrospectionResult;
use PHPUnit\Framework\TestCase;

class IntrospectionResultTest extends TestCase
{
    public function test_accepts_the_raw_environment_audience(): void
    {
        $result = new IntrospectionResult(active: true, audiences: ['env_01HRAW']);

        $this->assertTrue($result->audienceIncludes('env_01HRAW'));
    }

    public function test_accepts_the_workspace_prefixed_environment_audience(): void
    {
        $result = new IntrospectionResult(active: true, audiences: ['workspace/env_01HPREFIXED']);

        $this->assertTrue($result->audienceIncludes('env_01HPREFIXED'));
    }

    public function test_rejects_an_unrelated_environment_audience(): void
    {
        $result = new IntrospectionResult(active: true, audiences: ['env_01HOTHER']);

        $this->assertFalse($result->audienceIncludes('env_01HTARGET'));
    }
}
