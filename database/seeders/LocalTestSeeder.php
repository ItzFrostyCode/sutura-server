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
                'name' => 'Maria Cruz',
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
                'name' => 'Thread & Needle Tailoring',
                'slug' => 'thread-needle',
                'description' => 'Premium tailoring and bespoke design services.',
                'address' => '123 Rizal Avenue',
                'city' => 'Davao City',
                'province' => 'Davao del Sur',
                'email' => 'hello@threadneedle.com',
                'phone' => '+639000000000',
                'status' => 'approved', // Bypasses Admin Approval!
                'approved_at' => now(),
                'operating_hours' => [
                    'monday' => ['is_open' => true, 'open' => '09:00', 'close' => '18:00'],
                    'tuesday' => ['is_open' => true, 'open' => '09:00', 'close' => '18:00'],
                    'wednesday' => ['is_open' => true, 'open' => '09:00', 'close' => '18:00'],
                    'thursday' => ['is_open' => true, 'open' => '09:00', 'close' => '18:00'],
                    'friday' => ['is_open' => true, 'open' => '09:00', 'close' => '18:00'],
                    'saturday' => ['is_open' => false, 'open' => '09:00', 'close' => '18:00'],
                    'sunday' => ['is_open' => false, 'open' => '09:00', 'close' => '18:00'],
                ],
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
        $mainBranch = ShopBranch::create([
            'shop_id' => $shop->id,
            'name' => 'Main Branch',
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
                'name' => 'Juan Dela Cruz',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        if (!$staff->roles()->where('role_id', $staffRole->id)->exists()) {
            $staff->roles()->attach($staffRole->id);
        }

        // Link the staff to the shop branch via StaffProfile — this demo staff
        // member covers two roles (head tailor + sublimation), demonstrating
        // the ranked additional_roles feature rather than needing a second
        // duplicate-named account for the same person.
        if (!$staff->staffProfile()->exists()) {
            StaffProfile::create([
                'user_id' => $staff->id,
                'shop_id' => $shop->id,
                'shop_branch_id' => $mainBranch->id,
                'role' => 'head_tailor',
                'additional_roles' => ['sublimation_specialist'],
            ]);
        }

        // 5. Update Shop Branding (Profile Picture/Logo, Bio/Description)
        $shop->update([
            'logo_path' => 'http://127.0.0.1:8000/storage/logos/sutura_logo.png',
            'description' => "Davao City's premier provider of full sublimation jerseys, corporate uniforms, and custom tailoring.",
        ]);

        // 6. Seed separated services (Sublimation Jerseys & Bespoke Suits)
        // Service 1: Custom Sublimation Team Jerseys
        \App\Models\Service::updateOrCreate(
            [
                'shop_id' => $shop->id,
                'name' => 'Custom Sublimation Team Jerseys',
            ],
            [
                'description' => 'Full sublimation jerseys using high-quality drifit fabrics. Perfect for sports teams, tournaments, and athletic wear. Price varies based on quantity, fabric (Mesh, Honeycomb), and design complexity.',
                'category' => 'Sublimation & Digital Printing',
                'base_price' => null,
                'estimated_days' => 14,
                'is_active' => true,
                'image_url' => 'https://images.unsplash.com/photo-1587280501635-68a0e82cd5ff?q=80&w=800&auto=format&fit=crop',
                'custom_fields' => [
                    [
                        'name' => 'fabric_preference',
                        'label' => 'Fabric Preference',
                        'type' => 'dropdown',
                        'required' => true,
                        'options' => ['Drifit', 'Cotton', 'Honeycomb']
                    ],
                    [
                        'name' => 'team_name',
                        'label' => 'Team/Organization Name',
                        'type' => 'short_text',
                        'required' => true
                    ],
                    [
                        'name' => 'roster',
                        'label' => 'Player Name & Number Roster',
                        'type' => 'short_text',
                        'required' => true
                    ],
                    [
                        'name' => 'size_breakdown',
                        'label' => 'Size Breakdown (e.g. S-5, M-10, L-2)',
                        'type' => 'short_text',
                        'required' => true
                    ]
                ]
            ]
        );

        // Service 2: Bespoke Suit Tailoring
        \App\Models\Service::updateOrCreate(
            [
                'shop_id' => $shop->id,
                'name' => 'Bespoke Suit Tailoring',
            ],
            [
                'description' => 'Premium bespoke custom suits tailored to your exact measurements with premium fabrics, lining, and custom details. Price varies based on wool quality and lining.',
                'category' => 'Custom Tailoring & Bespoke',
                'base_price' => null,
                'estimated_days' => 15,
                'is_active' => true,
                'image_url' => 'https://images.unsplash.com/photo-1594938298603-c8148c4dae35?q=80&w=800&auto=format&fit=crop',
                'custom_fields' => []
            ]
        );

        // 7. Seed 2 Additional Shop Branches (making it 3 branches in total)
        $branch2 = ShopBranch::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'SUTURA (Lanang Branch)'],
            [
                'address' => 'Lanang Business Park',
                'city' => 'Davao City',
                'latitude' => 7.0988,
                'longitude' => 125.6312,
                'contact_number' => '+63 900 111 2222',
                'is_main' => false,
            ]
        );

        $branch3 = ShopBranch::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'SUTURA (Matina Branch)'],
            [
                'address' => 'Matina Crossing Road',
                'city' => 'Davao City',
                'latitude' => 7.0543,
                'longitude' => 125.5891,
                'contact_number' => '+63 900 333 4444',
                'is_main' => false,
            ]
        );

        // 8. Seed 2 Additional Tailoring Staff Members (making it 3 staff in total)
        $staffNames = [
            ['email' => 'ana.santos@sutura.com', 'name' => 'Ana Santos', 'role' => 'senior_designer'],
            ['email' => 'pedro.penduko@sutura.com', 'name' => 'Pedro Penduko', 'role' => 'cutter_sewer'],
        ];

        $staffUsers = [];
        $staffUsers[] = $staff; // Add the first staff we created earlier

        foreach ($staffNames as $sData) {
            $sUser = User::firstOrCreate(
                ['email' => $sData['email']],
                [
                    'name' => $sData['name'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );
            if (!$sUser->roles()->where('role_id', $staffRole->id)->exists()) {
                $sUser->roles()->attach($staffRole->id);
            }

            if (!$sUser->staffProfile()->exists()) {
                StaffProfile::create([
                    'user_id' => $sUser->id,
                    'shop_id' => $shop->id,
                    'shop_branch_id' => $mainBranch->id,
                    'role' => $sData['role']
                ]);
            }
            $staffUsers[] = $sUser;
        }

        // 9. Seed 3 Customers
        $customerRole = Role::where('name', 'customer')->first();
        $customers = [];
        $customerNames = [
            ['email' => 'jose.rizal@gmail.com', 'name' => 'Jose Rizal'],
            ['email' => 'andres.b@gmail.com', 'name' => 'Andres Bonifacio'],
            ['email' => 'maria.clara@gmail.com', 'name' => 'Maria Clara'],
        ];

        foreach ($customerNames as $cData) {
            $cUser = User::firstOrCreate(
                ['email' => $cData['email']],
                [
                    'name' => $cData['name'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );
            if (!$cUser->roles()->where('role_id', $customerRole->id)->exists()) {
                $cUser->roles()->attach($customerRole->id);
            }
            
            // Sync with shop customers
            $shop->customers()->syncWithoutDetaching([$cUser->id]);
            $customers[] = $cUser;
        }

        // 10. Seed 4 Appointments
        $service1 = \App\Models\Service::where('name', 'Custom Sublimation Team Jerseys')->first();
        $service2 = \App\Models\Service::where('name', 'Bespoke Suit Tailoring')->first();

        \App\Models\Appointment::firstOrCreate(
            ['shop_id' => $shop->id, 'customer_id' => $customers[0]->id, 'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i:s')],
            [
                'shop_branch_id' => $mainBranch->id,
                'service_id' => $service2->id,
                'status' => 'confirmed',
                'notes' => 'Bespoke suit fitting session.'
            ]
        );

        \App\Models\Appointment::firstOrCreate(
            ['shop_id' => $shop->id, 'customer_id' => $customers[1]->id, 'scheduled_at' => now()->addDays(3)->format('Y-m-d H:i:s')],
            [
                'shop_branch_id' => $branch2->id,
                'service_id' => $service1->id,
                'status' => 'pending',
                'notes' => 'Design discussion for basketball jerseys.'
            ]
        );

        \App\Models\Appointment::firstOrCreate(
            ['shop_id' => $shop->id, 'customer_id' => $customers[2]->id, 'scheduled_at' => now()->subDays(1)->format('Y-m-d H:i:s')],
            [
                'shop_branch_id' => $branch3->id,
                'service_id' => $service2->id,
                'status' => 'completed',
                'notes' => 'Initial consultation completed.'
            ]
        );

        \App\Models\Appointment::firstOrCreate(
            ['shop_id' => $shop->id, 'customer_id' => $customers[0]->id, 'scheduled_at' => now()->subDays(5)->format('Y-m-d H:i:s')],
            [
                'shop_branch_id' => $mainBranch->id,
                'service_id' => $service1->id,
                'status' => 'cancelled',
                'notes' => 'Cancelled by customer.'
            ]
        );

        // 11. Seed 4 Job Orders (Custom Jobs)
        $jo1 = \App\Models\JobOrder::firstOrCreate(
            ['shop_id' => $shop->id, 'order_number' => 'JO-1001'],
            [
                'shop_branch_id' => $mainBranch->id,
                'customer_id' => $customers[0]->id,
                'service_id' => $service2->id,
                'assigned_staff_id' => $staffUsers[0]->id,
                'total_amount' => 15000.00,
                'balance' => 7500.00,
                'payment_status' => 'partial',
                'status' => 'sewing',
                'due_date' => now()->addDays(10)->format('Y-m-d'),
                'notes' => 'Pina Cocoon lining custom suit',
                'intake_channel' => 'walk_in',
                'fulfillment_type' => 'pickup',
            ]
        );

        $jo2 = \App\Models\JobOrder::firstOrCreate(
            ['shop_id' => $shop->id, 'order_number' => 'JO-1002'],
            [
                'shop_branch_id' => $branch2->id,
                'customer_id' => $customers[1]->id,
                'service_id' => $service1->id,
                'assigned_staff_id' => $staffUsers[1]->id,
                'total_amount' => 6500.00,
                'balance' => 6500.00,
                'payment_status' => 'unpaid',
                'status' => 'cutting',
                'due_date' => now()->addDays(14)->format('Y-m-d'),
                'notes' => '10 jerseys set for tournament',
                'intake_channel' => 'online',
                'fulfillment_type' => 'shipping',
                'shipping_address' => 'Matina, Davao City',
                'custom_order_data' => [
                    'team_name' => 'Davao Eagles',
                    'team_roster' => [
                        ['name' => 'Juan Dela Cruz', 'print_name' => 'JUAN', 'number' => '10', 'size' => 'L'],
                        ['name' => 'Pedro Penduko', 'print_name' => 'PEDRO', 'number' => '7', 'size' => 'M'],
                        ['name' => 'Maria Makiling', 'print_name' => 'MARIA', 'number' => '23', 'size' => 'S'],
                    ]
                ]
            ]
        );

        $jo3 = \App\Models\JobOrder::firstOrCreate(
            ['shop_id' => $shop->id, 'order_number' => 'JO-1003'],
            [
                'shop_branch_id' => $branch3->id,
                'customer_id' => $customers[2]->id,
                'service_id' => $service2->id,
                'assigned_staff_id' => $staffUsers[3]->id,
                'total_amount' => 12000.00,
                'balance' => 0.00,
                'payment_status' => 'paid',
                'status' => 'ready_for_pickup',
                'due_date' => now()->addDays(1)->format('Y-m-d'),
                'notes' => 'Bespoke corporate dress suit',
                'intake_channel' => 'walk_in',
                'fulfillment_type' => 'pickup',
            ]
        );

        $jo4 = \App\Models\JobOrder::firstOrCreate(
            ['shop_id' => $shop->id, 'order_number' => 'JO-1004'],
            [
                'shop_branch_id' => $mainBranch->id,
                'customer_id' => $customers[0]->id,
                'service_id' => $service1->id,
                'assigned_staff_id' => $staffUsers[2]->id,
                'total_amount' => 3250.00,
                'balance' => 0.00,
                'payment_status' => 'paid',
                'status' => 'completed',
                'due_date' => now()->subDays(2)->format('Y-m-d'),
                'notes' => '5 training singlets completed',
                'intake_channel' => 'online',
                'fulfillment_type' => 'pickup',
                'custom_order_data' => [
                    'team_name' => 'Sartorial Club',
                    'team_roster' => [
                        ['name' => 'Jossua Arabejo', 'print_name' => 'JOSSUA', 'number' => '99', 'size' => 'XL'],
                        ['name' => 'Alex Wright', 'print_name' => 'ALEX', 'number' => '14', 'size' => 'M'],
                    ]
                ]
            ]
        );

        // 12. Seed 4 Payments
        \App\Models\Payment::firstOrCreate(
            ['job_order_id' => $jo1->id, 'amount' => 7500.00],
            [
                'payment_method' => 'bank_transfer',
                'recorded_by' => $owner->id,
                'notes' => 'Downpayment for custom suit.'
            ]
        );

        \App\Models\Payment::firstOrCreate(
            ['job_order_id' => $jo3->id, 'amount' => 12000.00],
            [
                'payment_method' => 'gcash',
                'recorded_by' => $owner->id,
                'notes' => 'Full payment.'
            ]
        );

        \App\Models\Payment::firstOrCreate(
            ['job_order_id' => $jo4->id, 'amount' => 3250.00],
            [
                'payment_method' => 'cash',
                'recorded_by' => $owner->id,
                'notes' => 'Settled in cash.'
            ]
        );

        \App\Models\Payment::firstOrCreate(
            ['job_order_id' => $jo2->id, 'amount' => 3250.00],
            [
                'payment_method' => 'gcash',
                'recorded_by' => $owner->id,
                'notes' => 'Partial deposit via GCash.'
            ]
        );

        // Update JO-1002 balance after payment
        $jo2->update([
            'balance' => 3250.00,
            'payment_status' => 'partial'
        ]);

        // 13. Catalog Items are now dynamically seeded via CatalogItemsSeeder from your custom images folder.

        // 14. Seed 3 Shop Reviews
        \App\Models\ShopReview::firstOrCreate(
            ['shop_id' => $shop->id, 'user_id' => $customers[0]->id],
            [
                'rating' => 5,
                'comment' => 'Exceptional quality. The bespoke suit fits perfectly.',
                'is_featured' => true,
            ]
        );

        \App\Models\ShopReview::firstOrCreate(
            ['shop_id' => $shop->id, 'user_id' => $customers[1]->id],
            [
                'rating' => 5,
                'comment' => 'Fast turnaround and high-quality sublimation jerseys. Highly recommended!',
                'is_featured' => true,
            ]
        );

        \App\Models\ShopReview::firstOrCreate(
            ['shop_id' => $shop->id, 'user_id' => $customers[2]->id],
            [
                'rating' => 4,
                'comment' => 'Very professional tailor, although scheduling the fitting session took some time. Overall great experience.',
                'is_featured' => false,
            ]
        );

        $this->call(CatalogItemsSeeder::class);

        // Link seeded job orders to catalog items to show earnings/performance data
        $item1 = \App\Models\CatalogItem::where('name', 'Andrea & Leo A1237 Off Shoulder Slit Leg Floral Tulle A Line Gown')->first();
        $item2 = \App\Models\CatalogItem::where('name', 'Cycling_Jerseys_1')->first();

        if ($item1 && isset($jo1)) {
            $jo1->update(['catalog_item_id' => $item1->id]);
        }
        if ($item2 && isset($jo2)) {
            $jo2->update(['catalog_item_id' => $item2->id]);
        }

        // Seed some ready-to-wear CatalogOrders
        if ($item1 && isset($customers[0])) {
            // 1. Seed a completed rental (returned)
            \App\Models\CatalogOrder::create([
                'shop_id' => $shop->id,
                'catalog_item_id' => $item1->id,
                'customer_id' => $customers[0]->id,
                'type' => 'online',
                'status' => 'completed',
                'total_amount' => 4500.00,
                'payment_status' => 'paid',
                'payment_method' => 'gcash',
                'intake_channel' => 'online',
                'fulfillment_type' => 'pickup',
                'rental_start_date' => now()->subDays(12)->toDateString(),
                'rental_end_date' => now()->subDays(7)->toDateString(),
                'security_deposit_amount' => 2250.00,
            ]);

            // 2. Seed a ready-for-pickup rental
            \App\Models\CatalogOrder::create([
                'shop_id' => $shop->id,
                'catalog_item_id' => $item1->id,
                'customer_id' => $customers[0]->id,
                'type' => 'online',
                'status' => 'ready',
                'total_amount' => 4500.00,
                'payment_status' => 'paid',
                'payment_method' => 'gcash',
                'intake_channel' => 'online',
                'fulfillment_type' => 'pickup',
                'rental_start_date' => now()->toDateString(),
                'rental_end_date' => now()->addDays(5)->toDateString(),
                'security_deposit_amount' => 2250.00,
            ]);

            // 3. Seed an active rental (out on rent)
            \App\Models\CatalogOrder::create([
                'shop_id' => $shop->id,
                'catalog_item_id' => $item1->id,
                'customer_id' => $customers[0]->id,
                'type' => 'online',
                'status' => 'out_for_delivery', // used as Active Rental status on client
                'total_amount' => 4500.00,
                'payment_status' => 'paid',
                'payment_method' => 'gcash',
                'intake_channel' => 'online',
                'fulfillment_type' => 'pickup',
                'rental_start_date' => now()->subDays(3)->toDateString(),
                'rental_end_date' => now()->addDays(2)->toDateString(),
                'security_deposit_amount' => 2250.00,
            ]);
        }

        if ($item2 && isset($customers[1])) {
            // 4. Seed a walkin completed purchase
            \App\Models\CatalogOrder::create([
                'shop_id' => $shop->id,
                'catalog_item_id' => $item2->id,
                'customer_id' => $customers[1]->id,
                'type' => 'walkin',
                'status' => 'completed',
                'total_amount' => 1300.00,
                'payment_status' => 'paid',
                'payment_method' => 'cash',
                'intake_channel' => 'walk_in',
                'fulfillment_type' => 'pickup',
            ]);

            // 5. Seed an online pending purchase with shipping requested
            \App\Models\CatalogOrder::create([
                'shop_id' => $shop->id,
                'catalog_item_id' => $item2->id,
                'customer_id' => $customers[1]->id,
                'type' => 'online',
                'status' => 'pending',
                'total_amount' => 650.00,
                'payment_status' => 'pending',
                'payment_method' => 'gcash',
                'payment_reference' => 'GCASH-REF-884920194',
                'payment_receipt_path' => 'https://images.unsplash.com/photo-1620712943543-bcc4688e7485?w=500&auto=format&fit=crop&q=60',
                'intake_channel' => 'online',
                'fulfillment_type' => 'shipping',
                'delivery_address' => '123 Rizal Avenue, Caloocan City, Metro Manila',
            ]);
        }

        // Seed some temporary schedules for testing
        \App\Models\ShopSpecialHour::create([
            'shop_id' => $shop->id,
            'title' => 'Christmas Break 2026',
            'start_date' => '2026-12-24',
            'end_date' => '2026-12-26',
            'is_closed' => true,
            'announcement_message' => 'Merry Christmas! SUTURA will be fully closed from December 24 to 26 to celebrate the holidays with our families. Online bookings on these dates are disabled.',
        ]);

        \App\Models\ShopSpecialHour::create([
            'shop_id' => $shop->id,
            'title' => 'Staff Planning Day',
            'start_date' => '2026-07-04',
            'end_date' => '2026-07-04',
            'is_closed' => false,
            'special_open_time' => '10:00:00',
            'special_close_time' => '15:00:00',
            'announcement_message' => 'We are having our annual Staff Planning Day on July 4. Custom hours apply: 10:00 AM - 3:00 PM.',
        ]);
    }
}
