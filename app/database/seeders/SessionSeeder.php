<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SessionSeeder extends Seeder
{
    /**
     * Depends on UserSeeder. `id` is a VARCHAR PK, so we generate UUIDs.
     */
    public function run(): void
    {
        DB::table('sessions')->insert([
            [
                'id' => (string) Str::uuid(),
                'user_id' => 1, // admin
                'token_hash' => Hash::make('session-token-admin'),
                'device_info' => 'Chrome on macOS',
                'ip_address' => '203.0.113.10',
                'expires_at' => Carbon::now()->addDays(7),
                'created_at' => Carbon::now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'user_id' => 3, // john.doe
                'token_hash' => Hash::make('session-token-john'),
                'device_info' => 'Safari on iOS',
                'ip_address' => '203.0.113.20',
                'expires_at' => Carbon::now()->addDays(7),
                'created_at' => Carbon::now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'user_id' => 5, // mike.jones
                'token_hash' => Hash::make('session-token-mike-expired'),
                'device_info' => 'Firefox on Windows',
                'ip_address' => '203.0.113.30',
                'expires_at' => Carbon::now()->subDay(), // already expired
                'created_at' => Carbon::now()->subDays(8),
            ],
        ]);
    }
}
