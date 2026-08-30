<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Carbon;

class UserTokenSeeder extends Seeder
{
    /**
     * Depends on UserSeeder. user_id 4 = jane.smith (unverified email),
     * user_id 3 = john.doe (requesting a password reset).
     */
    public function run(): void
    {
        DB::table('user_tokens')->insert([
            [
                'user_id' => 4,
                'type' => 'email_verification',
                'token_hash' => Hash::make('verify-token-jane'),
                'expires_at' => Carbon::now()->addDay(),
                'used_at' => null,
                'created_at' => Carbon::now(),
            ],
            [
                'user_id' => 3,
                'type' => 'password_reset',
                'token_hash' => Hash::make('reset-token-john'),
                'expires_at' => Carbon::now()->addHours(2),
                'used_at' => null,
                'created_at' => Carbon::now(),
            ],
            [
                'user_id' => 5,
                'type' => 'password_reset',
                'token_hash' => Hash::make('reset-token-mike-used'),
                'expires_at' => Carbon::now()->subHour(),
                'used_at' => Carbon::now()->subHours(2),
                'created_at' => Carbon::now()->subHours(3),
            ],
        ]);
    }
}
