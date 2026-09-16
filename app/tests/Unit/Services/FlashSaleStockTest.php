<?php

namespace Tests\Unit\Services;

use App\Events\StockUpdated;
use App\Models\FlashSaleItem;
use App\Services\FlashSaleStock;
use Illuminate\Support\Facades\Event;
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
            $ids = range(900001, 900015);

            Redis::connection('stock')->del([
                ...array_map(fn (int $id) => "flashsale:{$id}:stock", $ids),
                ...array_map(fn (int $id) => "flashsale:{$id}:broadcast_throttle", $ids),
            ]);
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

    public function test_broadcast_updated_does_nothing_when_broadcasting_is_disabled(): void
    {
        config(['flash_sale.stock_broadcast_enabled' => false]);

        Event::fake();

        $flashSaleItem = new FlashSaleItem(['flash_sale_id' => 1, 'quantity_limit' => 5, 'quantity_sold' => 0]);
        $flashSaleItem->id = 900011;

        $this->stock->broadcastUpdated($flashSaleItem, 'reserved');

        Event::assertNotDispatched(StockUpdated::class);
    }

    public function test_broadcast_updated_throttles_reserved_events_but_not_released_events(): void
    {
        config(['flash_sale.stock_broadcast_enabled' => true, 'flash_sale.stock_broadcast_every' => 3]);

        Event::fake();

        $flashSaleItem = new FlashSaleItem(['flash_sale_id' => 1, 'quantity_limit' => 10, 'quantity_sold' => 0]);
        $flashSaleItem->id = 900012;

        // 1st and 2nd 'reserved' calls are swallowed by the throttle...
        $this->stock->broadcastUpdated($flashSaleItem, 'reserved');
        $this->stock->broadcastUpdated($flashSaleItem, 'reserved');
        Event::assertNotDispatched(StockUpdated::class);

        // ...the 3rd fires.
        $this->stock->broadcastUpdated($flashSaleItem, 'reserved');
        Event::assertDispatchedTimes(StockUpdated::class, 1);

        // A 'released' correction is never throttled, regardless of count.
        $this->stock->broadcastUpdated($flashSaleItem, 'released');
        Event::assertDispatchedTimes(StockUpdated::class, 2);
    }

    public function test_broadcast_updated_reads_remaining_stock_from_redis_when_available(): void
    {
        config(['flash_sale.stock_broadcast_enabled' => true, 'flash_sale.stock_broadcast_every' => 1]);

        Event::fake();

        $this->stock->seed(900013, 7);

        // Deliberately does not match the model's own remainingStock()
        // (10 - 8 = 2) — this is what actually proves the payload reads
        // the live Redis counter rather than silently falling back to a
        // stale in-memory model value.
        $flashSaleItem = new FlashSaleItem(['flash_sale_id' => 9, 'quantity_limit' => 10, 'quantity_sold' => 8]);
        $flashSaleItem->id = 900013;

        $this->stock->broadcastUpdated($flashSaleItem, 'reserved');

        Event::assertDispatched(
            StockUpdated::class,
            fn ($event) => $event->flashSaleId === 9
                && $event->flashSaleItemId === 900013
                && $event->remainingStock === 7 // from Redis (seeded value), not the model's remainingStock() of 2
                && $event->reason === 'reserved',
        );
    }

    public function test_broadcast_updated_falls_back_to_the_models_remaining_stock_when_redis_has_nothing(): void
    {
        config(['flash_sale.stock_broadcast_enabled' => true, 'flash_sale.stock_broadcast_every' => 1]);

        Event::fake();

        // Nothing seeded for 900014 — e.g. a cancellation's 'released'
        // correction on a sale that never used the Redis gatekeeper.
        $flashSaleItem = new FlashSaleItem(['flash_sale_id' => 9, 'quantity_limit' => 10, 'quantity_sold' => 6]);
        $flashSaleItem->id = 900014;

        $this->stock->broadcastUpdated($flashSaleItem, 'released');

        Event::assertDispatched(
            StockUpdated::class,
            fn ($event) => $event->remainingStock === 4 // 10 - 6, from the model, not Redis
                && $event->reason === 'released',
        );
    }

    public function test_broadcast_updated_never_throws_when_redis_is_unreachable(): void
    {
        config(['flash_sale.stock_broadcast_enabled' => true]);

        Redis::shouldReceive('connection')
            ->with('stock')
            ->andThrow(new \RedisException('Connection refused'));

        $flashSaleItem = new FlashSaleItem(['flash_sale_id' => 1, 'quantity_limit' => 5, 'quantity_sold' => 0]);
        $flashSaleItem->id = 900015;

        // Must not throw — a broadcast/Redis outage can never surface as a
        // purchase-blocking exception.
        $this->stock->broadcastUpdated($flashSaleItem, 'reserved');

        $this->addToAssertionCount(1);
    }

    public function test_forget_deletes_the_stock_and_broadcast_throttle_keys(): void
    {
        $this->stock->seed(900009, 5);
        $this->stock->tryReserve(900009, 1); // also creates the broadcast-throttle-adjacent state below

        config(['flash_sale.stock_broadcast_enabled' => true, 'flash_sale.stock_broadcast_every' => 1]);

        $flashSaleItem = new FlashSaleItem(['flash_sale_id' => 1, 'quantity_limit' => 5, 'quantity_sold' => 1]);
        $flashSaleItem->id = 900009;
        $this->stock->broadcastUpdated($flashSaleItem, 'reserved'); // populates the throttle counter key

        $this->stock->forget(900009);

        $this->assertFalse((bool) Redis::connection('stock')->exists('flashsale:900009:stock'));
        $this->assertFalse((bool) Redis::connection('stock')->exists('flashsale:900009:broadcast_throttle'));
    }

    public function test_forget_is_a_no_op_when_nothing_was_ever_seeded(): void
    {
        // Sweeping an ended sale whose items never actually got a Redis
        // counter (disabled, or never purchased) must not error.
        $this->stock->forget(900010);

        $this->assertFalse((bool) Redis::connection('stock')->exists('flashsale:900010:stock'));
    }
}
