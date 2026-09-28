<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            SubscriptionPlanSeeder::class,
            LocalTestSeeder::class,
            // Fully built (2 more stores, including this dataset's ONLY
            // Branch Manager test account) but never actually registered
            // here — every `db:seed` run silently skipped it. Found while
            // auditing for seed-data bugs; there was no way to demo the
            // Branch Manager role at all without this.
            AdditionalStoresSeeder::class,
        ]);
    }
}
