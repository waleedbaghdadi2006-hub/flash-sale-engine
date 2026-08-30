<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class CartItemSeeder extends Seeder
{
    /**
     * Depends on CartSeeder and ProductSeeder.
     */
    public function run(): void
    {
        DB::table('cart_items')->insert([
            [
                'cart_id' => 1, // john.doe's cart
                'product_id' => 1, // Aurora Smartphone X1
                'quantity' => 1,
                'unit_price_snapshot' => 499.99,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'cart_id' => 1, // john.doe's cart
                'product_id' => 4, // Men's Classic T-Shirt
                'quantity' => 3,
                'unit_price_snapshot' => 19.99,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'cart_id' => 2, // jane.smith's cart
                'product_id' => 3, // Voyager Laptop 14"
                'quantity' => 1,
                'unit_price_snapshot' => 999.00,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'cart_id' => 3, // guest cart
                'product_id' => 5, // Mystery Grab Bag
                'quantity' => 2,
                'unit_price_snapshot' => 9.99,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ]);
    }
}
