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

    public function test_brackets_ipv6_literal_hosts_and_pinned_ips_in_resolve_entries(): void
    {
        $options = CurlMultiPinnedProbe::optionsFor($this->requestFor('2001:db9::1', 443, '2001:db9::1'));

        $this->assertSame(['[2001:db9::1]:443:[2001:db9::1]'], $options[CURLOPT_RESOLVE]);
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
