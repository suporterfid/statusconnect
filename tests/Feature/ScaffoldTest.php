<?php

namespace Tests\Feature;

use Tests\TestCase;

class ScaffoldTest extends TestCase
{
    public function test_home_route_returns_json_status(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200)
            ->assertJson([
                'name' => 'StatusConnect',
                'status' => 'healthy',
            ]);
    }

    public function test_api_health_route_returns_ok(): void
    {
        $response = $this->get('/v1/platform/health');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
            ]);
    }
}
