<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class PaymentSeeder extends Seeder
{
    /**
     * Depends on OrderSeeder.
     */
    public function run(): void
    {
        DB::table('payments')->insert([
            [
                'order_id' => 1,
                'provider' => 'stripe',
                'provider_transaction_id' => 'ch_test_0001',
                'amount' => 549.98,
                'currency' => 'USD',
                'status' => 'succeeded',
                'failure_reason' => null,
                'created_at' => Carbon::now()->subDays(14),
                'updated_at' => Carbon::now()->subDays(14),
            ],
            [
                'order_id' => 2,
                'provider' => 'stripe',
                'provider_transaction_id' => 'ch_test_0002',
                'amount' => 1057.32,
                'currency' => 'USD',
                'status' => 'succeeded',
                'failure_reason' => null,
                'created_at' => Carbon::now()->subDays(5),
                'updated_at' => Carbon::now()->subDays(5),
            ],
            [
                'order_id' => 3,
                'provider' => 'paypal',
                'provider_transaction_id' => 'pp_test_0003',
                'amount' => 436.98,
                'currency' => 'USD',
                'status' => 'pending',
                'failure_reason' => null,
                'created_at' => Carbon::now()->subHours(3),
                'updated_at' => Carbon::now()->subHours(3),
            ],
            [
                'order_id' => 4,
                'provider' => 'stripe',
                'provider_transaction_id' => 'ch_test_0004',
                'amount' => 24.42,
                'currency' => 'USD',
                'status' => 'refunded', // order was cancelled
                'failure_reason' => null,
                'created_at' => Carbon::now()->subMonth(),
                'updated_at' => Carbon::now()->subMonth()->addDay(),
            ],
            [
                'order_id' => 4,
                'provider' => 'stripe',
                'provider_transaction_id' => 'ch_test_0004_attempt1',
                'amount' => 24.42,
                'currency' => 'USD',
                'status' => 'failed', // first attempt failed before the successful one above
                'failure_reason' => 'insufficient_funds',
                'created_at' => Carbon::now()->subMonth()->subHour(),
                'updated_at' => Carbon::now()->subMonth()->subHour(),
            ],
        ]);
    }
}
