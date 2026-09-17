<?php

namespace Tests\Feature;

use App\Models\FlashSale;
use App\Models\FlashSaleItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerifyFlashSaleLoadCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_verifies_expected_sold_units_and_order_items(): void
    {
        $sale = FlashSale::factory()->create();
        $product = Product::factory()->create();
        $item = FlashSaleItem::create([
            'flash_sale_id' => $sale->id,
            'product_id' => $product->id,
            'sale_price' => 10,
            'quantity_limit' => 10,
            'quantity_sold' => 3,
            'version' => 3,
        ]);

        $order = Order::factory()->create([
            'flash_sale_id' => $sale->id,
            'status' => 'confirmed',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name_snapshot' => 'Test product',
            'quantity' => 3,
            'unit_price' => 10,
        ]);

        $this->artisan('flash-sale:verify-load', [
            'flashSaleItemId' => $item->id,
            'expectedUnits' => 3,
        ])->assertExitCode(0);
    }
}
