<?php

namespace Tests\Feature;

use App\Domain\Outbound\OutboundPolicy;
use App\Infrastructure\HttpClient\CurlMultiPinnedProbe;
use App\Infrastructure\HttpClient\MultiPinnedHttpProbe;
use App\Infrastructure\HttpClient\MultiPinnedHttpRequest;
use App\Infrastructure\HttpClient\PinnedHttpRequest;
use Tests\TestCase;

class ParallelHttpProbeTest extends TestCase
{
    public function test_binds_the_multi_probe_interface_to_the_pinned_implementation(): void
    {
        $this->assertInstanceOf(CurlMultiPinnedProbe::class, app(MultiPinnedHttpProbe::class));
    }

    public function test_thirty_five_second_targets_complete_concurrently(): void
    {
        $policy = app(OutboundPolicy::class);
        $target = rtrim((string) env('TARGET_URL', 'http://target:8080'), '/');
        $requests = [];

        for ($monitorId = 1; $monitorId <= 30; $monitorId++) {
            $requests[] = new MultiPinnedHttpRequest(
                $monitorId,
                new PinnedHttpRequest(
                    method: 'GET',
                    endpoint: $policy->validateUrl("{$target}/delay/5000", ['production']),
                    totalTimeout: 10,
                ),
            );
        }

        $startedAt = hrtime(true);
        $results = (new CurlMultiPinnedProbe($policy))->probe($requests);
        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

        $this->assertCount(30, $results);
        $this->assertLessThan(9_000, $elapsedMs);
        foreach ($results as $result) {
            $this->assertSame(200, $result->response->statusCode);
        }
    }

    public function test_redirect_hops_are_followed_without_curl_automatic_redirects(): void
    {
        $policy = app(OutboundPolicy::class);
        $target = rtrim((string) env('TARGET_URL', 'http://target:8080'), '/');
        $request = new MultiPinnedHttpRequest(
            1,
            new PinnedHttpRequest(
                method: 'GET',
                endpoint: $policy->validateUrl("{$target}/redirect/3", ['production']),
                totalTimeout: 10,
            ),
        );

        $result = (new CurlMultiPinnedProbe($policy))->probe([$request])[0];

        $this->assertSame(200, $result->response->statusCode);
        $this->assertSame(3, $result->response->redirectCount);
        $this->assertStringEndsWith('/status/200', $result->response->finalUrl);
    }

    public function test_redirect_limit_returns_the_last_bounded_response(): void
    {
        config()->set('outbound.redirect_limit', 1);
        $policy = app(OutboundPolicy::class);
        $target = rtrim((string) env('TARGET_URL', 'http://target:8080'), '/');
        $request = new MultiPinnedHttpRequest(
            1,
            new PinnedHttpRequest(
                method: 'GET',
                endpoint: $policy->validateUrl("{$target}/redirect/3", ['production']),
                totalTimeout: 10,
            ),
        );

        $results = (new CurlMultiPinnedProbe($policy))->probe([$request]);

        $this->assertCount(1, $results);
        $this->assertSame(302, $results[0]->response->statusCode);
        $this->assertSame(1, $results[0]->response->redirectCount);
    }
}
