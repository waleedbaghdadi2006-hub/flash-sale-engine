<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class ProductSeeder extends Seeder
{
    /**
     * Depends on CategorySeeder. Assumes a fresh table, so ids are 1..6
     * in insertion order below.
     */
    public function run(): void
    {
        DB::table('products')->insert([
            [
                // id 1
                'category_id' => 3, // Phones
                'name' => 'Aurora Smartphone X1',
                'slug' => 'aurora-smartphone-x1',
                'description' => 'A mid-range smartphone with a 6.5" display and dual camera.',
                'base_price' => 499.99,
                'currency' => 'USD',
                'sku' => 'SKU-PHN-0001',
                'is_active' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'deleted_at' => null,
            ],
            [
                // id 2
                'category_id' => 3, // Phones
                'name' => 'Aurora Smartphone X1 Pro',
                'slug' => 'aurora-smartphone-x1-pro',
                'description' => 'The flagship version with 256GB storage and improved camera.',
                'base_price' => 799.99,
                'currency' => 'USD',
                'sku' => 'SKU-PHN-0002',
                'is_active' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'deleted_at' => null,
            ],
            [
                // id 3
                'category_id' => 4, // Laptops
                'name' => 'Voyager Laptop 14"',
                'slug' => 'voyager-laptop-14',
                'description' => 'Lightweight laptop for everyday productivity.',
                'base_price' => 999.00,
                'currency' => 'USD',
                'sku' => 'SKU-LAP-0001',
                'is_active' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'deleted_at' => null,
            ],
            [
                // id 4
                'category_id' => 5, // Men's Apparel
                'name' => "Men's Classic T-Shirt",
                'slug' => 'mens-classic-t-shirt',
                'description' => '100% cotton crew-neck t-shirt.',
                'base_price' => 19.99,
                'currency' => 'USD',
                'sku' => 'SKU-APP-0001',
                'is_active' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'deleted_at' => null,
            ],
            [
                // id 5
                'category_id' => null, // uncategorized on purpose
                'name' => 'Mystery Grab Bag',
                'slug' => 'mystery-grab-bag',
                'description' => 'A surprise assortment of small accessories.',
                'base_price' => 9.99,
                'currency' => 'USD',
                'sku' => 'SKU-MISC-0001',
                'is_active' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'deleted_at' => null,
            ],
            [
                // id 6
                'category_id' => 4, // Laptops
                'name' => 'Voyager Laptop 14" (Discontinued)',
                'slug' => 'voyager-laptop-14-discontinued',
                'description' => 'Previous generation model, no longer sold.',
                'base_price' => 899.00,
                'currency' => 'USD',
                'sku' => 'SKU-LAP-0000',
                'is_active' => false,
                'created_at' => Carbon::now()->subYear(),
                'updated_at' => Carbon::now()->subMonths(6),
                'deleted_at' => Carbon::now()->subMonths(6), // soft-deleted example
            ],
        ]);
    }
}
