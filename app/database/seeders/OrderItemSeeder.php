<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class OrderItemSeeder extends Seeder
{
    /**
     * Depends on OrderSeeder and ProductSeeder.
     */
    public function run(): void
    {
        DB::table('order_items')->insert([
            [
                'order_id' => 1,
                'product_id' => 1,
                'product_name_snapshot' => 'Aurora Smartphone X1',
                'quantity' => 1,
                'unit_price' => 499.99,
                'created_at' => Carbon::now()->subDays(14),
            ],
            [
                'order_id' => 2,
                'product_id' => 3,
                'product_name_snapshot' => 'Voyager Laptop 14"',
                'quantity' => 1,
                'unit_price' => 999.00,
                'created_at' => Carbon::now()->subDays(5),
            ],
            [
                'order_id' => 3,
                'product_id' => 1,
                'product_name_snapshot' => 'Aurora Smartphone X1',
                'quantity' => 1,
                'unit_price' => 399.99, // flash sale price captured at purchase
                'created_at' => Carbon::now()->subHours(3),
            ],
            [
                'order_id' => 4,
                'product_id' => 4,
                'product_name_snapshot' => "Men's Classic T-Shirt",
                'quantity' => 1,
                'unit_price' => 19.99,
                'created_at' => Carbon::now()->subMonth(),
            ],
        ]);
    }
}
