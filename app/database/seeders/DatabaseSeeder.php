<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Order matters: parents are seeded before the children that
     * hold foreign keys to them.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            UserTokenSeeder::class,
            SessionSeeder::class,
            AddressSeeder::class,
            CategorySeeder::class,
            ProductSeeder::class,
            ProductImageSeeder::class,
            InventorySeeder::class,
            FlashSaleSeeder::class,
            FlashSaleItemSeeder::class,
            CouponSeeder::class,
            CartSeeder::class,
            CartItemSeeder::class,
            OrderSeeder::class,
            OrderItemSeeder::class,
            PaymentSeeder::class,
            AuditLogSeeder::class,
        ]);
    }
}
