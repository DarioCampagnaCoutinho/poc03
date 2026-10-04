<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_all_services_are_healthy(): void
    {
        Http::fake(['*/nginx-health' => Http::response("ok\n", 200, ['Server' => 'nginx'])]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('services.app.status', 'ok')
            ->assertJsonPath('services.webserver.status', 'ok')
            ->assertJsonPath('services.database.status', 'ok')
            ->assertJsonPath('services.cache.status', 'ok');
    }

    public function test_single_service_can_be_checked(): void
    {
        $this->getJson('/api/health/database')
            ->assertOk()
            ->assertJsonPath('service', 'database')
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('driver', 'pgsql');
    }

    public function test_failing_service_returns_service_unavailable(): void
    {
        Http::fake(['*/nginx-health' => Http::response('', 502)]);

        $this->getJson('/api/health/webserver')
            ->assertServiceUnavailable()
            ->assertJsonPath('status', 'error');

        $this->getJson('/api/health')
            ->assertServiceUnavailable()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('services.webserver.status', 'error')
            ->assertJsonPath('services.database.status', 'ok');
    }

    public function test_unknown_service_returns_not_found(): void
    {
        $this->getJson('/api/health/redis')->assertNotFound();
    }
}
