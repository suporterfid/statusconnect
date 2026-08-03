<?php

namespace Tests\Unit;

use App\Domain\Outbound\DnsResolverInterface;
use App\Domain\Outbound\EgressProfile;
use App\Domain\Outbound\OutboundPolicy;
use App\Domain\Outbound\OutboundPolicyConfig;
use App\Domain\Outbound\OutboundPolicyViolation;
use PHPUnit\Framework\TestCase;

class OutboundPolicyTest extends TestCase
{
    private OutboundPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $config = OutboundPolicyConfig::fromArray([
            'allowed_ports' => [80, 443, 8095],
            'allow_http' => true,
            'user_agent' => 'StatusConnect/1.0',
            'metadata_hosts' => ['metadata.google.internal', 'metadata.goog'],
            'metadata_ips' => ['169.254.169.254', '100.100.100.200'],
            'testing_allow_hosts' => ['target'],
            'profiles' => [
                'public-crawl' => [],
            ],
        ]);

        $mockResolver = new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return match ($hostname) {
                    'loopback.test' => ['127.0.0.1'],
                    'private.test' => ['10.0.0.1'],
                    'metadata.test' => ['169.254.169.254'],
                    'ipv6-linklocal.test' => ['fe80::1'],
                    'example.com' => ['93.184.216.34'],
                    default => ['127.0.0.1'],
                };
            }
        };

        $this->policy = OutboundPolicy::fromConfig($config, $mockResolver);
    }

    public function test_rejects_localhost_and_loopback(): void
    {
        $this->expectException(OutboundPolicyViolation::class);
        $this->policy->validateUrl('http://localhost/health', [], EgressProfile::PublicCrawl);
    }

    public function test_rejects_loopback_ip_resolutions(): void
    {
        $this->expectException(OutboundPolicyViolation::class);
        $this->policy->validateUrl('http://loopback.test/health', [], EgressProfile::PublicCrawl);
    }

    public function test_rejects_rfc1918_private_ips(): void
    {
        $this->expectException(OutboundPolicyViolation::class);
        $this->policy->validateUrl('http://private.test/status', [], EgressProfile::PublicCrawl);
    }

    public function test_rejects_cloud_metadata_ip(): void
    {
        $this->expectException(OutboundPolicyViolation::class);
        $this->policy->validateUrl('http://metadata.test/computeMetadata/v1/', [], EgressProfile::PublicCrawl);
    }

    public function test_rejects_cloud_metadata_hostname(): void
    {
        $this->expectException(OutboundPolicyViolation::class);
        $this->policy->validateUrl('http://metadata.google.internal/computeMetadata/v1/', [], EgressProfile::PublicCrawl);
    }

    public function test_rejects_ipv6_link_local(): void
    {
        $this->expectException(OutboundPolicyViolation::class);
        $this->policy->validateUrl('http://ipv6-linklocal.test/status', [], EgressProfile::PublicCrawl);
    }

    public function test_allows_valid_public_url(): void
    {
        $endpoint = $this->policy->validateUrl('https://example.com/status', [], EgressProfile::PublicCrawl);

        $this->assertEquals('example.com', $endpoint->host);
        $this->assertEquals('93.184.216.34', $endpoint->pinnedIp);
        $this->assertEquals(443, $endpoint->port);
    }

    public function test_allows_testing_allowlisted_host(): void
    {
        $endpoint = $this->policy->validateUrl('http://target:8095/status/200', [], EgressProfile::PublicCrawl);

        $this->assertEquals('target', $endpoint->host);
        $this->assertTrue($endpoint->hostAllowlisted);
    }
}
