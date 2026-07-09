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
        $mainBranch = ShopBranch::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Main Branch'],
            [
                'address' => '123 Rizal Avenue',
                'city' => 'Davao City',
                'latitude' => 7.0702,
                'longitude' => 125.6077,
                'contact_number' => '+63 900 000 0000',
                'is_main' => true,
            ]
        );

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
        $service1 = \App\Models\Service::updateOrCreate(
            [
                'shop_id' => $shop->id,
                'name' => 'Custom Sublimation Team Jerseys',
            ],
            [
                'description' => 'Full sublimation jerseys using high-quality drifit fabrics. Perfect for sports teams, tournaments, and athletic wear. Price varies based on quantity, fabric (Mesh, Honeycomb), and design complexity.',
                'category' => 'Sublimation & Digital Printing',
                'categories' => ['Custom Jersey Printing', 'Corporate & Team Uniforms'],
                'service_types' => ['bulk_sublimation'],
                'base_price' => 1000,
                'min_order_qty' => 10,
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
        $service2 = \App\Models\Service::updateOrCreate(
            [
                'shop_id' => $shop->id,
                'name' => 'Bespoke Suit Tailoring',
            ],
            [
                'description' => 'Premium bespoke custom suits tailored to your exact measurements with premium fabrics, lining, and custom details. Price varies based on wool quality and lining.',
                'category' => 'Custom Tailoring & Bespoke',
                'categories' => ['Suit & Tuxedo Tailoring', 'Formal & Cultural Wear'],
                'service_types' => ['custom_tailoring'],
                'base_price' => 3500,
                'estimated_days' => 15,
                'is_active' => true,
                'image_url' => 'https://images.unsplash.com/photo-1594938298603-c8148c4dae35?q=80&w=800&auto=format&fit=crop',
                'custom_fields' => [],
                'size_chart_columns' => ['Chest (in)', 'Waist (in)', 'Shoulder (in)'],
                'size_chart_rows' => [
                    ['size' => 'Small', 'values' => ['36', '30', '17']],
                    ['size' => 'Medium', 'values' => ['40', '34', '18']],
                    ['size' => 'Large', 'values' => ['44', '38', '19']],
                ],
            ]
        );

        // Pricing tiers for services 1 & 2 (the two "core" seeded services, priced
        // per-variant since "price varies" — matches how the Add Service form
        // requires at least one tier and derives `tags` from these labels).
        $pricingTiers = [
            $service1->id => [
                ['label' => 'Mesh Fabric Jersey Set', 'amount' => 1000],
                ['label' => 'Honeycomb Fabric Jersey Set', 'amount' => 1200],
                ['label' => 'Full Sublimation Premium Set', 'amount' => 1500],
            ],
            $service2->id => [
                ['label' => 'Classic Wool Suit', 'amount' => 3500],
                ['label' => 'Premium Wool Suit with Silk Lining', 'amount' => 5500],
                ['label' => 'Tuxedo, Full Canvas Construction', 'amount' => 8000],
            ],
        ];
        foreach ($pricingTiers as $serviceId => $tiers) {
            foreach ($tiers as $tier) {
                \App\Models\ServicePricing::updateOrCreate(
                    ['service_id' => $serviceId, 'label' => $tier['label']],
                    ['amount' => $tier['amount']]
                );
            }
            \App\Models\Service::find($serviceId)->update([
                'tags' => array_column($tiers, 'label'),
            ]);
        }

        // Additional real services (Barong, Bridal, School Uniforms, Alterations,
        // Corporate Jersey Printing, Embroidery) — these were originally added by
        // hand through the real Add Service form, not by this seeder, so a fresh
        // `migrate:fresh --seed` used to come back with only the 2 services above.
        $extraServices = [
            [
                'name' => 'Barong Tagalog Tailoring',
                'description' => "Classic Filipiniana formal wear, hand-tailored to fit. Choose from plain cotton, jusi, or premium piña fabric. Includes one fitting session before final delivery.",
                'categories' => ['Barong Tagalog Tailoring', 'Formal & Cultural Wear'],
                'service_types' => ['fashion_bridal'],
                'base_price' => 1500,
                'estimated_days' => 10,
                'image_url' => 'https://images.unsplash.com/photo-1602810318383-e386cc2a3ccf?q=80&w=800&auto=format&fit=crop',
                'tiers' => [
                    ['label' => 'Plain Cotton Barong', 'amount' => 1500],
                    ['label' => 'Jusi Fabric Barong', 'amount' => 2800],
                    ['label' => 'Premium Piña Barong', 'amount' => 4500],
                ],
            ],
            [
                'name' => 'Bridal & Wedding Gown Design',
                'description' => 'Custom-designed wedding gowns from sketch to final fitting. Two fitting sessions included.',
                'categories' => ['Custom Bridal Tailoring', 'Gown & Evening Wear Designing', 'Formal & Cultural Wear'],
                'service_types' => ['fashion_bridal'],
                'base_price' => 8000,
                'estimated_days' => 30,
                'image_url' => 'https://images.unsplash.com/photo-1594552072238-b8a33785b261?q=80&w=800&auto=format&fit=crop',
                'tiers' => [
                    ['label' => 'Simple A-Line Gown', 'amount' => 8000],
                    ['label' => 'Ball Gown with Beadwork', 'amount' => 15000],
                    ['label' => 'Mermaid Gown with Train', 'amount' => 25000],
                ],
            ],
            [
                'name' => 'School & Organization Uniform Sewing',
                'description' => 'Bulk uniform sewing for schools and organizations, sized per student roster.',
                'categories' => ['School Uniforms', 'Institutional & Uniform Wear', 'Corporate & Team Uniforms'],
                'service_types' => ['bulk_sublimation'],
                'base_price' => null,
                'estimated_days' => 20,
                'image_url' => '/catalog/Women\'s Esports Jersey with Customized Design .jpg',
                'tiers' => [
                    ['label' => 'Elementary Uniform Set', 'amount' => 450],
                    ['label' => 'High School Uniform Set', 'amount' => 650],
                    ['label' => 'Complete Set with PE Uniform', 'amount' => 950],
                ],
            ],
            [
                'name' => 'Garment Alterations & Repair Services',
                'description' => 'Resizing, hemming, and repair work on existing garments.',
                'categories' => ['General Clothing Alterations', 'Alterations & Adjustments'],
                'service_types' => ['alteration_repair'],
                'base_price' => null,
                'estimated_days' => 3,
                'image_url' => '/catalog/Bespoke_Suits2.jpg',
                'tiers' => [
                    ['label' => 'Hem Pants / Skirt', 'amount' => 150],
                    ['label' => 'Take In / Let Out Waist', 'amount' => 200],
                    ['label' => 'Zipper Replacement', 'amount' => 250],
                    ['label' => 'Sleeve Shortening', 'amount' => 200],
                ],
            ],
            [
                'name' => 'Corporate & Team Jersey Printing',
                'description' => 'Sublimation-printed jerseys for corporate teams and events.',
                'categories' => ['Custom Jersey Printing', 'Corporate & Team Uniforms'],
                'service_types' => ['bulk_sublimation'],
                'base_price' => null,
                'estimated_days' => 12,
                'image_url' => '/catalog/Healong-Customized-Design-Sportswear-Sublimation-Volleyball-Jersey.avif',
                'tiers' => [
                    ['label' => 'Basic Jersey Set (Top + Shorts)', 'amount' => 850],
                    ['label' => 'Full Sublimation Premium Set', 'amount' => 1200],
                ],
            ],
            [
                'name' => 'Embroidery & Logo Digitizing',
                'description' => 'Custom embroidery for logos, names, and designs on garments, uniforms, jackets, and accessories. New logo designs include one-time digitizing to convert artwork into a stitchable file.',
                'categories' => ['Embroidered Logos & Team Names', 'Custom Apparel, Printing & Embroidery'],
                'service_types' => ['bulk_sublimation'],
                'base_price' => null,
                'estimated_days' => 4,
                'image_url' => '/catalog/AllStar-Basketball-Jersey.jpg',
                'tiers' => [
                    ['label' => 'Small Logo Embroidery (up to 2x2 in)', 'amount' => 150],
                    ['label' => 'Team Name / Text Embroidery', 'amount' => 200],
                    ['label' => 'Large Design / Patch Embroidery', 'amount' => 350],
                    ['label' => 'Custom Logo Digitizing (one-time setup)', 'amount' => 500],
                ],
            ],
        ];

        foreach ($extraServices as $svc) {
            $tiers = $svc['tiers'];
            unset($svc['tiers']);
            $svc['tags'] = array_column($tiers, 'label');

            $newService = \App\Models\Service::updateOrCreate(
                ['shop_id' => $shop->id, 'name' => $svc['name']],
                $svc + ['is_active' => true]
            );

            foreach ($tiers as $tier) {
                \App\Models\ServicePricing::updateOrCreate(
                    ['service_id' => $newService->id, 'label' => $tier['label']],
                    ['amount' => $tier['amount']]
                );
            }
        }

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

        // 9b. Seed 3 more customers used specifically for the GCash/Bank Transfer
        // receipt-verification demo scenarios below — these existed only as ad
        // hoc records before, not in this seeder, so a fresh reseed lost them.
        $onlineCustomerNames = [
            ['email' => 'liza.fernandez@example.com', 'name' => 'Liza Fernandez'],
            ['email' => 'mark.villanueva@example.com', 'name' => 'Mark Villanueva'],
            ['email' => 'cristina.ramos@example.com', 'name' => 'Cristina Ramos'],
        ];
        $onlineCustomers = [];
        foreach ($onlineCustomerNames as $cData) {
            $cUser = User::firstOrCreate(
                ['email' => $cData['email']],
                ['name' => $cData['name'], 'password' => Hash::make('password'), 'email_verified_at' => now()]
            );
            if (!$cUser->roles()->where('role_id', $customerRole->id)->exists()) {
                $cUser->roles()->attach($customerRole->id);
            }
            $shop->customers()->syncWithoutDetaching([$cUser->id]);
            $onlineCustomers[$cData['name']] = $cUser;
        }

        // 10. Seed 4 Appointments
        $service1 = \App\Models\Service::where('name', 'Custom Sublimation Team Jerseys')->first();
        $service2 = \App\Models\Service::where('name', 'Bespoke Suit Tailoring')->first();

        \App\Models\Appointment::updateOrCreate(
            [
                'shop_id' => $shop->id,
                'customer_id' => $customers[0]->id,
                'service_id' => $service2->id,
                'status' => 'confirmed',
                'notes' => 'Bespoke suit fitting session.',
            ],
            [
                'shop_branch_id' => $mainBranch->id,
                'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
                'appointment_type' => 'consultation',
                'intake_channel' => 'walk_in',
                'duration_minutes' => 60,
            ]
        );

        \App\Models\Appointment::updateOrCreate(
            [
                'shop_id' => $shop->id,
                'customer_id' => $customers[1]->id,
                'service_id' => $service1->id,
                'status' => 'pending',
                'notes' => 'Design discussion for basketball jerseys.',
            ],
            [
                'shop_branch_id' => $branch2->id,
                'scheduled_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
                'appointment_type' => 'consultation',
                'intake_channel' => 'walk_in',
                'duration_minutes' => 60,
            ]
        );

        \App\Models\Appointment::updateOrCreate(
            [
                'shop_id' => $shop->id,
                'customer_id' => $customers[2]->id,
                'service_id' => $service2->id,
                'status' => 'completed',
                'notes' => 'Initial consultation completed.',
            ],
            [
                'shop_branch_id' => $branch3->id,
                'scheduled_at' => now()->subDays(1)->format('Y-m-d H:i:s'),
                'appointment_type' => 'consultation',
                'intake_channel' => 'walk_in',
                'duration_minutes' => 60,
            ]
        );

        \App\Models\Appointment::updateOrCreate(
            [
                'shop_id' => $shop->id,
                'customer_id' => $customers[0]->id,
                'service_id' => $service1->id,
                'status' => 'cancelled',
                'notes' => 'Cancelled by customer.',
            ],
            [
                'shop_branch_id' => $mainBranch->id,
                'scheduled_at' => now()->subDays(5)->format('Y-m-d H:i:s'),
                'appointment_type' => 'consultation',
                'intake_channel' => 'walk_in',
                'duration_minutes' => 60,
            ]
        );

        // 10b. Seed 2 pending appointments paid via GCash/Bank Transfer, awaiting
        // the owner's manual receipt verification — populates the "GCash & Bank
        // Receipts" tab on Collect Payments (only unverified, non-cash payments
        // show there; see JobOrderController/AppointmentController's queue query).
        \App\Models\Appointment::firstOrCreate(
            ['shop_id' => $shop->id, 'payment_reference' => 'GC-2201394857'],
            [
                'customer_id' => $onlineCustomers['Liza Fernandez']->id,
                'service_id' => $service2->id,
                'appointment_type' => 'consultation',
                'intake_channel' => 'online',
                'scheduled_at' => now()->addDays(2),
                'duration_minutes' => 60,
                'status' => 'pending',
                'payment_method' => 'gcash',
                'payment_status' => 'pending',
            ]
        );

        $bridalService = \App\Models\Service::where('name', 'Bridal & Wedding Gown Design')->first();
        \App\Models\Appointment::firstOrCreate(
            ['shop_id' => $shop->id, 'payment_reference' => 'BDO-88213340'],
            [
                'customer_id' => $onlineCustomers['Mark Villanueva']->id,
                'service_id' => $bridalService?->id,
                'appointment_type' => 'fitting',
                'intake_channel' => 'online',
                'scheduled_at' => now()->addDays(4),
                'duration_minutes' => 60,
                'status' => 'pending',
                'payment_method' => 'bank_transfer',
                'payment_status' => 'pending',
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
                        ['name' => 'Ramon Bautista', 'print_name' => 'RAMON', 'number' => '5', 'size' => 'L'],
                        ['name' => 'Carlo Reyes', 'print_name' => 'CARLO', 'number' => '11', 'size' => 'M'],
                        ['name' => 'Nico Santos', 'print_name' => 'NICO', 'number' => '3', 'size' => 'XL'],
                        ['name' => 'Ella Villanueva', 'print_name' => 'ELLA', 'number' => '8', 'size' => 'S'],
                        ['name' => 'Miguel Torres', 'print_name' => 'MIGUEL', 'number' => '14', 'size' => 'L'],
                        ['name' => 'Diego Fernandez', 'print_name' => 'DIEGO', 'number' => '22', 'size' => 'M'],
                        ['name' => 'Paolo Cruz', 'print_name' => 'PAOLO', 'number' => '9', 'size' => 'L'],
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
                'assigned_staff_id' => $staffUsers[0]->id,
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
                        ['name' => 'Ben Castillo', 'print_name' => 'BEN', 'number' => '6', 'size' => 'L'],
                        ['name' => 'Ken Morales', 'print_name' => 'KEN', 'number' => '2', 'size' => 'M'],
                        ['name' => 'Rico Domingo', 'print_name' => 'RICO', 'number' => '17', 'size' => 'S'],
                    ]
                ]
            ]
        );

        // 11b. Seed Multi-Stage Staff Assignments for each job order — otherwise
        // the "Multi-Stage Staff Assignment" card on every job's detail page
        // shows all 6 stages as "Unassigned", even though the feature (and its
        // completed/in-progress badges) is fully built and working. Each job
        // gets its already-passed stages marked completed and its CURRENT
        // status stage marked in-progress (no completed_at yet) — jobs already
        // past all 6 stages (ready_for_pickup, completed) get every stage closed out.
        $stageAssignments = [
            $jo1->id => [ // status: sewing — design/pattern_making/cutting done, sewing in progress
                ['stage' => 'design', 'staff' => $staffUsers[0], 'assigned' => 6, 'completed' => 5],
                ['stage' => 'pattern_making', 'staff' => $staffUsers[0], 'assigned' => 5, 'completed' => 4],
                ['stage' => 'cutting', 'staff' => $staffUsers[0], 'assigned' => 4, 'completed' => 2],
                ['stage' => 'sewing', 'staff' => $staffUsers[0], 'assigned' => 2, 'completed' => null],
            ],
            $jo2->id => [ // status: cutting — design/pattern_making done, cutting in progress
                ['stage' => 'design', 'staff' => $staffUsers[1], 'assigned' => 4, 'completed' => 3],
                ['stage' => 'pattern_making', 'staff' => $staffUsers[1], 'assigned' => 3, 'completed' => 1],
                ['stage' => 'cutting', 'staff' => $staffUsers[1], 'assigned' => 1, 'completed' => null],
            ],
            $jo3->id => [ // status: ready_for_pickup — all 6 production stages already closed out
                ['stage' => 'design', 'staff' => $staffUsers[0], 'assigned' => 10, 'completed' => 9],
                ['stage' => 'pattern_making', 'staff' => $staffUsers[0], 'assigned' => 9, 'completed' => 8],
                ['stage' => 'cutting', 'staff' => $staffUsers[0], 'assigned' => 8, 'completed' => 6],
                ['stage' => 'sewing', 'staff' => $staffUsers[0], 'assigned' => 6, 'completed' => 4],
                ['stage' => 'fitting', 'staff' => $staffUsers[0], 'assigned' => 4, 'completed' => 2],
                ['stage' => 'finishing', 'staff' => $staffUsers[0], 'assigned' => 2, 'completed' => 1],
            ],
            $jo4->id => [ // status: completed — all 6 production stages already closed out
                ['stage' => 'design', 'staff' => $staffUsers[2], 'assigned' => 9, 'completed' => 8],
                ['stage' => 'pattern_making', 'staff' => $staffUsers[2], 'assigned' => 8, 'completed' => 7],
                ['stage' => 'cutting', 'staff' => $staffUsers[2], 'assigned' => 7, 'completed' => 5],
                ['stage' => 'sewing', 'staff' => $staffUsers[2], 'assigned' => 5, 'completed' => 3],
                ['stage' => 'fitting', 'staff' => $staffUsers[2], 'assigned' => 3, 'completed' => 2],
                ['stage' => 'finishing', 'staff' => $staffUsers[2], 'assigned' => 2, 'completed' => 1],
            ],
        ];
        foreach ($stageAssignments as $jobOrderId => $stages) {
            foreach ($stages as $s) {
                \App\Models\JobOrderStaff::updateOrCreate(
                    ['job_order_id' => $jobOrderId, 'user_id' => $s['staff']->id, 'stage' => $s['stage']],
                    [
                        'assigned_at' => now()->subDays($s['assigned']),
                        'completed_at' => $s['completed'] === null ? null : now()->subDays($s['completed']),
                    ]
                );
            }
        }

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

        // 13b. Two specific real catalog items (not part of CatalogItemsSeeder's
        // auto-generated set) needed for the GCash/Bank Transfer catalog-order
        // demo scenarios below. NOTE: this does not replace CatalogItemsSeeder's
        // auto-generated 48 items — those still seed alongside these two. Fully
        // matching the real, hand-curated 48-item catalog would mean rewriting
        // CatalogItemsSeeder itself, which hasn't been done yet.
        $gown1 = \App\Models\CatalogItem::updateOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Off-Shoulder Floral Tulle A-Line Gown'],
            [
                'price' => 4500,
                'material' => 'Chiffon & Tulle',
                'listing_type' => 'for_rent',
                'is_active' => true,
                'size_chart_columns' => ['Bust (in)', 'Waist (in)', 'Hip (in)'],
                'size_chart_rows' => [
                    ['size' => 'Small', 'values' => ['32', '25', '35']],
                    ['size' => 'Medium', 'values' => ['34', '27', '37']],
                    ['size' => 'Large', 'values' => ['36', '29', '39']],
                ],
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $gown1->id, 'image_url' => '/catalog/Andrea & Leo A1237 Off Shoulder Slit Leg Floral Tulle A Line Gown.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $gown2 = \App\Models\CatalogItem::updateOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Long-Train Wedding Gown'],
            [
                'price' => 4500,
                'material' => 'Chiffon & Tulle',
                'listing_type' => 'for_rent',
                'is_active' => true,
                'size_chart_columns' => ['Bust (in)', 'Waist (in)', 'Hip (in)'],
                'size_chart_rows' => [
                    ['size' => 'Small', 'values' => ['32', '25', '35']],
                    ['size' => 'Medium', 'values' => ['34', '27', '37']],
                    ['size' => 'Large', 'values' => ['36', '29', '39']],
                ],
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $gown2->id, 'image_url' => '/catalog/Shop Long Tail Wedding Gown .jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );

        // 13c. Seed 2 pending catalog orders paid via GCash/Bank Transfer,
        // awaiting the owner's manual receipt verification — same "GCash &
        // Bank Receipts" queue as the appointments seeded above.
        \App\Models\CatalogOrder::firstOrCreate(
            ['shop_id' => $shop->id, 'payment_reference' => 'GC-5563321190'],
            [
                'catalog_item_id' => $gown1->id,
                'customer_id' => $onlineCustomers['Cristina Ramos']->id,
                'type' => 'online',
                'status' => 'pending',
                'total_amount' => $gown1->price,
                'payment_status' => 'pending',
                'payment_method' => 'gcash',
                'intake_channel' => 'online',
                'fulfillment_type' => 'shipping',
            ]
        );
        \App\Models\CatalogOrder::firstOrCreate(
            ['shop_id' => $shop->id, 'payment_reference' => 'BPI-77102256'],
            [
                'catalog_item_id' => $gown2->id,
                'customer_id' => $onlineCustomers['Liza Fernandez']->id,
                'type' => 'online',
                'status' => 'pending',
                'total_amount' => $gown2->price,
                'payment_status' => 'pending',
                'payment_method' => 'bank_transfer',
                'intake_channel' => 'online',
                'fulfillment_type' => 'shipping',
            ]
        );

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
            \App\Models\CatalogOrder::updateOrCreate(
                ['shop_id' => $shop->id, 'catalog_item_id' => $item1->id, 'customer_id' => $customers[0]->id, 'status' => 'completed'],
                [
                    'type' => 'online',
                    'total_amount' => 4500.00,
                    'payment_status' => 'paid',
                    'payment_method' => 'gcash',
                    'intake_channel' => 'online',
                    'fulfillment_type' => 'pickup',
                    'rental_start_date' => now()->subDays(12)->toDateString(),
                    'rental_end_date' => now()->subDays(7)->toDateString(),
                    'security_deposit_amount' => 2250.00,
                ]
            );

            // 2. Seed a ready-for-pickup rental
            \App\Models\CatalogOrder::updateOrCreate(
                ['shop_id' => $shop->id, 'catalog_item_id' => $item1->id, 'customer_id' => $customers[0]->id, 'status' => 'ready'],
                [
                    'type' => 'online',
                    'total_amount' => 4500.00,
                    'payment_status' => 'paid',
                    'payment_method' => 'gcash',
                    'intake_channel' => 'online',
                    'fulfillment_type' => 'pickup',
                    'rental_start_date' => now()->toDateString(),
                    'rental_end_date' => now()->addDays(5)->toDateString(),
                    'security_deposit_amount' => 2250.00,
                ]
            );

            // 3. Seed an active rental (out on rent)
            \App\Models\CatalogOrder::updateOrCreate(
                ['shop_id' => $shop->id, 'catalog_item_id' => $item1->id, 'customer_id' => $customers[0]->id, 'status' => 'out_for_delivery'], // used as Active Rental status on client
                [
                    'type' => 'online',
                    'total_amount' => 4500.00,
                    'payment_status' => 'paid',
                    'payment_method' => 'gcash',
                    'intake_channel' => 'online',
                    'fulfillment_type' => 'pickup',
                    'rental_start_date' => now()->subDays(3)->toDateString(),
                    'rental_end_date' => now()->addDays(2)->toDateString(),
                    'security_deposit_amount' => 2250.00,
                ]
            );
        }

        if ($item2 && isset($customers[1])) {
            // 4. Seed a walkin completed purchase
            \App\Models\CatalogOrder::updateOrCreate(
                ['shop_id' => $shop->id, 'catalog_item_id' => $item2->id, 'customer_id' => $customers[1]->id, 'type' => 'walkin', 'status' => 'completed'],
                [
                    'total_amount' => 1300.00,
                    'payment_status' => 'paid',
                    'payment_method' => 'cash',
                    'intake_channel' => 'walk_in',
                    'fulfillment_type' => 'pickup',
                ]
            );

            // 5. Seed an online pending purchase with shipping requested
            \App\Models\CatalogOrder::updateOrCreate(
                ['shop_id' => $shop->id, 'payment_reference' => 'GCASH-REF-884920194'],
                [
                    'catalog_item_id' => $item2->id,
                    'customer_id' => $customers[1]->id,
                    'type' => 'online',
                    'status' => 'pending',
                    'total_amount' => 650.00,
                    'payment_status' => 'pending',
                    'payment_method' => 'gcash',
                    'payment_receipt_path' => 'https://images.unsplash.com/photo-1620712943543-bcc4688e7485?w=500&auto=format&fit=crop&q=60',
                    'intake_channel' => 'online',
                    'fulfillment_type' => 'shipping',
                    'delivery_address' => '123 Rizal Avenue, Caloocan City, Metro Manila',
                ]
            );
        }

        // Seed some temporary schedules for testing
        \App\Models\ShopSpecialHour::updateOrCreate(
            ['shop_id' => $shop->id, 'title' => 'Christmas Break 2026'],
            [
                'start_date' => '2026-12-24',
                'end_date' => '2026-12-26',
                'is_closed' => true,
                'announcement_message' => 'Merry Christmas! SUTURA will be fully closed from December 24 to 26 to celebrate the holidays with our families. Online bookings on these dates are disabled.',
            ]
        );

        \App\Models\ShopSpecialHour::updateOrCreate(
            ['shop_id' => $shop->id, 'title' => 'Staff Planning Day'],
            [
                'start_date' => '2026-07-04',
                'end_date' => '2026-07-04',
                'is_closed' => false,
                'special_open_time' => '10:00:00',
                'special_close_time' => '15:00:00',
                'announcement_message' => 'We are having our annual Staff Planning Day on July 4. Custom hours apply: 10:00 AM - 3:00 PM.',
            ]
        );

        // 15. Seed Coupons — otherwise the Coupons page is completely empty on
        // a fresh install, which looks broken/unfinished rather than just unused.
        \App\Models\Coupon::updateOrCreate(
            ['shop_id' => $shop->id, 'code' => 'WELCOME10'],
            [
                'discount_type' => 'percent',
                'discount_value' => 10,
                'applies_to' => 'all',
                'usage_limit' => 100,
                'used_count' => 0,
                'starts_at' => now()->subDays(5),
                'ends_at' => now()->addMonths(2),
                'is_active' => true,
            ]
        );
        \App\Models\Coupon::updateOrCreate(
            ['shop_id' => $shop->id, 'code' => 'BULK500'],
            [
                'discount_type' => 'fixed',
                'discount_value' => 500,
                'applies_to' => 'services',
                'usage_limit' => 50,
                'used_count' => 3,
                'starts_at' => now()->subDays(10),
                'ends_at' => now()->addMonths(1),
                'is_active' => true,
            ]
        );
        \App\Models\Coupon::updateOrCreate(
            ['shop_id' => $shop->id, 'code' => 'SUMMER2026'],
            [
                'discount_type' => 'percent',
                'discount_value' => 15,
                'applies_to' => 'catalog',
                'usage_limit' => 30,
                'used_count' => 30,
                'starts_at' => now()->subMonths(2),
                'ends_at' => now()->subDays(3),
                'is_active' => false,
            ]
        );

        // 16. Seed a Service Package (bundle) — combines two existing services.
        $barongService = \App\Models\Service::where('name', 'Barong Tagalog Tailoring')->first();
        if ($barongService && isset($service2)) {
            $package = \App\Models\ServicePackage::updateOrCreate(
                ['shop_id' => $shop->id, 'name' => 'Groom & Entourage Package'],
                [
                    'description' => 'Bespoke suit for the groom plus Barong Tagalog tailoring for the entourage, bundled at a discount.',
                    'bundle_price' => 12000,
                    'is_active' => true,
                ]
            );
            $package->services()->syncWithoutDetaching([$service2->id, $barongService->id]);
        }

        // 17. Seed Shop Posts (completed-work showcase) — otherwise "Our Work"
        // never appears on the public storefront (it only shows once posts exist).
        \App\Models\ShopPost::updateOrCreate(
            ['shop_id' => $shop->id, 'caption' => 'Delivered this custom barong for a client\'s wedding — jusi fabric with hand embroidery on the collar. Order placed 2 weeks ago, released today!'],
            [
                'service_id' => $barongService?->id,
                'image_urls' => ['https://images.unsplash.com/photo-1602810318383-e386cc2a3ccf?q=80&w=800&auto=format&fit=crop'],
            ]
        );
        \App\Models\ShopPost::updateOrCreate(
            ['shop_id' => $shop->id, 'caption' => 'Another satisfied customer picking up their bespoke suit today. Full canvas construction, tailored over 3 fitting sessions.'],
            [
                'service_id' => $service2->id ?? null,
                'image_urls' => ['https://images.unsplash.com/photo-1594938298603-c8148c4dae35?q=80&w=800&auto=format&fit=crop'],
            ]
        );

        // 18. Seed Measurement profiles — otherwise a customer's "Measurements &
        // Specs" tab is always empty, even though the whole point of the
        // measurement repository is to show it's populated and reusable.
        //
        // Jose Rizal's "Default" profile gets 2 versions, demonstrating the
        // version-history feature with real data: an older superseded
        // snapshot (matched on version explicitly, since version is now
        // part of the row's identity, not just shop+customer+profile_name)
        // and the current one.
        \App\Models\Measurement::updateOrCreate(
            ['shop_id' => $shop->id, 'customer_id' => $customers[0]->id, 'profile_name' => 'Default', 'version' => 1],
            [
                'source' => 'shop_owner',
                'metrics' => [
                    'Chest' => '39', 'Waist' => '33', 'Hip' => '39', 'Shoulder' => '18',
                    'Sleeve' => '25', 'Neck' => '16', 'Inseam' => '32',
                ],
                'notes' => 'Initial fitting — prefers a slightly looser fit around the shoulders.',
                'superseded_at' => now()->subDays(30),
            ]
        );
        \App\Models\Measurement::updateOrCreate(
            ['shop_id' => $shop->id, 'customer_id' => $customers[0]->id, 'profile_name' => 'Default', 'version' => 2],
            [
                'source' => 'shop_owner',
                'metrics' => [
                    'Chest' => '40', 'Waist' => '34', 'Hip' => '40', 'Shoulder' => '18',
                    'Sleeve' => '25', 'Neck' => '16', 'Inseam' => '32',
                ],
                'notes' => 'Re-measured after a follow-up fitting — chest and waist both grew half an inch.',
                'superseded_at' => null,
            ]
        );
        \App\Models\Measurement::updateOrCreate(
            ['shop_id' => $shop->id, 'customer_id' => $customers[2]->id, 'profile_name' => 'Wedding Gown Fitting', 'version' => 1],
            [
                'source' => 'shop_owner',
                'metrics' => [
                    'Chest' => '34', 'Waist' => '27', 'Hip' => '36', 'Shoulder' => '14',
                    'Sleeve' => '22', 'Shirt Length' => '58',
                ],
                'notes' => 'Second fitting scheduled after initial alterations.',
                'superseded_at' => null,
            ]
        );

        // 19. Seed Support Tickets — otherwise the owner's "Help & Support"
        // page is always empty, with no example of an open thread or a
        // resolved one with a reply to show the flow works end-to-end.
        $openTicket = \App\Models\SupportTicket::updateOrCreate(
            ['shop_id' => $shop->id, 'subject' => 'Payment shows as pending even after customer paid via GCash'],
            [
                'user_id' => $owner->id,
                'message' => 'A customer sent a GCash payment for their appointment deposit and I have the receipt, but it still shows as "pending" on my dashboard. How do I mark it as confirmed?',
                'type' => 'problem',
                'priority' => 'high',
                'status' => 'open',
            ]
        );
        $resolvedTicket = \App\Models\SupportTicket::updateOrCreate(
            ['shop_id' => $shop->id, 'subject' => 'Requesting a second branch to be added'],
            [
                'user_id' => $owner->id,
                'message' => 'We just opened a second branch in Quezon City. Can you add it to my account so I can start assigning staff and appointments there?',
                'type' => 'update_request',
                'priority' => 'medium',
                'status' => 'resolved',
                'resolved_at' => now()->subDays(2),
            ]
        );
        \App\Models\SupportTicketReply::updateOrCreate(
            ['ticket_id' => $resolvedTicket->id, 'message' => 'Done! Your Quezon City branch has been added — you can find it under Branches. Let us know if you need anything else.'],
            [
                'user_id' => $admin->id,
                'is_admin_reply' => true,
            ]
        );
    }
}
