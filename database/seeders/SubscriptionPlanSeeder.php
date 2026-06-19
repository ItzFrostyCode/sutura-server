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
        SubscriptionPlan::updateOrCreate(
            ['slug' => 'basic'],
            [
                'name' => 'Basic Tier',
                'description' => 'Perfect for small tailoring shops just getting started.',
                'price_monthly' => 499.00,
                'price_yearly' => 4990.00,
                'max_staff' => 3,
                'max_services' => 10,
                'max_appointments_per_month' => 50,
                'features' => json_encode([
                    'Basic Shop Profile',
                    'Up to 3 Staff Members',
                    'Up to 10 Services',
                    '50 Appointments per month',
                    'Standard Email Support'
                ]),
                'is_active' => true,
            ]
        );

        SubscriptionPlan::updateOrCreate(
            ['slug' => 'pro'],
            [
                'name' => 'Pro Tier',
                'description' => 'Ideal for growing shops with multiple staff and higher order volume.',
                'price_monthly' => 999.00,
                'price_yearly' => 9990.00,
                'max_staff' => 10,
                'max_services' => 50,
                'max_appointments_per_month' => 300,
                'features' => json_encode([
                    'Premium Shop Profile',
                    'Up to 10 Staff Members',
                    'Up to 50 Services',
                    '300 Appointments per month',
                    'Priority Support',
                    'Advanced Analytics'
                ]),
                'is_active' => true,
            ]
        );

        SubscriptionPlan::updateOrCreate(
            ['slug' => 'enterprise'],
            [
                'name' => 'Enterprise Tier',
                'description' => 'For large scale tailoring operations and multiple branches.',
                'price_monthly' => 1999.00,
                'price_yearly' => 19990.00,
                'max_staff' => -1, // Unlimited
                'max_services' => -1, // Unlimited
                'max_appointments_per_month' => -1, // Unlimited
                'features' => json_encode([
                    'Unlimited Staff',
                    'Unlimited Services',
                    'Unlimited Appointments',
                    '24/7 Dedicated Support',
                    'Multi-Branch Management',
                    'Custom Reporting'
                ]),
                'is_active' => true,
            ]
        );
    }
}
