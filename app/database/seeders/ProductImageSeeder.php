<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class ProductImageSeeder extends Seeder
{
    /**
     * Depends on ProductSeeder.
     */
    public function run(): void
    {
        DB::table('product_images')->insert([
            [
                'product_id' => 1,
                'url' => 'https://cdn.example.com/products/aurora-x1-front.jpg',
                'alt_text' => 'Aurora Smartphone X1 front view',
                'sort_order' => 0,
                'created_at' => Carbon::now(),
            ],
            [
                'product_id' => 1,
                'url' => 'https://cdn.example.com/products/aurora-x1-back.jpg',
                'alt_text' => 'Aurora Smartphone X1 back view',
                'sort_order' => 1,
                'created_at' => Carbon::now(),
            ],
            [
                'product_id' => 2,
                'url' => 'https://cdn.example.com/products/aurora-x1-pro-front.jpg',
                'alt_text' => 'Aurora Smartphone X1 Pro front view',
                'sort_order' => 0,
                'created_at' => Carbon::now(),
            ],
            [
                'product_id' => 3,
                'url' => 'https://cdn.example.com/products/voyager-14-open.jpg',
                'alt_text' => 'Voyager Laptop 14" open',
                'sort_order' => 0,
                'created_at' => Carbon::now(),
            ],
            [
                'product_id' => 4,
                'url' => 'https://cdn.example.com/products/tshirt-classic.jpg',
                'alt_text' => "Men's Classic T-Shirt",
                'sort_order' => 0,
                'created_at' => Carbon::now(),
            ],
            [
                'product_id' => 5,
                'url' => 'https://cdn.example.com/products/mystery-bag.jpg',
                'alt_text' => 'Mystery Grab Bag',
                'sort_order' => 0,
                'created_at' => Carbon::now(),
            ],
        ]);
    }
}
