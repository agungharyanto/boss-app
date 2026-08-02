<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    public function index()
    {
        $checks = [
            'app' => true,
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
        ];

        $healthy = ! in_array(false, $checks, true);

        return response()->json([
            'success' => $healthy,
            'message' => $healthy ? 'BOSS App healthy' : 'BOSS App degraded',
            'data' => $checks,
            'meta' => [
                'version' => 'v0.1.0-foundation',
                'timestamp' => now()->toIso8601String(),
            ],
        ], $healthy ? 200 : 503);
    }

    private function checkDatabase(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function checkRedis(): bool
    {
        try {
            Redis::ping();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
