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
            // Deliberately disabled (2026-09-29) — the owner wants the local
            // demo down to a single shop (Thread & Needle Tailoring / Maria
            // Cruz) only, and had the other 4 shops this seeder creates
            // force-deleted from the dev DB. Leave this commented out so a
            // future `db:seed` doesn't silently bring them back; uncomment
            // if the Branch Manager demo account (one of this seeder's
            // stores) is needed again.
            // AdditionalStoresSeeder::class,
        ]);
    }
}
