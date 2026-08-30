<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class CategorySeeder extends Seeder
{
    /**
     * Assumes a fresh table, so ids are 1..5 in insertion order below.
     * ids 1-2 are top-level categories; ids 3-5 are children of id 1/2.
     */
    public function run(): void
    {
        DB::table('categories')->insert([
            [
                // id 1
                'parent_id' => null,
                'name' => 'Electronics',
                'slug' => 'electronics',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                // id 2
                'parent_id' => null,
                'name' => 'Clothing',
                'slug' => 'clothing',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                // id 3
                'parent_id' => 1,
                'name' => 'Phones',
                'slug' => 'phones',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                // id 4
                'parent_id' => 1,
                'name' => 'Laptops',
                'slug' => 'laptops',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                // id 5
                'parent_id' => 2,
                'name' => "Men's Apparel",
                'slug' => 'mens-apparel',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ]);
    }
}
