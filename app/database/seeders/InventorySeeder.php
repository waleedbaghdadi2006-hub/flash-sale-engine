<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class InventorySeeder extends Seeder
{
    /**
     * Depends on ProductSeeder. One row per product (unique product_id).
     */
    public function run(): void
    {
        DB::table('inventory')->insert([
            [
                'product_id' => 1,
                'quantity_available' => 150,
                'quantity_reserved' => 10,
                'version' => 0,
                'last_restocked_at' => Carbon::now()->subDays(3),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'product_id' => 2,
                'quantity_available' => 60,
                'quantity_reserved' => 5,
                'version' => 0,
                'last_restocked_at' => Carbon::now()->subDays(3),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'product_id' => 3,
                'quantity_available' => 40,
                'quantity_reserved' => 0,
                'version' => 0,
                'last_restocked_at' => Carbon::now()->subDays(10),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'product_id' => 4,
                'quantity_available' => 300,
                'quantity_reserved' => 20,
                'version' => 0,
                'last_restocked_at' => Carbon::now()->subDay(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'product_id' => 5,
                'quantity_available' => 0, // out of stock on purpose
                'quantity_reserved' => 0,
                'version' => 2,
                'last_restocked_at' => Carbon::now()->subMonth(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'product_id' => 6,
                'quantity_available' => 0, // discontinued product
                'quantity_reserved' => 0,
                'version' => 5,
                'last_restocked_at' => Carbon::now()->subMonths(8),
                'created_at' => Carbon::now()->subYear(),
                'updated_at' => Carbon::now()->subMonths(6),
            ],
        ]);
    }
}
