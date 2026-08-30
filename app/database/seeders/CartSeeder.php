<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CartSeeder extends Seeder
{
    /**
     * Depends on UserSeeder. Assumes a fresh table, so ids are 1..3
     * in insertion order below. Each cart has either a user_id or a
     * guest_token (per the chk_carts_owner constraint).
     */
    public function run(): void
    {
        DB::table('carts')->insert([
            [
                // id 1: logged-in user's cart
                'user_id' => 3, // john.doe
                'guest_token' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                // id 2: another logged-in user's cart
                'user_id' => 4, // jane.smith
                'guest_token' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                // id 3: guest cart, no account yet
                'user_id' => null,
                'guest_token' => (string) Str::uuid(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ]);
    }
}
