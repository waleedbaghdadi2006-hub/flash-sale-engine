<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class AddressSeeder extends Seeder
{
    /**
     * Depends on UserSeeder. Assumes a fresh table, so ids are 1..5
     * in insertion order below.
     */
    public function run(): void
    {
        DB::table('addresses')->insert([
            [
                // id 1
                'user_id' => 3, // john.doe home
                'label' => 'Home',
                'recipient_name' => 'John Doe',
                'phone' => '+15550000003',
                'line1' => '123 Main St',
                'line2' => null,
                'city' => 'Springfield',
                'state' => 'IL',
                'postal_code' => '62701',
                'country' => 'US',
                'is_default_shipping' => true,
                'is_default_billing' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                // id 2
                'user_id' => 3, // john.doe work
                'label' => 'Work',
                'recipient_name' => 'John Doe',
                'phone' => '+15550000003',
                'line1' => '456 Office Park Dr',
                'line2' => 'Suite 200',
                'city' => 'Springfield',
                'state' => 'IL',
                'postal_code' => '62702',
                'country' => 'US',
                'is_default_shipping' => false,
                'is_default_billing' => false,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                // id 3
                'user_id' => 4, // jane.smith home
                'label' => 'Home',
                'recipient_name' => 'Jane Smith',
                'phone' => '+15550000004',
                'line1' => '789 Elm St',
                'line2' => null,
                'city' => 'Austin',
                'state' => 'TX',
                'postal_code' => '73301',
                'country' => 'US',
                'is_default_shipping' => true,
                'is_default_billing' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                // id 4
                'user_id' => 5, // mike.jones home
                'label' => 'Home',
                'recipient_name' => 'Mike Jones',
                'phone' => '+15550000005',
                'line1' => '10 Downing Close',
                'line2' => null,
                'city' => 'London',
                'state' => null,
                'postal_code' => 'SW1A 2AA',
                'country' => 'GB',
                'is_default_shipping' => true,
                'is_default_billing' => false,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                // id 5
                'user_id' => 5, // mike.jones billing
                'label' => 'Billing',
                'recipient_name' => 'Mike Jones',
                'phone' => '+15550000005',
                'line1' => '10 Downing Close',
                'line2' => null,
                'city' => 'London',
                'state' => null,
                'postal_code' => 'SW1A 2AA',
                'country' => 'GB',
                'is_default_shipping' => false,
                'is_default_billing' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ]);
    }
}
