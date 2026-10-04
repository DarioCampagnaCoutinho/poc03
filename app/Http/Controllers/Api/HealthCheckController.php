<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;

class HealthCheckController extends Controller
{
    public function __construct(private HealthCheckService $health) {}

    /**
     * Status de todos os serviços.
     */
    public function index(): JsonResponse
    {
        $services = $this->health->checkAll();
        $healthy = collect($services)->every(fn (array $result) => $result['status'] === 'ok');

        return response()->json([
            'status' => $healthy ? 'ok' : 'error',
            'services' => $services,
        ], $healthy ? 200 : 503);
    }

    /**
     * Status de um serviço específico.
     */
    public function show(string $service): JsonResponse
    {
        $result = $this->health->check($service);

        return response()->json(
            ['service' => $service, ...$result],
            $result['status'] === 'ok' ? 200 : 503,
        );
    }
}
