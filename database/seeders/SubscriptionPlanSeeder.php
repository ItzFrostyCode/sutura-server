<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SubscriptionPlan;

class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // ---------------------------------------------------------------
        // BASIC — ₱299/mo
        // ---------------------------------------------------------------
        SubscriptionPlan::updateOrCreate(
            ['slug' => 'basic'],
            [
                'name'                       => 'Basic',
                'description'                => 'Perfect for independent tailors just getting started online.',
                'price_monthly'              => 299.00,
                'price_yearly'               => 2990.00,
                'max_staff'                  => 1,
                'max_services'               => 10,
                'max_appointments_per_month' => 50,
                'features'                   => json_encode([
                    'Customer Management',
                    'Appointment Scheduling',
                    'Order Tracking',
                    'Measurement Recording',
                    'Text-Only Profile',
                    'Manual Updates (Web Portal)',
                    'Standard Search Listing',
                ]),
                'is_active'                  => true,
            ]
        );

        // ---------------------------------------------------------------
        // PRO — ₱799/mo
        // ---------------------------------------------------------------
        SubscriptionPlan::updateOrCreate(
            ['slug' => 'pro'],
            [
                'name'                       => 'Pro',
                'description'                => 'Grow faster with visibility tools, portfolio, and team management.',
                'price_monthly'              => 799.00,
                'price_yearly'               => 7990.00,
                'max_staff'                  => 5,
                'max_services'               => 50,
                'max_appointments_per_month' => 200,
                'features'                   => json_encode([
                    'All Basic Plan Features',
                    'Boosted Search Visibility',
                    'Visual Portfolio Gallery',
                    'Direct Customer Inquiries',
                    'Visual Dashboard & Analytics',
                    'SMS/Email Notifications',
                    'Measurement History',
                    'Multi-User Access',
                    'Staff Management',
                ]),
                'is_active'                  => true,
            ]
        );

        // ---------------------------------------------------------------
        // PREMIUM — ₱1,999/mo
        // ---------------------------------------------------------------
        SubscriptionPlan::updateOrCreate(
            ['slug' => 'premium'],
            [
                'name'                       => 'Premium',
                'description'                => 'Top-tier visibility, custom branding, and advanced reporting for serious shops.',
                'price_monthly'              => 1999.00,
                'price_yearly'               => 19990.00,
                'max_staff'                  => -1, // Unlimited
                'max_services'               => -1, // Unlimited
                'max_appointments_per_month' => -1, // Unlimited
                'features'                   => json_encode([
                    'All Pro Plan Features',
                    'Custom Branding',
                    'Featured Shop Visibility (Top Placement)',
                    'Sales Reports & Income Exports',
                    'Advanced Dashboard',
                ]),
                'is_active'                  => true,
            ]
        );
    }
}
