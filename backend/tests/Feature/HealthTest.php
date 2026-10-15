<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_reports_all_services_up(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.redis.ok', true)
            ->assertJsonPath('checks.storage.ok', true)
            ->assertJsonPath('app.timezone', 'Asia/Damascus');
    }

    public function test_api_errors_are_json(): void
    {
        $this->get('/api/does-not-exist')
            ->assertNotFound()
            ->assertHeader('content-type', 'application/json');
    }
}
