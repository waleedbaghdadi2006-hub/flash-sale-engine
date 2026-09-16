<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\RedisDiagnostics;
use Illuminate\Http\JsonResponse;

/**
 * Admin-only Redis monitoring snapshot (REDIS_WORKPLAN.md Phase 6):
 * memory vs maxmemory, connected clients, ops/sec, hit/miss rate,
 * rejected connections, and a per-logical-DB key count. Sits behind the
 * same `role:admin` middleware as the coupon admin routes — see
 * routes/api.php.
 *
 * Deliberately unauthenticated-against-Redis-being-down: if Redis itself
 * is unreachable this still returns a 200 with `status: unavailable`
 * rather than a 500, since "Redis is down" is exactly the situation an
 * operator is hitting this endpoint to confirm.
 */
class RedisHealthController extends Controller
{
    public function __invoke(RedisDiagnostics $diagnostics): JsonResponse
    {
        return response()->json($diagnostics->summary());
    }
}
