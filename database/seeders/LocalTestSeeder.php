<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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
                'slug' => \Illuminate\Support\Str::slug('Main Branch') . '-' . uniqid(),
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
        // Was hardcoded to http://127.0.0.1:8000/... -- only ever resolved on
        // one specific machine with `php artisan serve` running on that exact
        // port. Storage::url() generates the correct host for wherever this
        // actually runs (local, Postgres test, or real production), same
        // fix as FileUploadController's UPLOAD_DISK bug.
        $shop->update([
            'logo_path' => Storage::url('logos/sutura_logo.png'),
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
                'slug' => \Illuminate\Support\Str::slug('SUTURA (Lanang Branch)') . '-' . uniqid(),
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
                'slug' => \Illuminate\Support\Str::slug('SUTURA (Matina Branch)') . '-' . uniqid(),
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

        // Branch managers for the two satellite branches (Lanang, Matina) —
        // created live through the real branch-manager invite flow, not by
        // this seeder. Job orders/stages below prefer them over $staffUsers
        // (all Main Branch) so a Lanang/Matina job is staffed by someone who
        // actually works there; falls back to a Main Branch staffer if this
        // is a fresh install where those two accounts don't exist yet.
        $lanangStaff = User::where('email', 'ferdinand.cruz@sutura.com')->first() ?? $staffUsers[1];
        $matinaStaff = User::where('email', 'rowena.aquino@sutura.com')->first() ?? $staffUsers[0];

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

        // 10b. Seed 3 pending appointments paid via GCash/Bank Transfer, awaiting
        // the owner's manual receipt verification — populates the "GCash & Bank
        // Receipts" tab on Collect Payments (only unverified, non-cash payments
        // show there; see JobOrderController/AppointmentController's queue query).
        \App\Models\Appointment::updateOrCreate(
            ['shop_id' => $shop->id, 'payment_reference' => 'GC-2201394857'],
            [
                'customer_id' => $onlineCustomers['Liza Fernandez']->id,
                'service_id' => $service2->id,
                'shop_branch_id' => $mainBranch->id,
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
        \App\Models\Appointment::updateOrCreate(
            ['shop_id' => $shop->id, 'payment_reference' => 'BDO-88213340'],
            [
                'customer_id' => $onlineCustomers['Mark Villanueva']->id,
                'service_id' => $bridalService?->id,
                'shop_branch_id' => $branch2->id,
                'appointment_type' => 'fitting',
                'intake_channel' => 'online',
                'scheduled_at' => now()->addDays(4),
                'duration_minutes' => 60,
                'status' => 'pending',
                'payment_method' => 'bank_transfer',
                'payment_status' => 'pending',
            ]
        );

        $alterationService = \App\Models\Service::where('name', 'Garment Alterations & Repair Services')->first();
        \App\Models\Appointment::updateOrCreate(
            ['shop_id' => $shop->id, 'payment_reference' => 'GC-3390215671'],
            [
                'customer_id' => $onlineCustomers['Cristina Ramos']->id,
                'service_id' => $alterationService?->id,
                'shop_branch_id' => $branch3->id,
                'appointment_type' => 'fitting',
                'garment_category' => 'alteration_repair',
                'intake_channel' => 'online',
                'scheduled_at' => now()->addDays(3),
                'duration_minutes' => 30,
                'status' => 'pending',
                'payment_method' => 'gcash',
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

        $jo2 = \App\Models\JobOrder::updateOrCreate(
            ['shop_id' => $shop->id, 'order_number' => 'JO-1002'],
            [
                'shop_branch_id' => $branch2->id,
                'customer_id' => $customers[1]->id,
                'service_id' => $service1->id,
                'assigned_staff_id' => $lanangStaff->id,
                'total_amount' => 6500.00,
                'balance' => 6500.00,
                'payment_status' => 'unpaid',
                'status' => 'cutting',
                'due_date' => now()->addDays(14)->format('Y-m-d'),
                'notes' => '10 jerseys set for tournament',
                'intake_channel' => 'online',
                'fulfillment_type' => 'pickup',
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

        $jo3 = \App\Models\JobOrder::updateOrCreate(
            ['shop_id' => $shop->id, 'order_number' => 'JO-1003'],
            [
                'shop_branch_id' => $branch3->id,
                'customer_id' => $customers[2]->id,
                'service_id' => $service2->id,
                'assigned_staff_id' => $matinaStaff->id,
                'total_amount' => 12000.00,
                'balance' => 0.00,
                'payment_status' => 'paid',
                'status' => 'ready_for_pickup',
                'due_date' => now()->subDays(20)->format('Y-m-d'),
                'notes' => 'Bespoke corporate dress suit',
                'intake_channel' => 'walk_in',
                'fulfillment_type' => 'pickup',
            ]
        );
        // ready_for_pickup_at is server-derived (not in $fillable, same as
        // adjustment_count/first_adjustment_at) — 3 weeks past due and
        // already fully paid, the exact "customer never came back for it"
        // scenario the new Unclaimed Pickups aging list on Reports catches.
        $jo3->forceFill(['ready_for_pickup_at' => now()->subDays(21)])->save();

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

        // 11a. Seed 3 more Job Orders exercising the new 3-Phase Tailoring
        // Tracker statuses introduced by the pipeline redesign (Ready for
        // Fitting/Final Adjustments/QC & Ironing/the bulk-order Mass Cutting
        // & Printing override) so every Kanban column has at least one card.
        $jo5 = \App\Models\JobOrder::firstOrCreate(
            ['shop_id' => $shop->id, 'order_number' => 'JO-1005'],
            [
                'shop_branch_id' => $mainBranch->id,
                'customer_id' => $customers[1]->id,
                'service_id' => $service2->id,
                'assigned_staff_id' => $staffUsers[1]->id,
                'total_amount' => 18000.00,
                'balance' => 9000.00,
                'payment_status' => 'partial',
                'status' => 'ready_for_fitting',
                'due_date' => now()->addDays(7)->format('Y-m-d'),
                'notes' => 'Barong Tagalog — ready for first fitting',
                'intake_channel' => 'walk_in',
                'fulfillment_type' => 'pickup',
            ]
        );
        // Simulates the auto-created Fitting appointment JobOrderController
        // spawns when a job's status transitions to 'ready_for_fitting'.
        \App\Models\Appointment::firstOrCreate(
            ['shop_id' => $shop->id, 'job_order_id' => $jo5->id, 'appointment_type' => 'fitting'],
            [
                'shop_branch_id' => $mainBranch->id,
                'customer_id' => $jo5->customer_id,
                'intake_channel' => 'walk_in',
                'scheduled_at' => now()->addDay()->setTime(10, 0),
                'duration_minutes' => \App\Models\Appointment::TYPE_DEFAULT_DURATIONS['fitting'],
                'status' => 'pending',
                'notes' => "Auto-generated when Job Order {$jo5->order_number} became Ready for Fitting — please confirm the actual date/time with the customer.",
            ]
        );

        $jo6 = \App\Models\JobOrder::updateOrCreate(
            ['shop_id' => $shop->id, 'order_number' => 'JO-1006'],
            [
                'shop_branch_id' => $branch2->id,
                'customer_id' => $customers[2]->id,
                'service_id' => $service1->id,
                'assigned_staff_id' => $lanangStaff->id,
                'total_amount' => 9750.00,
                'balance' => 4875.00,
                'payment_status' => 'partial',
                'status' => 'mass_cutting_printing',
                'due_date' => now()->addDays(12)->format('Y-m-d'),
                'notes' => '15 volleyball jerseys — bulk order, skipped Pattern Making',
                'intake_channel' => 'online',
                'fulfillment_type' => 'pickup',
                'custom_order_data' => [
                    'team_name' => 'Matina Spikers',
                    'fabric_preference' => 'Drifit Mesh',
                    'team_roster' => [
                        ['name' => 'Anna Reyes', 'print_name' => 'ANNA', 'number' => '4', 'size' => 'M', 'completed' => false],
                        ['name' => 'Bea Santos', 'print_name' => 'BEA', 'number' => '12', 'size' => 'S', 'completed' => false],
                        ['name' => 'Carla Cruz', 'print_name' => 'CARLA', 'number' => '7', 'size' => 'L', 'completed' => false],
                        ['name' => 'Diana Mendoza', 'print_name' => 'DIANA', 'number' => '1', 'size' => 'M', 'completed' => false],
                        ['name' => 'Elena Garcia', 'print_name' => 'ELENA', 'number' => '9', 'size' => 'S', 'completed' => false],
                        ['name' => 'Faith Ramos', 'print_name' => 'FAITH', 'number' => '14', 'size' => 'XL', 'completed' => false],
                        ['name' => 'Grace Torres', 'print_name' => 'GRACE', 'number' => '3', 'size' => 'M', 'completed' => false],
                        ['name' => 'Hannah Lopez', 'print_name' => 'HANNAH', 'number' => '10', 'size' => 'S', 'completed' => false],
                        ['name' => 'Ivy Flores', 'print_name' => 'IVY', 'number' => '6', 'size' => 'L', 'completed' => false],
                        ['name' => 'Joyce Castro', 'print_name' => 'JOYCE', 'number' => '8', 'size' => 'M', 'completed' => false],
                        ['name' => 'Karen Diaz', 'print_name' => 'KAREN', 'number' => '15', 'size' => 'L', 'completed' => false],
                        ['name' => 'Lea Morales', 'print_name' => 'LEA', 'number' => '11', 'size' => 'M', 'completed' => false],
                        ['name' => 'Mia Navarro', 'print_name' => 'MIA', 'number' => '2', 'size' => 'S', 'completed' => false],
                        ['name' => 'Nicole Salazar', 'print_name' => 'NICOLE', 'number' => '5', 'size' => 'XL', 'completed' => false],
                        ['name' => 'Olivia Tan', 'print_name' => 'OLIVIA', 'number' => '13', 'size' => 'M', 'completed' => false],
                    ],
                ],
            ]
        );

        $jo7 = \App\Models\JobOrder::firstOrCreate(
            ['shop_id' => $shop->id, 'order_number' => 'JO-1007'],
            [
                'shop_branch_id' => $mainBranch->id,
                'customer_id' => $customers[0]->id,
                'service_id' => $service2->id,
                'assigned_staff_id' => $staffUsers[0]->id,
                'total_amount' => 14500.00,
                'balance' => 7250.00,
                'payment_status' => 'partial',
                'status' => 'final_adjustments',
                'due_date' => now()->addDays(3)->format('Y-m-d'),
                'notes' => 'Fitting revealed a shoulder adjustment — back to Final Adjustments',
                'intake_channel' => 'walk_in',
                'fulfillment_type' => 'pickup',
            ]
        );

        $jo8 = \App\Models\JobOrder::updateOrCreate(
            ['shop_id' => $shop->id, 'order_number' => 'JO-1008'],
            [
                'shop_branch_id' => $branch3->id,
                'customer_id' => $customers[1]->id,
                'service_id' => $service2->id,
                'assigned_staff_id' => $matinaStaff->id,
                'total_amount' => 11000.00,
                'balance' => 5500.00,
                'payment_status' => 'partial',
                'status' => 'qc_ironing',
                'due_date' => now()->addDays(1)->format('Y-m-d'),
                'notes' => 'Final quality check and ironing before pickup',
                'intake_channel' => 'walk_in',
                'fulfillment_type' => 'pickup',
            ]
        );

        // 11a-1. Two more scenarios added for the Home dashboard's newer
        // alert widgets (completed-unpaid + due-today), which every existing
        // JO above happened to miss — none were both completed and still
        // owing money, and none had due_date exactly today. Without these,
        // reseeding always left those two widgets empty, which read as
        // "broken" even though the feature itself worked fine.
        $jo9 = \App\Models\JobOrder::updateOrCreate(
            ['shop_id' => $shop->id, 'order_number' => 'JO-1009'],
            [
                'shop_branch_id' => $mainBranch->id,
                'customer_id' => $customers[2]->id,
                'service_id' => $service1->id,
                'assigned_staff_id' => $staffUsers[1]->id,
                'total_amount' => 4200.00,
                'balance' => 1200.00,
                'payment_status' => 'partial',
                'status' => 'completed',
                'due_date' => now()->subDays(3)->format('Y-m-d'),
                'notes' => 'Picked up already — owner let the customer take it and settle the rest later.',
                'intake_channel' => 'walk_in',
                'fulfillment_type' => 'pickup',
            ]
        );

        $jo10 = \App\Models\JobOrder::updateOrCreate(
            ['shop_id' => $shop->id, 'order_number' => 'JO-1010'],
            [
                'shop_branch_id' => $branch2->id,
                'customer_id' => $customers[0]->id,
                'service_id' => $service2->id,
                'assigned_staff_id' => $lanangStaff->id,
                'total_amount' => 7800.00,
                'balance' => 3900.00,
                'payment_status' => 'partial',
                'status' => 'sewing',
                'due_date' => now()->format('Y-m-d'),
                'notes' => 'Due today — customer is picking this up this afternoon.',
                'intake_channel' => 'walk_in',
                'fulfillment_type' => 'pickup',
            ]
        );

        // jo2 (JO-1002) starts unpaid above but receives a partial payment
        // later in this same seeder (a deliberate "started unpaid, later
        // paid something" scenario) — so nothing genuinely stays unpaid
        // without this one, leaving the "no downpayment collected" alert
        // permanently empty on every reseed.
        $jo11 = \App\Models\JobOrder::updateOrCreate(
            ['shop_id' => $shop->id, 'order_number' => 'JO-1011'],
            [
                'shop_branch_id' => $mainBranch->id,
                'customer_id' => $customers[1]->id,
                'service_id' => $service1->id,
                'assigned_staff_id' => $staffUsers[2]->id,
                'total_amount' => 5000.00,
                'balance' => 5000.00,
                'payment_status' => 'unpaid',
                'status' => 'pending',
                'due_date' => now()->addDays(9)->format('Y-m-d'),
                'notes' => "Full Sublimation Team Jerseys (5 sets). Red & Gold gradient print with team logo on left chest. Fabric: Drifit Honeycomb. Double-stitched seams on collar and armholes.",
                'intake_channel' => 'walk_in',
                'fulfillment_type' => 'pickup',
                'custom_order_data' => [
                    'team_name' => 'Katipunan Ballers',
                    'fabric_preference' => 'Honeycomb',
                    'team_roster' => [
                        ['name' => 'Andres Bonifacio', 'print_name' => 'A. BONIFACIO', 'number' => '1', 'size' => 'L', 'completed' => false],
                        ['name' => 'Emilio Jacinto', 'print_name' => 'E. JACINTO', 'number' => '2', 'size' => 'M', 'completed' => false],
                        ['name' => 'Pio Valenzuela', 'print_name' => 'P. VALENZUELA', 'number' => '3', 'size' => 'XL', 'completed' => false],
                        ['name' => 'Apolinario Mabini', 'print_name' => 'A. MABINI', 'number' => '4', 'size' => 'M', 'completed' => false],
                        ['name' => 'Melchora Aquino', 'print_name' => 'M. AQUINO', 'number' => '5', 'size' => 'S', 'completed' => false],
                    ],
                ],
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
                ['stage' => 'design', 'staff' => $lanangStaff, 'assigned' => 4, 'completed' => 3],
                ['stage' => 'pattern_making', 'staff' => $lanangStaff, 'assigned' => 3, 'completed' => 1],
                ['stage' => 'cutting', 'staff' => $lanangStaff, 'assigned' => 1, 'completed' => null],
            ],
            $jo3->id => [ // status: ready_for_pickup — all staff stages already closed out
                ['stage' => 'design', 'staff' => $matinaStaff, 'assigned' => 10, 'completed' => 9],
                ['stage' => 'pattern_making', 'staff' => $matinaStaff, 'assigned' => 9, 'completed' => 8],
                ['stage' => 'cutting', 'staff' => $matinaStaff, 'assigned' => 8, 'completed' => 6],
                ['stage' => 'sewing', 'staff' => $matinaStaff, 'assigned' => 6, 'completed' => 4],
                ['stage' => 'qc_ironing', 'staff' => $matinaStaff, 'assigned' => 2, 'completed' => 1],
            ],
            $jo4->id => [ // status: completed — all staff stages already closed out
                ['stage' => 'design', 'staff' => $staffUsers[2], 'assigned' => 9, 'completed' => 8],
                ['stage' => 'pattern_making', 'staff' => $staffUsers[2], 'assigned' => 8, 'completed' => 7],
                ['stage' => 'cutting', 'staff' => $staffUsers[2], 'assigned' => 7, 'completed' => 5],
                ['stage' => 'sewing', 'staff' => $staffUsers[2], 'assigned' => 5, 'completed' => 3],
                ['stage' => 'qc_ironing', 'staff' => $staffUsers[2], 'assigned' => 2, 'completed' => 1],
            ],
            $jo5->id => [ // status: ready_for_fitting — sewing done, no qc_ironing yet
                ['stage' => 'design', 'staff' => $staffUsers[1], 'assigned' => 8, 'completed' => 7],
                ['stage' => 'pattern_making', 'staff' => $staffUsers[1], 'assigned' => 7, 'completed' => 6],
                ['stage' => 'cutting', 'staff' => $staffUsers[1], 'assigned' => 6, 'completed' => 4],
                ['stage' => 'sewing', 'staff' => $staffUsers[1], 'assigned' => 4, 'completed' => 1],
            ],
            $jo6->id => [ // status: mass_cutting_printing — bulk order, pattern_making skipped
                ['stage' => 'design', 'staff' => $lanangStaff, 'assigned' => 3, 'completed' => 2],
                ['stage' => 'cutting', 'staff' => $lanangStaff, 'assigned' => 2, 'completed' => null],
            ],
            $jo7->id => [ // status: final_adjustments — reverted here after fitting
                ['stage' => 'design', 'staff' => $staffUsers[0], 'assigned' => 12, 'completed' => 11],
                ['stage' => 'pattern_making', 'staff' => $staffUsers[0], 'assigned' => 11, 'completed' => 10],
                ['stage' => 'cutting', 'staff' => $staffUsers[0], 'assigned' => 10, 'completed' => 8],
                ['stage' => 'sewing', 'staff' => $staffUsers[0], 'assigned' => 8, 'completed' => 5],
            ],
            $jo8->id => [ // status: qc_ironing — final quality check in progress
                ['stage' => 'design', 'staff' => $matinaStaff, 'assigned' => 9, 'completed' => 8],
                ['stage' => 'pattern_making', 'staff' => $matinaStaff, 'assigned' => 8, 'completed' => 7],
                ['stage' => 'cutting', 'staff' => $matinaStaff, 'assigned' => 7, 'completed' => 5],
                ['stage' => 'sewing', 'staff' => $matinaStaff, 'assigned' => 5, 'completed' => 3],
                ['stage' => 'qc_ironing', 'staff' => $matinaStaff, 'assigned' => 2, 'completed' => null],
            ],
            $jo9->id => [ // status: completed — all staff stages already closed out
                ['stage' => 'design', 'staff' => $staffUsers[1], 'assigned' => 7, 'completed' => 6],
                ['stage' => 'pattern_making', 'staff' => $staffUsers[1], 'assigned' => 6, 'completed' => 5],
                ['stage' => 'cutting', 'staff' => $staffUsers[1], 'assigned' => 5, 'completed' => 4],
                ['stage' => 'sewing', 'staff' => $staffUsers[1], 'assigned' => 4, 'completed' => 3],
            ],
            $jo10->id => [ // status: sewing — design/pattern_making/cutting done, sewing in progress
                ['stage' => 'design', 'staff' => $lanangStaff, 'assigned' => 3, 'completed' => 2],
                ['stage' => 'pattern_making', 'staff' => $lanangStaff, 'assigned' => 2, 'completed' => 1],
                ['stage' => 'cutting', 'staff' => $lanangStaff, 'assigned' => 1, 'completed' => null],
                ['stage' => 'sewing', 'staff' => $lanangStaff, 'assigned' => 0, 'completed' => null],
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
                'estimated_days' => 10,
                'material' => 'Chiffon & Tulle',
                'listing_type' => 'made_to_order',
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
                'estimated_days' => 14,
                'material' => 'Chiffon & Tulle',
                'listing_type' => 'made_to_order',
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
        // Bank Receipts" queue as the appointments seeded above. Walk-in
        // doesn't mean cash-only — a customer can still pay digitally before
        // coming in to collect a made-to-order piece.
        \App\Models\CatalogOrder::firstOrCreate(
            ['shop_id' => $shop->id, 'payment_reference' => 'GC-5563321190'],
            [
                'catalog_item_id' => $gown1->id,
                'customer_id' => $onlineCustomers['Cristina Ramos']->id,
                'type' => 'walkin',
                'status' => 'pending',
                'total_amount' => $gown1->price,
                'payment_status' => 'pending',
                'payment_method' => 'gcash',
                'intake_channel' => 'walk_in',
                'fulfillment_type' => 'pickup',
            ]
        );
        \App\Models\CatalogOrder::firstOrCreate(
            ['shop_id' => $shop->id, 'payment_reference' => 'BPI-77102256'],
            [
                'catalog_item_id' => $gown2->id,
                'customer_id' => $onlineCustomers['Liza Fernandez']->id,
                'type' => 'walkin',
                'status' => 'pending',
                'total_amount' => $gown2->price,
                'payment_status' => 'pending',
                'payment_method' => 'bank_transfer',
                'intake_channel' => 'walk_in',
                'fulfillment_type' => 'pickup',
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

        // Seed some walk-in CatalogOrders across different statuses/stages
        if ($item1 && isset($customers[0])) {
            // 1. Completed walk-in sale
            \App\Models\CatalogOrder::updateOrCreate(
                ['shop_id' => $shop->id, 'catalog_item_id' => $item1->id, 'customer_id' => $customers[0]->id, 'status' => 'completed'],
                [
                    'type' => 'walkin',
                    // Branch-attributed so Reports' branch comparison has a
                    // real per-branch walk-in-orders split from first seed,
                    // not everything piled into the "Unassigned" bucket.
                    'shop_branch_id' => $mainBranch->id,
                    'total_amount' => 4500.00,
                    'payment_status' => 'paid',
                    'payment_method' => 'gcash',
                    'intake_channel' => 'walk_in',
                    'fulfillment_type' => 'pickup',
                ]
            );

            // 2. Ready-for-pickup walk-in order, with a repeat-customer discount applied
            $discountedCatalogOrder = \App\Models\CatalogOrder::updateOrCreate(
                ['shop_id' => $shop->id, 'catalog_item_id' => $item1->id, 'customer_id' => $customers[0]->id, 'status' => 'ready'],
                [
                    'type' => 'walkin',
                    'shop_branch_id' => $branch2->id,
                    'total_amount' => 4200.00,
                    'discount_amount' => 300.00,
                    'payment_status' => 'paid',
                    'payment_method' => 'gcash',
                    'intake_channel' => 'walk_in',
                    'fulfillment_type' => 'pickup',
                ]
            );
            // Manual discount demo — mirrors CatalogOrderController::applyDiscount's
            // audit trail so the feature has a real example from first seed.
            \App\Models\AuditLog::firstOrCreate(
                ['shop_id' => $shop->id, 'model_type' => \App\Models\CatalogOrder::class, 'model_id' => $discountedCatalogOrder->id, 'action' => 'discount_applied'],
                [
                    'user_id' => $shop->owner_id,
                    'payload' => ['amount' => 300.00, 'reason' => 'Repeat customer — 4th order this year'],
                ]
            );

            // 3. Still being prepped
            \App\Models\CatalogOrder::updateOrCreate(
                ['shop_id' => $shop->id, 'catalog_item_id' => $item1->id, 'customer_id' => $customers[0]->id, 'status' => 'pending'],
                [
                    'type' => 'walkin',
                    'total_amount' => 4500.00,
                    'payment_status' => 'partial',
                    'payment_method' => 'gcash',
                    'intake_channel' => 'walk_in',
                    'fulfillment_type' => 'pickup',
                ]
            );
        }

        if ($item2 && isset($customers[1])) {
            // 4. Seed a walkin completed purchase
            \App\Models\CatalogOrder::updateOrCreate(
                ['shop_id' => $shop->id, 'catalog_item_id' => $item2->id, 'customer_id' => $customers[1]->id, 'type' => 'walkin', 'status' => 'completed'],
                [
                    'shop_branch_id' => $branch3->id,
                    'total_amount' => 1300.00,
                    'payment_status' => 'paid',
                    'payment_method' => 'cash',
                    'intake_channel' => 'walk_in',
                    'fulfillment_type' => 'pickup',
                ]
            );

            // 5. Seed a pending walk-in purchase paid via GCash, awaiting prep
            \App\Models\CatalogOrder::updateOrCreate(
                ['shop_id' => $shop->id, 'payment_reference' => 'GCASH-REF-884920194'],
                [
                    'catalog_item_id' => $item2->id,
                    'customer_id' => $customers[1]->id,
                    'type' => 'walkin',
                    'status' => 'pending',
                    'total_amount' => 650.00,
                    'payment_status' => 'pending',
                    'payment_method' => 'gcash',
                    'payment_receipt_path' => '/receipts/gcash-884920194.svg',
                    'intake_channel' => 'walk_in',
                    'fulfillment_type' => 'pickup',
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

        // 15. Seed a manual per-order discount example (replaces the old
        // Coupons/promo-code feature) — a one-time, in-the-moment discount
        // the owner grants a repeat customer, logged to the audit trail.
        // Mirrors JobOrderController::applyDiscount's effect: balance goes
        // down by the discount amount, discount_amount accumulates.
        if (isset($jo2)) {
            $jo2DiscountAmount = 500.00;
            $jo2->update([
                'balance' => max(0, (float) $jo2->balance - $jo2DiscountAmount),
                'discount_amount' => $jo2DiscountAmount,
            ]);
            \App\Models\AuditLog::firstOrCreate(
                ['shop_id' => $shop->id, 'model_type' => \App\Models\JobOrder::class, 'model_id' => $jo2->id, 'action' => 'discount_applied'],
                [
                    'user_id' => $shop->owner_id,
                    'payload' => ['amount' => $jo2DiscountAmount, 'reason' => 'Repeat customer — bulk jersey order'],
                ]
            );
        }

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
        \App\Models\SupportTicket::updateOrCreate(
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

        // 20. Seed Notifications — a shop this active (11 job orders, 40
        // staff stage assignments, 8 appointments, 4 payments) would
        // generate a real stream of in-app notifications, but every one of
        // those rows above was created by writing straight to Eloquent,
        // bypassing the controllers where notification-firing actually
        // lives (JobOrderController@store/@assignStaff, the payment/
        // appointment endpoints, etc.) — so the notifications table stayed
        // empty even on a freshly reseeded "realistic" demo shop, which
        // looked like a bug but was really just missing seed coverage.
        // Fire the real Notification classes against already-seeded data
        // so the format matches production exactly, then mark the older
        // half read and leave the newest unread — never all-read or
        // all-unread, and never delete any of them. notify() has no
        // built-in dedup (unlike the updateOrCreate/firstOrCreate calls
        // everywhere else in this file), so clear this seeder's own prior
        // batch first — otherwise re-running db:seed doubles them up.
        $owner->notifications()->delete();
        $staffUsers[0]->notifications()->delete();
        $lanangStaff->notifications()->delete();
        $matinaStaff->notifications()->delete();

        $owner->notify(new \App\Notifications\PaymentReceivedNotification($jo1, 7500.00));
        $owner->notify(new \App\Notifications\PaymentReceivedNotification($jo3, 12000.00));
        $owner->notify(new \App\Notifications\PaymentReceivedNotification($jo4, 3250.00));
        $owner->notify(new \App\Notifications\PaymentReceivedNotification($jo2, 3250.00));

        $staffUsers[0]->notify(new \App\Notifications\StaffAssignedNotification($jo1, 'sewing'));
        $lanangStaff->notify(new \App\Notifications\StaffAssignedNotification($jo10, 'sewing'));
        $matinaStaff->notify(new \App\Notifications\StaffAssignedNotification($jo8, 'qc_ironing'));

        $seededAppointments = \App\Models\Appointment::where('shop_id', $shop->id)->latest()->take(3)->get();
        foreach ($seededAppointments as $appt) {
            $owner->notify(new \App\Notifications\AppointmentBookedNotification($appt));
        }

        $owner->notify(new \App\Notifications\NewJobOrderNotification($jo9));
        $owner->notify(new \App\Notifications\NewJobOrderNotification($jo10));
        $owner->notify(new \App\Notifications\NewJobOrderNotification($jo11));

        // notify() stamps created_at as "now" for every call, and they all
        // land within the same script run (same second) — ordering by
        // created_at alone leaves ties broken arbitrarily by MySQL, which
        // silently marked some of the *newest* events (e.g. a just-created
        // job order) read while leaving an older payment unread. Order by
        // the auto-increment id instead — it's the one column that reliably
        // reflects actual insertion order — and stagger real created_at
        // values across it so "oldest half read, newest half unread" means
        // what it says.
        $ownerNotifications = $owner->notifications()->orderBy('id')->get();
        $total = $ownerNotifications->count();
        $readCutoff = (int) ceil($total / 2);
        foreach ($ownerNotifications as $i => $n) {
            $daysAgo = $total - $i;
            $n->created_at = now()->subDays($daysAgo);
            $n->updated_at = $n->created_at;
            if ($i < $readCutoff) {
                $n->read_at = $n->created_at->copy()->addHours(2);
            }
            $n->save();
        }

        // 21. Second Shop Owner account — a separate login for testing
        // multi-tenant isolation (does this shop ever leak Thread & Needle's
        // data or vice versa?) and lower-tier plan behavior (Basic here vs.
        // Premium above, e.g. the single-branch limit gate). Deliberately
        // light — one shop, one branch, no jobs/catalog — this is a login
        // fixture, not a second fully-populated demo shop.
        $owner2 = User::firstOrCreate(
            ['email' => 'owner2@sutura.com'],
            [
                'name' => 'Ana Bautista',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        if (!$owner2->roles()->where('role_id', $ownerRole->id)->exists()) {
            $owner2->roles()->attach($ownerRole->id);
        }

        $shop2 = Shop::firstOrCreate(
            ['owner_id' => $owner2->id],
            [
                'name' => 'Bautista Custom Tailors',
                'slug' => 'bautista-tailors',
                'description' => 'Everyday tailoring and school uniform specialists.',
                'address' => '45 Bonifacio Street',
                'city' => 'Davao City',
                'province' => 'Davao del Sur',
                'email' => 'hello@bautistatailors.com',
                'phone' => '+639111111111',
                'status' => 'approved',
                'approved_at' => now(),
                'operating_hours' => [
                    'monday' => ['is_open' => true, 'open' => '08:00', 'close' => '17:00'],
                    'tuesday' => ['is_open' => true, 'open' => '08:00', 'close' => '17:00'],
                    'wednesday' => ['is_open' => true, 'open' => '08:00', 'close' => '17:00'],
                    'thursday' => ['is_open' => true, 'open' => '08:00', 'close' => '17:00'],
                    'friday' => ['is_open' => true, 'open' => '08:00', 'close' => '17:00'],
                    'saturday' => ['is_open' => true, 'open' => '08:00', 'close' => '12:00'],
                    'sunday' => ['is_open' => false, 'open' => '08:00', 'close' => '17:00'],
                ],
            ]
        );

        $basicPlan = \App\Models\SubscriptionPlan::where('slug', 'basic')->first();
        if ($basicPlan && !$shop2->subscription()->exists()) {
            \App\Models\ShopSubscription::create([
                'shop_id' => $shop2->id,
                'plan_id' => $basicPlan->id,
                'status' => 'trial',
                'starts_at' => now(),
                'ends_at' => now()->addDays(30),
                'trial_ends_at' => now()->addDays(30),
            ]);
        }

        ShopBranch::firstOrCreate(
            ['shop_id' => $shop2->id, 'name' => 'Main Branch'],
            [
                'slug' => \Illuminate\Support\Str::slug('Main Branch') . '-' . uniqid(),
                'address' => '45 Bonifacio Street',
                'city' => 'Davao City',
                'latitude' => 7.0644,
                'longitude' => 125.6108,
                'contact_number' => '+63 911 111 1111',
                'is_main' => true,
            ]
        );

        // 22. Third Shop Owner account — completes one login per plan tier
        // (owner@ = Premium, owner2@ = Basic, owner3@ = Pro), so all three
        // subscription tiers have a real account to log into and test
        // against instead of only the two extremes.
        $owner3 = User::firstOrCreate(
            ['email' => 'owner3@sutura.com'],
            [
                'name' => 'Carlos Villanueva',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        if (!$owner3->roles()->where('role_id', $ownerRole->id)->exists()) {
            $owner3->roles()->attach($ownerRole->id);
        }

        $shop3 = Shop::firstOrCreate(
            ['owner_id' => $owner3->id],
            [
                'name' => 'Villanueva Bespoke Atelier',
                'slug' => 'villanueva-atelier',
                'description' => 'Formal wear and bridal atelier serving multiple branches.',
                'address' => '78 J.P. Laurel Avenue',
                'city' => 'Davao City',
                'province' => 'Davao del Sur',
                'email' => 'hello@villanuevaatelier.com',
                'phone' => '+639222222222',
                'status' => 'approved',
                'approved_at' => now(),
                'operating_hours' => [
                    'monday' => ['is_open' => true, 'open' => '10:00', 'close' => '19:00'],
                    'tuesday' => ['is_open' => true, 'open' => '10:00', 'close' => '19:00'],
                    'wednesday' => ['is_open' => true, 'open' => '10:00', 'close' => '19:00'],
                    'thursday' => ['is_open' => true, 'open' => '10:00', 'close' => '19:00'],
                    'friday' => ['is_open' => true, 'open' => '10:00', 'close' => '19:00'],
                    'saturday' => ['is_open' => true, 'open' => '10:00', 'close' => '19:00'],
                    'sunday' => ['is_open' => false, 'open' => '10:00', 'close' => '19:00'],
                ],
            ]
        );

        $proPlan = \App\Models\SubscriptionPlan::where('slug', 'pro')->first();
        if ($proPlan && !$shop3->subscription()->exists()) {
            \App\Models\ShopSubscription::create([
                'shop_id' => $shop3->id,
                'plan_id' => $proPlan->id,
                'status' => 'trial',
                'starts_at' => now(),
                'ends_at' => now()->addDays(30),
                'trial_ends_at' => now()->addDays(30),
            ]);
        }

        ShopBranch::firstOrCreate(
            ['shop_id' => $shop3->id, 'name' => 'Main Branch'],
            [
                'slug' => \Illuminate\Support\Str::slug('Main Branch') . '-' . uniqid(),
                'address' => '78 J.P. Laurel Avenue',
                'city' => 'Davao City',
                'latitude' => 7.0731,
                'longitude' => 125.6128,
                'contact_number' => '+63 922 222 2222',
                'is_main' => true,
            ]
        );
    }
}
