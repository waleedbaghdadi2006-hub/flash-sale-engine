<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class AuditLogSeeder extends Seeder
{
    /**
     * Depends on UserSeeder (user_id is nullable, e.g. for system actions).
     */
    public function run(): void
    {
        DB::table('audit_logs')->insert([
            [
                'user_id' => 1, // admin
                'action' => 'update',
                'entity_type' => 'product',
                'entity_id' => 1,
                'old_values' => json_encode(['base_price' => 549.99]),
                'new_values' => json_encode(['base_price' => 499.99]),
                'ip_address' => '203.0.113.10',
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
                'created_at' => Carbon::now()->subDays(20),
            ],
            [
                'user_id' => 3, // john.doe
                'action' => 'create',
                'entity_type' => 'order',
                'entity_id' => 1,
                'old_values' => null,
                'new_values' => json_encode(['status' => 'pending', 'total_price' => 549.98]),
                'ip_address' => '203.0.113.20',
                'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
                'created_at' => Carbon::now()->subDays(14),
            ],
            [
                'user_id' => 2, // staff
                'action' => 'update',
                'entity_type' => 'order',
                'entity_id' => 1,
                'old_values' => json_encode(['status' => 'confirmed']),
                'new_values' => json_encode(['status' => 'delivered']),
                'ip_address' => '203.0.113.15',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                'created_at' => Carbon::now()->subDays(10),
            ],
            [
                'user_id' => null, // automated/system action
                'action' => 'refund',
                'entity_type' => 'payment',
                'entity_id' => 4,
                'old_values' => json_encode(['status' => 'succeeded']),
                'new_values' => json_encode(['status' => 'refunded']),
                'ip_address' => null,
                'user_agent' => 'system-cron/payment-reconciliation',
                'created_at' => Carbon::now()->subMonth()->addDay(),
            ],
            [
                'user_id' => 1, // admin
                'action' => 'delete',
                'entity_type' => 'product',
                'entity_id' => 6,
                'old_values' => json_encode(['is_active' => true]),
                'new_values' => json_encode(['is_active' => false, 'deleted_at' => Carbon::now()->subMonths(6)->toDateTimeString()]),
                'ip_address' => '203.0.113.10',
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
                'created_at' => Carbon::now()->subMonths(6),
            ],
        ]);
    }
}
