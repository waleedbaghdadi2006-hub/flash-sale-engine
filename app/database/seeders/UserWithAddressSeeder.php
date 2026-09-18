<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Carbon;

class UserWithAddressSeeder extends Seeder
{
    public function run(): void
    {
        $passwordHash = Hash::make('YourSecurePassword123');
        $now = Carbon::now();

        for ($i = 1; $i <= 250; $i++) {
            $email = "user_{$i}@example.com";

            $userId = DB::table('users')->where('email', $email)->value('id');

            if (!$userId) {
                $userId = DB::table('users')->insertGetId([
                    'email' => $email,
                    'password_hash' => $passwordHash,
                    'first_name' => "TestUser",
                    'last_name' => "{$i}",
                    'phone' => '1234567890',
                    'role' => 'customer',
                    'email_verified_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('addresses')->updateOrInsert(
                ['user_id' => $userId],
                [
                    'label' => 'Home',
                    'recipient_name' => "TestUser {$i}",
                    'phone' => '1234567890',
                    'line1' => "{$i} Main Street",
                    'line2' => "Apt {$i}",
                    'city' => 'Test City',
                    'state' => 'Test State',
                    'postal_code' => '10001',
                    'country' => 'US',
                    'is_default_shipping' => true,
                    'is_default_billing' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}