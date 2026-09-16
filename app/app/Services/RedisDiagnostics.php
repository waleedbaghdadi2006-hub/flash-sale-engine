<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Read-only Redis health snapshot for the metrics REDIS_WORKPLAN.md
 * Phase 6 calls out as worth watching: memory vs maxmemory, connected
 * clients, ops/sec, hit/miss rate, and rejected connections — plus a
 * per-logical-DB key count so an incident responder can immediately see
 * whether the pressure is coming from cache (DB 1, safe to evict/flush),
 * the queue (DB 0), or live flash-sale stock counters (DB 2).
 *
 * Backs both `php artisan redis:diagnostics` and the admin-gated
 * `GET /admin/redis/health` endpoint. Never throws: any failure to reach
 * Redis is reported as a `status: unavailable` payload rather than an
 * exception, since this is a diagnostics tool that should still render
 * something useful (or at least honest) when Redis itself is the problem.
 */
class RedisDiagnostics
{
    /**
     * Logical connections defined in config/database.php's `redis` array,
     * mapped to a human label. See REDIS_WORKPLAN.md Phase 1 / Phase 6 for
     * why these are split.
     */
    private const CONNECTIONS = [
        'default' => 'queue_and_general',
        'cache' => 'cache',
        'stock' => 'flash_sale_stock',
    ];

    public function summary(): array
    {
        try {
            $info = (array) Redis::connection('default')->info();
        } catch (Throwable $e) {
            return [
                'status' => 'unavailable',
                'error' => $e->getMessage(),
            ];
        }

        $usedMemory = (int) ($info['used_memory'] ?? 0);
        $maxMemory = (int) ($info['maxmemory'] ?? 0);
        $hits = (int) ($info['keyspace_hits'] ?? 0);
        $misses = (int) ($info['keyspace_misses'] ?? 0);
        $total = $hits + $misses;

        return [
            'status' => 'ok',
            'checked_at' => now()->toIso8601String(),
            'redis_version' => $info['redis_version'] ?? null,
            'uptime_seconds' => isset($info['uptime_in_seconds']) ? (int) $info['uptime_in_seconds'] : null,
            'memory' => [
                'used_bytes' => $usedMemory,
                'used_human' => $info['used_memory_human'] ?? null,
                'maxmemory_bytes' => $maxMemory,
                'maxmemory_policy' => $info['maxmemory_policy'] ?? null,
                'used_pct_of_max' => $maxMemory > 0 ? round(($usedMemory / $maxMemory) * 100, 1) : null,
            ],
            'clients' => [
                'connected' => isset($info['connected_clients']) ? (int) $info['connected_clients'] : null,
                'rejected_connections' => isset($info['rejected_connections']) ? (int) $info['rejected_connections'] : null,
            ],
            'ops' => [
                'instantaneous_ops_per_sec' => isset($info['instantaneous_ops_per_sec']) ? (int) $info['instantaneous_ops_per_sec'] : null,
                'keyspace_hits' => $hits,
                'keyspace_misses' => $misses,
                'hit_rate_pct' => $total > 0 ? round(($hits / $total) * 100, 1) : null,
            ],
            'logical_databases' => $this->keyCounts(),
        ];
    }

    /**
     * Per-logical-DB key count via DBSIZE on each named connection,
     * rather than parsing INFO's keyspace section — simpler, and it
     * reports on exactly the three connections the app actually uses
     * instead of whatever DB indices happen to exist on the server.
     */
    private function keyCounts(): array
    {
        $counts = [];

        foreach (self::CONNECTIONS as $connection => $label) {
            try {
                $counts[$label] = [
                    'connection' => $connection,
                    'database' => (int) config("database.redis.{$connection}.database"),
                    'keys' => (int) Redis::connection($connection)->dbSize(),
                ];
            } catch (Throwable $e) {
                $counts[$label] = [
                    'connection' => $connection,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $counts;
    }
}
