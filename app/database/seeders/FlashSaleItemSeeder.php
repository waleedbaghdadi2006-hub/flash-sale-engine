<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class FlashSaleItemSeeder extends Seeder
{
    /**
     * Depends on FlashSaleSeeder and ProductSeeder.
     */
    public function run(): void
    {
        DB::table('flash_sale_items')->insert([
            [
                'flash_sale_id' => 1, // Summer Electronics Blowout (active)
                'product_id' => 1,    // Aurora Smartphone X1
                'sale_price' => 399.99,
                'quantity_limit' => 50,
                'quantity_sold' => 12,
                'version' => 0,
                'created_at' => Carbon::now()->subDay(),
            ],
            [
                'flash_sale_id' => 1, // Summer Electronics Blowout (active)
                'product_id' => 2,    // Aurora Smartphone X1 Pro
                'sale_price' => 649.99,
                'quantity_limit' => 20,
                'quantity_sold' => 20, // sold out
                'version' => 0,
                'created_at' => Carbon::now()->subDay(),
            ],
            [
                'flash_sale_id' => 2, // Back to School Sale (pending)
                'product_id' => 3,    // Voyager Laptop 14"
                'sale_price' => 849.00,
                'quantity_limit' => 30,
                'quantity_sold' => 0,
                'version' => 0,
                'created_at' => Carbon::now(),
            ],
        ]);
    }
}
