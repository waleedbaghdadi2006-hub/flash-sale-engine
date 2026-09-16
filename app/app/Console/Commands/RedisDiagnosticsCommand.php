<?php

namespace App\Console\Commands;

use App\Services\RedisDiagnostics;
use Illuminate\Console\Command;

/**
 * Ops-facing snapshot of Redis health — the same metrics
 * REDIS_WORKPLAN.md Phase 6 says to watch (memory vs maxmemory,
 * connected clients, ops/sec, hit/miss rate, rejected connections),
 * plus a key count per logical DB. Useful during a game-day load test or
 * mid-incident without needing dashboard access. See also
 * `GET /admin/redis/health` for the same data over HTTP.
 */
class RedisDiagnosticsCommand extends Command
{
    protected $signature = 'redis:diagnostics {--json : Output raw JSON instead of a formatted summary}';

    protected $description = 'Print a Redis health snapshot: memory, clients, ops/sec, hit rate, and per-DB key counts.';

    public function handle(RedisDiagnostics $diagnostics): int
    {
        $summary = $diagnostics->summary();

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $summary['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
        }

        if ($summary['status'] !== 'ok') {
            $this->error('Redis is unavailable: ' . ($summary['error'] ?? 'unknown error'));

            return self::FAILURE;
        }

        $this->info("Redis {$summary['redis_version']} — up {$summary['uptime_seconds']}s");

        $this->table(['Metric', 'Value'], [
            ['Memory used', $summary['memory']['used_human'] ?? $summary['memory']['used_bytes']],
            ['Maxmemory', $summary['memory']['maxmemory_bytes'] > 0 ? $summary['memory']['maxmemory_bytes'] . ' bytes' : 'unlimited'],
            ['Maxmemory policy', $summary['memory']['maxmemory_policy'] ?? 'unknown'],
            ['Used % of max', $summary['memory']['used_pct_of_max'] !== null ? $summary['memory']['used_pct_of_max'] . '%' : 'n/a'],
            ['Connected clients', $summary['clients']['connected']],
            ['Rejected connections', $summary['clients']['rejected_connections']],
            ['Ops/sec', $summary['ops']['instantaneous_ops_per_sec']],
            ['Keyspace hit rate', $summary['ops']['hit_rate_pct'] !== null ? $summary['ops']['hit_rate_pct'] . '%' : 'n/a'],
        ]);

        $rows = [];
        foreach ($summary['logical_databases'] as $label => $db) {
            $rows[] = [
                $label,
                $db['connection'] ?? 'n/a',
                $db['database'] ?? 'n/a',
                $db['keys'] ?? ('error: ' . ($db['error'] ?? 'unknown')),
            ];
        }
        $this->table(['Logical DB', 'Connection', 'DB index', 'Keys'], $rows);

        return self::SUCCESS;
    }
}
