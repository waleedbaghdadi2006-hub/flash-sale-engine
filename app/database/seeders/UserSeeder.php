<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Carbon;

class UserSeeder extends Seeder
{
    /**
     * Assumes a fresh table (AUTO_INCREMENT starts at 1), so the
     * resulting ids are 1..5 in insertion order below.
     * id 1 = admin, id 2 = staff, ids 3-5 = customers.
     */
    public function run(): void
    {
        DB::table('users')->insert([
            [
                'email' => 'admin@example.com',
                'password_hash' => Hash::make('password'),
                'first_name' => 'Alice',
                'last_name' => 'Admin',
                'phone' => '+15550000001',
                'role' => 'admin',
                'email_verified_at' => Carbon::now(),
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'deleted_at' => null,
            ],
            [
                'email' => 'staff@example.com',
                'password_hash' => Hash::make('password'),
                'first_name' => 'Sam',
                'last_name' => 'Staffer',
                'phone' => '+15550000002',
                'role' => 'staff',
                'email_verified_at' => Carbon::now(),
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'deleted_at' => null,
            ],
            [
                'email' => 'john.doe@example.com',
                'password_hash' => Hash::make('password'),
                'first_name' => 'John',
                'last_name' => 'Doe',
                'phone' => '+15550000003',
                'role' => 'customer',
                'email_verified_at' => Carbon::now(),
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'deleted_at' => null,
            ],
            [
                'email' => 'jane.smith@example.com',
                'password_hash' => Hash::make('password'),
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'phone' => '+15550000004',
                'role' => 'customer',
                'email_verified_at' => null, // unverified on purpose
                'failed_login_attempts' => 2,
                'locked_until' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'deleted_at' => null,
            ],
            [
                'email' => 'mike.jones@example.com',
                'password_hash' => Hash::make('password'),
                'first_name' => 'Mike',
                'last_name' => 'Jones',
                'phone' => '+15550000005',
                'role' => 'customer',
                'email_verified_at' => Carbon::now(),
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'deleted_at' => null,
            ],
        ]);
    }
}
