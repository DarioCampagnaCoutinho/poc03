<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class HealthCheckService
{
    /**
     * Serviços que podem ser verificados.
     */
    public const SERVICES = ['app', 'webserver', 'database', 'cache'];

    /**
     * Verifica todos os serviços.
     *
     * @return array<string, array<string, mixed>>
     */
    public function checkAll(): array
    {
        return collect(self::SERVICES)
            ->mapWithKeys(fn (string $service) => [$service => $this->check($service)])
            ->all();
    }

    /**
     * Verifica um serviço e mede o tempo de resposta.
     *
     * @return array<string, mixed>
     */
    public function check(string $service): array
    {
        $start = hrtime(true);

        try {
            $details = match ($service) {
                'app' => $this->checkApp(),
                'webserver' => $this->checkWebserver(),
                'database' => $this->checkDatabase(),
                'cache' => $this->checkCache(),
            };

            return ['status' => 'ok', ...$details, 'latency_ms' => $this->elapsedMs($start)];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'error' => config('app.debug') ? $e->getMessage() : 'Service unavailable.',
                'latency_ms' => $this->elapsedMs($start),
            ];
        }
    }

    /**
     * PHP-FPM / Laravel: se este código está rodando, o container da aplicação está de pé.
     *
     * @return array<string, mixed>
     */
    private function checkApp(): array
    {
        return [
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'environment' => app()->environment(),
        ];
    }

    /**
     * Nginx: requisição ao endpoint /nginx-health, respondido pelo próprio Nginx sem passar pelo PHP.
     *
     * @return array<string, mixed>
     */
    private function checkWebserver(): array
    {
        $response = Http::timeout(3)
            ->get(config('services.nginx.url').'/nginx-health')
            ->throw();

        return ['server' => $response->header('Server')];
    }

    /**
     * PostgreSQL: abre a conexão e executa uma query.
     *
     * @return array<string, mixed>
     */
    private function checkDatabase(): array
    {
        $connection = DB::connection();
        $connection->select('select 1');

        return [
            'driver' => $connection->getDriverName(),
            'database' => $connection->getDatabaseName(),
            'version' => $connection->getServerVersion(),
        ];
    }

    /**
     * Cache: grava, lê e remove uma chave no store padrão (tabela "cache" no PostgreSQL).
     *
     * @return array<string, mixed>
     */
    private function checkCache(): array
    {
        $key = 'health-check:'.Str::uuid();

        Cache::put($key, 'ok', 10);

        if (Cache::pull($key) !== 'ok') {
            throw new RuntimeException('Cache read/write mismatch.');
        }

        return ['store' => config('cache.default')];
    }

    private function elapsedMs(int $start): float
    {
        return round((hrtime(true) - $start) / 1_000_000, 2);
    }
}
