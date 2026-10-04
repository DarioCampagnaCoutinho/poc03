<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Exceptions::fake();
    }

    public function test_all_services_are_healthy(): void
    {
        Http::fake(['*/nginx-health' => Http::response("ok\n")]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'services' => ['php' => 'ok', 'nginx' => 'ok', 'postgresql' => 'ok'],
            ]);

        Exceptions::assertNothingReported();
    }

    public function test_nginx_failure_returns_service_unavailable(): void
    {
        Http::fake(['*/nginx-health' => Http::response('', 502)]);

        $this->getJson('/api/health')
            ->assertServiceUnavailable()
            ->assertExactJson([
                'status' => 'error',
                'services' => ['php' => 'ok', 'nginx' => 'error', 'postgresql' => 'ok'],
            ]);

        Exceptions::assertReported(RequestException::class);
    }

    public function test_postgresql_failure_returns_service_unavailable(): void
    {
        Http::fake(['*/nginx-health' => Http::response("ok\n")]);

        // Porta sem nenhum serviço escutando: a conexão é recusada.
        config(['database.connections.pgsql.port' => 1]);
        DB::purge('pgsql');

        $this->getJson('/api/health')
            ->assertServiceUnavailable()
            ->assertExactJson([
                'status' => 'error',
                'services' => ['php' => 'ok', 'nginx' => 'ok', 'postgresql' => 'error'],
            ]);

        Exceptions::assertReported(QueryException::class);
    }
}
