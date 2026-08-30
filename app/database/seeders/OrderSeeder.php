<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class OrderSeeder extends Seeder
{
    /**
     * Depends on UserSeeder, AddressSeeder, FlashSaleSeeder, CouponSeeder.
     * Assumes a fresh table, so ids are 1..4 in insertion order below.
     */
    public function run(): void
    {
        DB::table('orders')->insert([
            [
                // id 1: delivered order, no coupon/flash sale
                'order_number' => 'ORD-2026-00001',
                'user_id' => 3, // john.doe
                'flash_sale_id' => null,
                'coupon_id' => null,
                'shipping_address_id' => 1,
                'billing_address_id' => 1,
                'subtotal' => 499.99,
                'discount_amount' => 0,
                'shipping_amount' => 9.99,
                'tax_amount' => 40.00,
                'total_price' => 549.98,
                'currency' => 'USD',
                'status' => 'delivered',
                'created_at' => Carbon::now()->subDays(14),
                'updated_at' => Carbon::now()->subDays(10),
            ],
            [
                // id 2: shipped order, used a coupon
                'order_number' => 'ORD-2026-00002',
                'user_id' => 4, // jane.smith
                'flash_sale_id' => null,
                'coupon_id' => 2, // SAVE20
                'shipping_address_id' => 3,
                'billing_address_id' => 3,
                'subtotal' => 999.00,
                'discount_amount' => 20.00,
                'shipping_amount' => 0,
                'tax_amount' => 78.32,
                'total_price' => 1057.32,
                'currency' => 'USD',
                'status' => 'shipped',
                'created_at' => Carbon::now()->subDays(5),
                'updated_at' => Carbon::now()->subDay(),
            ],
            [
                // id 3: pending order bought during flash sale
                'order_number' => 'ORD-2026-00003',
                'user_id' => 5, // mike.jones
                'flash_sale_id' => 1, // Summer Electronics Blowout
                'coupon_id' => null,
                'shipping_address_id' => 4,
                'billing_address_id' => 5,
                'subtotal' => 399.99,
                'discount_amount' => 0,
                'shipping_amount' => 4.99,
                'tax_amount' => 32.00,
                'total_price' => 436.98,
                'currency' => 'USD',
                'status' => 'pending',
                'created_at' => Carbon::now()->subHours(3),
                'updated_at' => Carbon::now()->subHours(3),
            ],
            [
                // id 4: cancelled order
                'order_number' => 'ORD-2026-00004',
                'user_id' => 3, // john.doe
                'flash_sale_id' => null,
                'coupon_id' => 1, // WELCOME10
                'shipping_address_id' => 2,
                'billing_address_id' => 2,
                'subtotal' => 19.99,
                'discount_amount' => 2.00,
                'shipping_amount' => 4.99,
                'tax_amount' => 1.44,
                'total_price' => 24.42,
                'currency' => 'USD',
                'status' => 'cancelled',
                'created_at' => Carbon::now()->subMonth(),
                'updated_at' => Carbon::now()->subMonth(),
            ],
        ]);
    }
}
