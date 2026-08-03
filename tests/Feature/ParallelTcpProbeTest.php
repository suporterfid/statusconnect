<?php

namespace Tests\Feature;

use App\Domain\Outbound\ValidatedEndpoint;
use App\Infrastructure\Tcp\PinnedTcpRequest;
use App\Infrastructure\Tcp\PinnedTcpProbe;
use App\Infrastructure\Tcp\StreamSelectPinnedTcpProbe;
use Tests\TestCase;

class ParallelTcpProbeTest extends TestCase
{
    public function test_binds_the_tcp_probe_interface_to_the_stream_select_implementation(): void
    {
        $this->assertInstanceOf(StreamSelectPinnedTcpProbe::class, app(PinnedTcpProbe::class));
    }

    public function test_connects_multiple_pinned_sockets_in_one_batch(): void
    {
        $ip = gethostbyname('target');
        $endpoint = new ValidatedEndpoint(
            url: 'tcp://target:8080',
            scheme: 'tcp',
            host: 'target',
            port: 8080,
            pinnedIp: $ip,
            resolvedIps: [$ip],
            hostAllowlisted: true,
        );

        $results = (new StreamSelectPinnedTcpProbe())->probe([
            new PinnedTcpRequest(1, $endpoint, 1_000),
            new PinnedTcpRequest(2, $endpoint, 1_000),
        ]);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->connected);
        $this->assertTrue($results[1]->connected);
    }

    public function test_reports_a_refused_socket_as_a_transport_error(): void
    {
        $ip = gethostbyname('target');
        $endpoint = new ValidatedEndpoint(
            url: 'tcp://target:1',
            scheme: 'tcp',
            host: 'target',
            port: 1,
            pinnedIp: $ip,
            resolvedIps: [$ip],
            hostAllowlisted: true,
        );

        $result = (new StreamSelectPinnedTcpProbe())->probe([new PinnedTcpRequest(1, $endpoint, 1_000)])[0];

        $this->assertFalse($result->connected);
        $this->assertNotNull($result->error);
    }
}
