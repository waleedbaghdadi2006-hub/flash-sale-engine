<?php

namespace Tests\Feature;

use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Fires many concurrent reservation attempts — as genuinely separate OS
 * processes, each making its own real round trip to Redis — against a
 * counter seeded to fewer units than there are attempts, and asserts
 * exactly the seeded quantity succeed.
 *
 * This is the test REDIS_WORKPLAN.md Phase 7 calls out as the one that
 * actually proves no overselling: a single-process, sequential loop can't
 * rule out a race the Lua script's atomicity is specifically there to
 * prevent, because a single PHP process can never race against itself.
 *
 * Slower than a typical unit test (each attempt boots a fresh `php
 * artisan` process) and requires a reachable real Redis plus a `php`
 * binary on PATH — run it deliberately, e.g.:
 *   docker compose exec app php artisan test --filter=FlashSaleStockConcurrencyTest
 */
class FlashSaleStockConcurrencyTest extends TestCase
{
    public function test_exactly_the_seeded_quantity_wins_under_concurrent_load(): void
    {
        $flashSaleItemId = 900099; // namespaced test ID, distinct from FlashSaleStockTest's range
        $key = "flashsale:{$flashSaleItemId}:stock";
        $seeded = 25;
        $attempts = 100;

        Redis::connection('stock')->del($key);
        Redis::connection('stock')->set($key, $seeded);

        $pool = Process::pool(function (Pool $pool) use ($attempts, $flashSaleItemId) {
            for ($i = 0; $i < $attempts; $i++) {
                $pool->path(base_path())
                    ->command(['php', 'artisan', 'flash-sale:reserve-once', (string) $flashSaleItemId, '1']);
            }
        })->start();

        $results = $pool->wait();

        $wins = 0;
        $soldOut = 0;

        foreach ($results as $result) {
            $output = trim($result->output());

            match ($output) {
                '1' => $wins++,
                '0' => $soldOut++,
                default => $this->fail(
                    "Unexpected reservation result '{$output}'. stderr: " . $result->errorOutput()
                ),
            };
        }

        Redis::connection('stock')->del($key);

        $this->assertSame($seeded, $wins, 'Exactly the seeded quantity should win a reservation — no overselling.');
        $this->assertSame($attempts - $seeded, $soldOut, 'Every remaining attempt should be a clean sold-out rejection.');
    }
}
