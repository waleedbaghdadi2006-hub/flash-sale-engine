<?php

namespace Tests\Feature;

use App\Models\FlashSale;
use App\Models\FlashSaleItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * `flash-sale:cleanup-stock` reads and writes real `flash_sales` /
 * `flash_sale_items` / `products` rows, so it needs actual migrated
 * tables. This project's migrations run raw `ALTER TABLE ... ADD
 * CONSTRAINT ... CHECK (...)` statements (see e.g.
 * database/migrations/*_create_products_table.php) — MySQL-only DDL
 * syntax that SQLite's grammar can't execute, so they can't run against
 * the in-memory sqlite connection phpunit.xml defaults to.
 *
 * Run this suite against the real stack instead of the default
 * `php artisan test`:
 *   docker compose exec app php artisan test --filter=CleanupFlashSaleStockCommandTest
 *
 * On any other DB connection this test skips itself with an explanatory
 * message rather than failing, so the default (sqlite) test run stays
 * green and CI doesn't need a MySQL service just to boot the suite.
 */
class CleanupFlashSaleStockCommandTest extends TestCase
{
    use RefreshDatabase;

    private ?int $itemAId = null;
    private ?int $itemBId = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'flash-sale:cleanup-stock reads real flash_sales/flash_sale_items tables, and this '
                . "project's migrations require MySQL (raw CHECK-constraint DDL). Run via "
                . '`docker compose exec app php artisan test`.'
            );
        }
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->itemAId, $this->itemBId)) {
                Redis::connection('stock')->del([
                    "flashsale:{$this->itemAId}:stock",
                    "flashsale:{$this->itemAId}:broadcast_throttle",
                    "flashsale:{$this->itemBId}:stock",
                    "flashsale:{$this->itemBId}:broadcast_throttle",
                ]);
            }
        } catch (\Throwable) {
            //
        }

        parent::tearDown();
    }

    public function test_it_forgets_redis_counters_and_marks_ended_sales_as_ended(): void
    {
        $product = Product::create([
            'name' => 'Cleanup Test Product',
            'slug' => 'cleanup-test-product-' . uniqid(),
            'base_price' => 10,
            'sku' => 'CLEANUP-' . uniqid(),
            'is_active' => true,
        ]);

        $endedSale = FlashSale::create([
            'title' => 'Ended sale',
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
            'status' => FlashSale::STATUS_ACTIVE, // status column is stale on purpose
        ]);

        $stillActiveSale = FlashSale::create([
            'title' => 'Still active sale',
            'starts_at' => now()->subMinutes(10),
            'ends_at' => now()->addHour(),
            'status' => FlashSale::STATUS_ACTIVE,
        ]);

        $itemA = FlashSaleItem::create([
            'flash_sale_id' => $endedSale->id,
            'product_id' => $product->id,
            'sale_price' => 5,
            'quantity_limit' => 10,
            'quantity_sold' => 3,
        ]);

        $itemB = FlashSaleItem::create([
            'flash_sale_id' => $stillActiveSale->id,
            'product_id' => $product->id,
            'sale_price' => 5,
            'quantity_limit' => 10,
            'quantity_sold' => 3,
        ]);

        $this->itemAId = $itemA->id;
        $this->itemBId = $itemB->id;

        Redis::connection('stock')->set("flashsale:{$itemA->id}:stock", 7);
        Redis::connection('stock')->set("flashsale:{$itemB->id}:stock", 7);

        $this->artisan('flash-sale:cleanup-stock')->assertExitCode(0);

        // The ended sale's counter is gone and its status is caught up...
        $this->assertFalse((bool) Redis::connection('stock')->exists("flashsale:{$itemA->id}:stock"));
        $this->assertSame(FlashSale::STATUS_ENDED, $endedSale->fresh()->status);

        // ...while the still-active sale's counter and status are untouched.
        $this->assertTrue((bool) Redis::connection('stock')->exists("flashsale:{$itemB->id}:stock"));
        $this->assertSame(FlashSale::STATUS_ACTIVE, $stillActiveSale->fresh()->status);
    }

    public function test_it_is_idempotent_and_does_not_resweep_an_already_ended_sale(): void
    {
        $product = Product::create([
            'name' => 'Cleanup Test Product 2',
            'slug' => 'cleanup-test-product-2-' . uniqid(),
            'base_price' => 10,
            'sku' => 'CLEANUP2-' . uniqid(),
            'is_active' => true,
        ]);

        $alreadyEnded = FlashSale::create([
            'title' => 'Already ended sale',
            'starts_at' => now()->subHours(3),
            'ends_at' => now()->subHours(2),
            'status' => FlashSale::STATUS_ENDED,
        ]);

        FlashSaleItem::create([
            'flash_sale_id' => $alreadyEnded->id,
            'product_id' => $product->id,
            'sale_price' => 5,
            'quantity_limit' => 10,
            'quantity_sold' => 10,
        ]);

        // Should be a no-op: nothing matches status != 'ended'.
        $this->artisan('flash-sale:cleanup-stock')->assertExitCode(0);

        $this->assertSame(FlashSale::STATUS_ENDED, $alreadyEnded->fresh()->status);
    }
}
