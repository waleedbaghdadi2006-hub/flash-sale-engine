<?php

namespace Tests\Unit\Services;

use App\Models\FlashSaleItem;
use App\Services\FlashSaleStock;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * These tests hit a real Redis instance (the `stock` connection — see
 * config/database.php) rather than a fake. FlashSaleStock's whole reason
 * for existing is the atomicity Redis's Lua execution guarantees; a mock
 * would just assert "the code calls eval()" without proving the
 * check-then-decrement is actually race-free.
 *
 * Requires a reachable Redis matching REDIS_HOST/REDIS_PORT/REDIS_STOCK_DB
 * in the environment running the suite (the app container's `.env` points
 * at the `redis` service, which is correct when run via
 * `docker compose exec app php artisan test`).
 */
class FlashSaleStockTest extends TestCase
{
    private FlashSaleStock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stock = new FlashSaleStock();
    }

    protected function tearDown(): void
    {
        // Namespaced test IDs (9xxxxx) so this never collides with real
        // data. Deleted explicitly by ID (rather than a KEYS pattern scan)
        // so cleanup doesn't depend on how the Redis client wrapper
        // does or doesn't strip the configured key prefix.
        try {
            Redis::connection('stock')->del(array_map(
                fn (int $id) => "flashsale:{$id}:stock",
                range(900001, 900008),
            ));
        } catch (\Throwable) {
            // Ignore — e.g. the Redis facade was replaced with a mock to
            // simulate an outage in the "fails closed" test below, so
            // there's nothing real to clean up.
        }

        parent::tearDown();
    }

    public function test_seed_sets_initial_value(): void
    {
        $this->stock->seed(900001, 10);

        $this->assertSame(10, (int) Redis::connection('stock')->get('flashsale:900001:stock'));
    }

    public function test_seed_does_not_overwrite_an_already_seeded_counter(): void
    {
        $this->stock->seed(900002, 10);
        $this->stock->tryReserve(900002, 3); // counter is now 7, and "live"

        $this->stock->seed(900002, 999); // a re-run / cold-cache re-seed attempt

        $this->assertSame(7, (int) Redis::connection('stock')->get('flashsale:900002:stock'));
    }

    public function test_try_reserve_decrements_atomically_and_reports_sold_out(): void
    {
        $this->stock->seed(900003, 2);

        $this->assertSame(FlashSaleStock::RESERVED, $this->stock->tryReserve(900003, 1));
        $this->assertSame(FlashSaleStock::RESERVED, $this->stock->tryReserve(900003, 1));
        $this->assertSame(FlashSaleStock::SOLD_OUT, $this->stock->tryReserve(900003, 1));
        $this->assertSame(0, (int) Redis::connection('stock')->get('flashsale:900003:stock'));
    }

    public function test_try_reserve_reports_miss_when_never_seeded(): void
    {
        $this->assertSame(FlashSaleStock::MISS, $this->stock->tryReserve(900004, 1));
    }

    public function test_release_returns_stock_to_an_existing_counter(): void
    {
        $this->stock->seed(900005, 5);
        $this->stock->tryReserve(900005, 5); // now 0 / sold out

        $this->stock->release(900005, 2);

        $this->assertSame(2, (int) Redis::connection('stock')->get('flashsale:900005:stock'));
    }

    public function test_release_is_a_no_op_when_the_counter_was_never_seeded(): void
    {
        // Guards against resurrecting a counter for an item whose
        // reservation never actually went through Redis (e.g. it was
        // served by the UNAVAILABLE fallback path) — a release() there
        // must not silently create a brand-new, wrong-value counter.
        $this->stock->release(900006, 5);

        $this->assertFalse((bool) Redis::connection('stock')->exists('flashsale:900006:stock'));
    }

    public function test_reserve_lazily_seeds_from_the_model_on_a_cold_counter(): void
    {
        $flashSaleItem = new FlashSaleItem([
            'quantity_limit' => 10,
            'quantity_sold' => 4,
        ]);
        $flashSaleItem->id = 900007;

        // Nothing seeded yet in Redis — reserve() should seed from
        // remainingStock() (10 - 4 = 6) and then succeed against that.
        $result = $this->stock->reserve($flashSaleItem, 1);

        $this->assertSame(FlashSaleStock::RESERVED, $result);
        $this->assertSame(5, (int) Redis::connection('stock')->get('flashsale:900007:stock'));
    }

    public function test_reserve_fails_closed_to_unavailable_when_redis_is_unreachable(): void
    {
        $flashSaleItem = new FlashSaleItem(['quantity_limit' => 10, 'quantity_sold' => 0]);
        $flashSaleItem->id = 900008;

        Redis::shouldReceive('connection')
            ->with('stock')
            ->andThrow(new \RedisException('Connection refused'));

        $result = $this->stock->reserve($flashSaleItem, 1);

        // Must never look like SOLD_OUT (wrongly rejects a real buyer) or
        // RESERVED (wrongly skips the DB gate) — only the distinct
        // "we don't know" sentinel is acceptable here.
        $this->assertSame(FlashSaleStock::UNAVAILABLE, $result);
    }
}
