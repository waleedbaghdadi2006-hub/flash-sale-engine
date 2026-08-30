<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class CouponSeeder extends Seeder
{
    /**
     * Assumes a fresh table, so ids are 1..4 in insertion order below.
     */
    public function run(): void
    {
        DB::table('coupons')->insert([
            [
                // id 1
                'code' => 'WELCOME10',
                'description' => '10% off your first order',
                'discount_type' => 'percentage',
                'discount_value' => 10.00,
                'min_order_amount' => 0,
                'max_uses' => 1000,
                'times_used' => 42,
                'starts_at' => Carbon::now()->subMonths(3),
                'expires_at' => Carbon::now()->addMonths(9),
                'is_active' => true,
                'created_at' => Carbon::now()->subMonths(3),
                'updated_at' => Carbon::now(),
            ],
            [
                // id 2
                'code' => 'SAVE20',
                'description' => '$20 off orders over $100',
                'discount_type' => 'fixed_amount',
                'discount_value' => 20.00,
                'min_order_amount' => 100.00,
                'max_uses' => 500,
                'times_used' => 120,
                'starts_at' => Carbon::now()->subMonth(),
                'expires_at' => Carbon::now()->addMonth(),
                'is_active' => true,
                'created_at' => Carbon::now()->subMonth(),
                'updated_at' => Carbon::now(),
            ],
            [
                // id 3: expired
                'code' => 'HOLIDAY2025',
                'description' => 'Holiday season 15% discount',
                'discount_type' => 'percentage',
                'discount_value' => 15.00,
                'min_order_amount' => 50.00,
                'max_uses' => 2000,
                'times_used' => 1875,
                'starts_at' => Carbon::now()->subMonths(9),
                'expires_at' => Carbon::now()->subMonths(8),
                'is_active' => false,
                'created_at' => Carbon::now()->subMonths(9),
                'updated_at' => Carbon::now()->subMonths(8),
            ],
            [
                // id 4: unlimited uses, no expiry
                'code' => 'FREESHIP',
                'description' => 'Free shipping fixed discount',
                'discount_type' => 'fixed_amount',
                'discount_value' => 5.00,
                'min_order_amount' => 0,
                'max_uses' => null,
                'times_used' => 0,
                'starts_at' => null,
                'expires_at' => null,
                'is_active' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ]);
    }
}
