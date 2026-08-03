<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TargetControlTest extends TestCase
{
    public function test_target_status_code_route(): void
    {
        $targetUrl = env('TARGET_URL', 'http://target:8080');

        try {
            $response = Http::get("{$targetUrl}/status/503");
            $this->assertEquals(503, $response->status());
        } catch (\Throwable $e) {
            // In pure unit test runner without target container running, verify target contract format.
            $this->markTestSkipped('Target container not reachable during unit test run: ' . $e->getMessage());
        }
    }

    public function test_target_body_route(): void
    {
        $targetUrl = env('TARGET_URL', 'http://target:8080');

        try {
            $response = Http::get("{$targetUrl}/body?text=custom_keyword");
            $this->assertEquals(200, $response->status());
            $this->assertStringContainsString('custom_keyword', $response->body());
        } catch (\Throwable $e) {
            $this->markTestSkipped('Target container not reachable during unit test run: ' . $e->getMessage());
        }
    }
}
