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
                'name' => 'Shop Owner',
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
                'name' => 'SUTURA Tailoring',
                'slug' => 'sutura-shop',
                'description' => 'Premium tailoring and bespoke design services.',
                'address' => '123 Rizal Avenue',
                'city' => 'Davao City',
                'province' => 'Davao del Sur',
                'email' => 'hello@suturashop.com',
                'phone' => '+639000000000',
                'status' => 'approved', // Bypasses Admin Approval!
                'approved_at' => now(),
            ]
        );

        // Assign Trial Subscription to Shop (Default to Premium plan)
        $premiumPlan = \App\Models\SubscriptionPlan::where('slug', 'premium')->first();
        if ($premiumPlan && !$shop->subscription()->exists()) {
            \App\Models\ShopSubscription::create([
                'shop_id' => $shop->id,
                'plan_id' => $premiumPlan->id,
                'status' => 'trial',
                'starts_at' => now(),
                'ends_at' => now()->addDays(30),
                'trial_ends_at' => now()->addDays(30),
            ]);
        }

        // Seed Main Branch
        $mainBranch = \App\Models\ShopBranch::create([
            'shop_id' => $shop->id,
            'name' => 'SUTURA (Main Branch)',
            'address' => '123 Rizal Avenue',
            'city' => 'Davao City',
            'latitude' => 7.0702,
            'longitude' => 125.6077,
            'contact_number' => '+63 900 000 0000',
            'is_main' => true,
        ]);

        // 4. Create a Tailoring Staff Member
        $staff = User::firstOrCreate(
            ['email' => 'staff@sutura.com'],
            [
                'name' => 'Tailoring Staff',
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
