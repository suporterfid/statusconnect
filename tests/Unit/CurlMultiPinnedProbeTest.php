<?php

namespace Tests\Unit;

use App\Domain\Outbound\ValidatedEndpoint;
use App\Infrastructure\HttpClient\CurlMultiPinnedProbe;
use App\Infrastructure\HttpClient\PinnedHttpRequest;
use Tests\TestCase;

class CurlMultiPinnedProbeTest extends TestCase
{
    public function test_builds_a_distinct_resolve_entry_for_each_validated_endpoint(): void
    {
        $first = CurlMultiPinnedProbe::optionsFor($this->requestFor('example.test', 443, '203.0.113.8'));
        $second = CurlMultiPinnedProbe::optionsFor($this->requestFor('example.test', 443, '203.0.113.9'));

        $this->assertSame(['example.test:443:203.0.113.8'], $first[CURLOPT_RESOLVE]);
        $this->assertSame(['example.test:443:203.0.113.9'], $second[CURLOPT_RESOLVE]);
        $this->assertFalse($first[CURLOPT_FOLLOWLOCATION]);
        $this->assertFalse($second[CURLOPT_FOLLOWLOCATION]);
    }

    private function requestFor(string $host, int $port, string $ip): PinnedHttpRequest
    {
        return new PinnedHttpRequest(
            method: 'GET',
            endpoint: new ValidatedEndpoint(
                url: "https://{$host}/health",
                scheme: 'https',
                host: $host,
                port: $port,
                pinnedIp: $ip,
                resolvedIps: [$ip],
                hostAllowlisted: false,
            ),
        );
    }
}
