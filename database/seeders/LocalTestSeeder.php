<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Role;
use App\Models\Shop;
use App\Models\StaffProfile;
use App\Models\ShopBranch;

class LocalTestSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('name', 'admin')->first();
        $ownerRole = Role::where('name', 'shop_owner')->first();
        $staffRole = Role::where('name', 'staff')->first();

        // 1. Create the Admin (For Jossua's Future Testing)
        $admin = User::firstOrCreate(
            ['email' => 'admin@sutura.com'],
            [
                'name' => 'System Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        if (!$admin->roles()->where('role_id', $adminRole->id)->exists()) {
            $admin->roles()->attach($adminRole->id);
        }

        // 2. Create YOU (The Shop Owner)
        $owner = User::firstOrCreate(
            ['email' => 'owner@sutura.com'],
            [
                'name' => 'Master Tailor',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        if (!$owner->roles()->where('role_id', $ownerRole->id)->exists()) {
            $owner->roles()->attach($ownerRole->id);
        }

        // 3. Create your Pre-Approved Shop
        $shop = Shop::firstOrCreate(
            ['owner_id' => $owner->id],
            [
                'name' => 'Sutura Davao Flagship',
                'slug' => 'sutura-davao',
                'description' => 'Premium bespoke tailoring in Davao City.',
                'address' => 'Poblacion District',
                'city' => 'Davao City',
                'province' => 'Davao del Sur',
                'email' => 'hello@suturadavao.com',
                'phone' => '+639123456789',
                'status' => 'approved', // Bypasses Admin Approval!
                'approved_at' => now(),
            ]
        );

        // Assign Trial Subscription to Shop
        $basicPlan = \App\Models\SubscriptionPlan::where('slug', 'basic')->first();
        if ($basicPlan && !$shop->subscription()->exists()) {
            \App\Models\ShopSubscription::create([
                'shop_id' => $shop->id,
                'plan_id' => $basicPlan->id,
                'status' => 'trial',
                'starts_at' => now(),
                'ends_at' => now()->addDays(14),
                'trial_ends_at' => now()->addDays(14),
            ]);
        }

        // Seed Main Branch
        $mainBranch = \App\Models\ShopBranch::create([
            'shop_id' => $shop->id,
            'name' => 'Sutura Davao (Main HQ)',
            'address' => '123 Tailor Street, Poblacion District',
            'city' => 'Davao City',
            'latitude' => 7.0702,
            'longitude' => 125.6077,
            'contact_number' => '+63 912 345 6789',
            'is_main' => true,
        ]);

        // 4. Create a Tailoring Staff Member
        $staff = User::firstOrCreate(
            ['email' => 'staff@sutura.com'],
            [
                'name' => 'Head Cutter Staff',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        if (!$staff->roles()->where('role_id', $staffRole->id)->exists()) {
            $staff->roles()->attach($staffRole->id);
        }

        // Link the staff to the shop branch via StaffProfile
        if (!$staff->staffProfile()->exists()) {
            \App\Models\StaffProfile::create([
                'user_id' => $staff->id,
                'shop_id' => $shop->id,
                'shop_branch_id' => $mainBranch->id,
                'role' => 'head_tailor'
            ]);
        }
    }
}
