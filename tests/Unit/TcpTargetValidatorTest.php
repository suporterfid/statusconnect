<?php

namespace Tests\Unit;

use App\Domain\Outbound\DnsResolverInterface;
use App\Domain\Outbound\IpClassifier;
use App\Domain\Outbound\OutboundPolicyViolation;
use App\Domain\Outbound\TcpTargetValidator;
use PHPUnit\Framework\TestCase;

class TcpTargetValidatorTest extends TestCase
{
    public function test_pins_a_public_host_and_allowed_port(): void
    {
        $endpoint = $this->validator()->validate('database.example:443');

        $this->assertSame('database.example', $endpoint->host);
        $this->assertSame(443, $endpoint->port);
        $this->assertSame('93.184.216.34', $endpoint->pinnedIp);
    }

    public function test_rejects_private_resolution_before_connecting(): void
    {
        $this->expectException(OutboundPolicyViolation::class);
        $this->expectExceptionMessage('private_or_reserved_ip');

        $this->validator()->validate('private.example:443');
    }

    public function test_rejects_a_port_outside_the_allowlist(): void
    {
        $this->expectException(OutboundPolicyViolation::class);
        $this->expectExceptionMessage('port_not_allowed');

        $this->validator()->validate('database.example:25');
    }

    private function validator(): TcpTargetValidator
    {
        $resolver = new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return $hostname === 'private.example' ? ['10.0.0.8'] : ['93.184.216.34'];
            }
        };

        return new TcpTargetValidator($resolver, new IpClassifier(), [443]);
    }
}
