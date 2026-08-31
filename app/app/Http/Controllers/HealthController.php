<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
class HealthController extends Controller
{
    public function index()
    {
        // 1. Check Database Connection
        try {
            DB::connection()->getPdo();
            $databaseStatus = 'up';
        } catch (\Exception $e) {
            $databaseStatus = 'down';
        }

        // 2. Check Redis Connection
        try {
            Redis::connection()->ping();
            $redisStatus = 'up';
        } catch (\Exception $e) {
            $redisStatus = 'down';
        }

        // 3. Determine Overall Status
        $isHealthy = ($databaseStatus === 'up' && $redisStatus === 'up');

        // 4. Return actual live results
        return response()->json([
            'status' => $isHealthy ? 'healthy' : 'unhealthy',
            'timestamp' => now()->toIso8601String(),
            'services' => [
                'database' => $databaseStatus,
                'redis' => $redisStatus,
            ]
        ], $isHealthy ? 200 : 500); // Returns HTTP 500 if anything is broken
    }
}
