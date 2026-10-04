<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class HealthCheckController extends Controller
{
    /**
     * Verifica se PHP, Nginx e PostgreSQL estão funcionando.
     */
    public function __invoke(): JsonResponse
    {
        $services = [
            // Se esta linha executou, o PHP-FPM está respondendo.
            'php' => 'ok',
            // Endpoint respondido pelo próprio Nginx, sem passar pelo PHP.
            'nginx' => $this->check(fn () => Http::timeout(3)->get(config('services.nginx.url').'/nginx-health')->throw()),
            'postgresql' => $this->check(fn () => DB::select('select 1')),
        ];

        $healthy = ! in_array('error', $services, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'error',
            'services' => $services,
        ], $healthy ? 200 : 503);
    }

    /**
     * Executa a verificação e retorna "ok" ou "error". A exceção é registrada no log.
     */
    private function check(callable $callback): string
    {
        return rescue(function () use ($callback) {
            $callback();

            return 'ok';
        }, 'error');
    }
}
