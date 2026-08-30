<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class FlashSaleSeeder extends Seeder
{
    /**
     * Assumes a fresh table, so ids are 1..3 in insertion order below.
     */
    public function run(): void
    {
        DB::table('flash_sales')->insert([
            [
                // id 1: currently active
                'title' => 'Summer Electronics Blowout',
                'description' => '24-hour flash sale on phones and laptops.',
                'starts_at' => Carbon::now()->subHours(2),
                'ends_at' => Carbon::now()->addHours(22),
                'status' => 'active',
                'created_at' => Carbon::now()->subDay(),
                'updated_at' => Carbon::now(),
            ],
            [
                // id 2: upcoming
                'title' => 'Back to School Sale',
                'description' => 'Discounts on laptops for the new school year.',
                'starts_at' => Carbon::now()->addWeek(),
                'ends_at' => Carbon::now()->addWeek()->addDay(),
                'status' => 'pending',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                // id 3: already ended
                'title' => 'Spring Clearance',
                'description' => 'End-of-season clearance event.',
                'starts_at' => Carbon::now()->subMonth(),
                'ends_at' => Carbon::now()->subMonth()->addDay(),
                'status' => 'ended',
                'created_at' => Carbon::now()->subMonths(2),
                'updated_at' => Carbon::now()->subMonth(),
            ],
        ]);
    }
}
