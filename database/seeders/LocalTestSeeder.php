<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\CatalogItem;
use App\Models\CatalogOrder;
use App\Models\JobOrder;
use App\Models\JobOrderStaff;
use App\Models\Measurement;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\ServicePricing;
use App\Models\StaffProfile;
use App\Models\Store;
use App\Models\StoreBranch;
use App\Models\StorePost;
use App\Models\StoreReview;
use App\Models\StoreSpecialHour;
use App\Models\StoreSubscription;
use App\Models\SubscriptionPlan;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\User;
use App\Notifications\AppointmentBookedNotification;
use App\Notifications\AppointmentStatusNotification;
use App\Notifications\JobStatusUpdatedNotification;
use App\Notifications\NewJobOrderNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Support\CanonicalTaxonomy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LocalTestSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('name', 'admin')->first();
        $ownerRole = Role::where('name', 'store_owner')->first();
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
        if (! $admin->roles()->where('role_id', $adminRole->id)->exists()) {
            $admin->roles()->attach($adminRole->id);
        }

        // 2. Create YOU (The Store Owner)
        $owner = User::firstOrCreate(
            ['email' => 'maria.cruz@gmail.com'],
            [
                'name' => 'Maria Cruz',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        if (! $owner->roles()->where('role_id', $ownerRole->id)->exists()) {
            $owner->roles()->attach($ownerRole->id);
        }

        // 3. Create your Pre-Approved Store
        $store = Store::firstOrCreate(
            ['owner_id' => $owner->id],
            [
                'name' => 'Thread & Needle Tailoring',
                'slug' => 'thread-needle',
                'store_code' => 'TNED',
                'description' => 'Premium tailoring and bespoke design services.',
                'specializations' => CanonicalTaxonomy::storeSpecializations(),
                'address' => '123 Rizal Avenue',
                'city' => 'Davao City',
                'province' => 'Davao del Sur',
                'email' => 'hello@threadneedle.com',
                // Real values so the public booking page's payment step has
                // something real to show instead of a hardcoded placeholder.
                'gcash_number' => '0917 123 4567',
                'gcash_account_name' => 'Thread & Needle Tailoring',
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

        // Assign Trial Subscription to Store (Default to Premium plan)
        $premiumPlan = SubscriptionPlan::where('slug', 'premium')->first();
        if ($premiumPlan && ! $store->subscription()->exists()) {
            StoreSubscription::create([
                'store_id' => $store->id,
                'plan_id' => $premiumPlan->id,
                'status' => 'trial',
                'starts_at' => now(),
                'ends_at' => now()->addDays(30),
                'trial_ends_at' => now()->addDays(30),
            ]);
        }

        // Seed Main Branch
        $mainBranch = StoreBranch::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Poblacion Branch (Main)'],
            [
                'slug' => Str::slug('Poblacion Branch (Main)').'-'.uniqid(),
                'address' => '123 Rizal Avenue, Poblacion',
                'city' => 'Davao City',
                // Real Davao City administrative district for this real
                // street/coordinate pair -- not a fabricated value. Rizal
                // Avenue sits in the Poblacion district (downtown Davao).
                'district' => 'Poblacion',
                'latitude' => 7.0702,
                'longitude' => 125.6077,
                'contact_number' => '+63 917 100 0001',
                'guide_image_url' => Storage::url('banners/thread_needle_banner.jpg'),
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
        if (! $staff->roles()->where('role_id', $staffRole->id)->exists()) {
            $staff->roles()->attach($staffRole->id);
        }
        // The demo "Staff" login must stay plain staff (it's listed as Staff in the
        // README / HowToOpen). If it was promoted while testing, a re-seed undoes it.
        if ($bmRole = \App\Models\Role::where('name', 'branch_manager')->first()) {
            $staff->roles()->detach($bmRole->id);
        }
        $staff->staffProfile()->update(['is_branch_manager' => false]);

        // Link the staff to the store branch via StaffProfile — this demo staff
        // member covers two roles (head tailor + sublimation), demonstrating
        // the ranked additional_roles feature rather than needing a second
        // duplicate-named account for the same person.
        if (! $staff->staffProfile()->exists()) {
            StaffProfile::create([
                'user_id' => $staff->id,
                'store_id' => $store->id,
                'store_branch_id' => $mainBranch->id,
                'role' => 'head_tailor',
                'additional_roles' => ['sublimation_specialist'],
            ]);
        }

        // 4b. Branch Manager demo login — kept separate from staff@sutura.com (which is plain
        // Staff) so testers can compare the two roles side by side.
        $branchManagerRole = Role::where('name', 'branch_manager')->first();
        $manager = User::firstOrCreate(
            ['email' => 'manager@sutura.com'],
            ['name' => 'Miguel Manager', 'password' => Hash::make('password'), 'email_verified_at' => now()]
        );
        if ($branchManagerRole && ! $manager->roles()->where('role_id', $branchManagerRole->id)->exists()) {
            $manager->roles()->attach($branchManagerRole->id);
        }
        if (! $manager->staffProfile()->exists()) {
            StaffProfile::create([
                'user_id' => $manager->id,
                'store_id' => $store->id,
                'store_branch_id' => $mainBranch->id,
                'role' => 'head_tailor',
                'is_branch_manager' => true,
            ]);
        }

        // 5. Update Store Branding (Profile Picture/Logo, Bio/Description)
        // Was hardcoded to http://127.0.0.1:8000/... -- only ever resolved on
        // one specific machine with `php artisan serve` running on that exact
        // port. Storage::url() generates the correct host for wherever this
        // actually runs (local, Postgres test, or real production), same
        // fix as FileUploadController's UPLOAD_DISK bug.
        $store->update([
            'logo_path' => Storage::url('logos/thread_needle_logo.jpg'),
            'banner_path' => Storage::url('banners/thread_needle_banner.jpg'),
            'description' => "Davao City's premier provider of full sublimation jerseys, corporate uniforms, and custom tailoring.",
            // Re-applied on every run (firstOrCreate above only sets it on a
            // brand-new store) so an existing DB picks up the canonical
            // Men/Women/Kids + Services specializations too.
            'specializations' => CanonicalTaxonomy::storeSpecializations(),
        ]);

        // 6. Seed separated services (Sublimation Jerseys & Bespoke Suits)
        // Service 1: Custom Sublimation Team Jerseys
        $service1 = Service::updateOrCreate(
            [
                'store_id' => $store->id,
                'name' => 'Custom Sublimation Team Jerseys',
            ],
            [
                'description' => 'Full sublimation jerseys using high-quality drifit fabrics. Perfect for sports teams, tournaments, and athletic wear. Price varies based on quantity, fabric (Mesh, Honeycomb), and design complexity.',
                'category' => 'Sublimation & Digital Printing',
                // 'office' isn't a valid department anymore (Categories.md
                // demotes Office to a filter, not a department) — team
                // jerseys are gender-neutral, so left unset here rather than
                // guessing men/women.
                'department' => null,
                'categories' => ['Custom Jersey Printing', 'Corporate & Team Uniforms'],
                'service_category' => 'printing_sublimation',
                'service_leaf_type' => 'sports_jersey_printing',
                'service_types' => ['bulk_sublimation'],
                'base_price' => 1000,
                'min_order_qty' => 10,
                'estimated_days' => 14,
                'is_active' => true,
                'image_url' => '/catalog/custom-sublimation-jerseys-service.jpg',
                'custom_fields' => [
                    [
                        'name' => 'fabric_preference',
                        'label' => 'Fabric Preference',
                        'type' => 'dropdown',
                        'required' => true,
                        'options' => ['Drifit', 'Cotton', 'Honeycomb'],
                    ],
                    [
                        'name' => 'team_name',
                        'label' => 'Team/Organization Name',
                        'type' => 'short_text',
                        'required' => true,
                    ],
                    [
                        'name' => 'roster',
                        'label' => 'Player Name & Number Roster',
                        'type' => 'short_text',
                        'required' => true,
                    ],
                    [
                        'name' => 'size_breakdown',
                        'label' => 'Size Breakdown (e.g. S-5, M-10, L-2)',
                        'type' => 'short_text',
                        'required' => true,
                    ],
                ],
            ]
        );

        // Service 2: Bespoke Suit Tailoring
        $service2 = Service::updateOrCreate(
            [
                'store_id' => $store->id,
                'name' => 'Bespoke Suit Tailoring',
            ],
            [
                'description' => 'Premium bespoke custom suits tailored to your exact measurements with premium fabrics, lining, and custom details. Price varies based on wool quality and lining.',
                'category' => 'Custom Tailoring & Bespoke',
                'department' => 'men',
                'categories' => ['Suit & Tuxedo Tailoring', 'Formal & Cultural Wear'],
                'service_category' => 'custom_tailoring',
                'service_leaf_type' => 'formal_wear_tailoring',
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
                ServicePricing::updateOrCreate(
                    ['service_id' => $serviceId, 'label' => $tier['label']],
                    ['amount' => $tier['amount']]
                );
            }
            Service::find($serviceId)->update([
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
                'description' => 'Classic Filipiniana formal wear, hand-tailored to fit. Choose from plain cotton, jusi, or premium piña fabric. Includes one fitting session before final delivery.',
                'department' => 'men',
                'categories' => ['Barong Tagalog Tailoring', 'Formal & Cultural Wear'],
                'service_category' => 'custom_tailoring',
                'service_leaf_type' => 'traditional_wear_tailoring',
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
                // 'wedding' isn't a valid department anymore (Categories.md
                // demotes Wedding to a filter, not a department) — bridal
                // gowns are women's formal wear.
                'department' => 'women',
                'categories' => ['Custom Bridal Tailoring', 'Gown & Evening Wear Designing', 'Formal & Cultural Wear'],
                'service_category' => 'custom_tailoring',
                'service_leaf_type' => 'formal_wear_tailoring',
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
                'department' => null,
                'categories' => ['School Uniforms', 'Institutional & Uniform Wear', 'Corporate & Team Uniforms'],
                'service_category' => 'uniform_production',
                'service_leaf_type' => 'school_uniforms',
                'service_types' => ['bulk_sublimation'],
                'base_price' => null,
                'estimated_days' => 20,
                'image_url' => '/catalog/school-uniforms.jpg',
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
                'service_category' => 'alterations_repairs',
                'service_leaf_type' => 'garment_repair_restoration',
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
                'department' => null,
                'categories' => ['Custom Jersey Printing', 'Corporate & Team Uniforms'],
                'service_category' => 'printing_sublimation',
                'service_leaf_type' => 'teamwear_printing',
                'service_types' => ['bulk_sublimation'],
                'base_price' => null,
                'estimated_days' => 12,
                'image_url' => '/catalog/volleyball-jersey-sublimation.avif',
                'tiers' => [
                    ['label' => 'Basic Jersey Set (Top + Shorts)', 'amount' => 850],
                    ['label' => 'Full Sublimation Premium Set', 'amount' => 1200],
                ],
            ],
            [
                'name' => 'Embroidery & Logo Digitizing',
                'description' => 'Custom embroidery for logos, names, and designs on garments, uniforms, jackets, and accessories. New logo designs include one-time digitizing to convert artwork into a stitchable file.',
                'department' => null,
                'categories' => ['Embroidered Logos & Team Names', 'Custom Apparel, Printing & Embroidery'],
                'service_category' => 'printing_sublimation',
                'service_leaf_type' => 'custom_apparel_printing',
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
            [
                // Covers the Services > Custom Costume Creation header menu,
                // which otherwise led to an empty results page.
                'name' => 'Cultural & Stage Costume Tailoring',
                'description' => 'Made-to-order costumes for school programs, cultural dance troupes, and stage productions — Buwan ng Wika attire, folk dance ensembles, and character costumes.',
                'department' => null,
                'categories' => ['Costume Tailoring', 'Formal & Cultural Wear'],
                'service_category' => 'custom_costume_creation',
                'service_leaf_type' => 'cultural_dance_costumes',
                'service_types' => ['custom_tailoring'],
                'base_price' => null,
                'estimated_days' => 14,
                'image_url' => '/images/categories/filipiniana.jpg',
                'tiers' => [
                    ['label' => 'Buwan ng Wika Attire (Kids)', 'amount' => 900],
                    ['label' => 'Folk Dance Costume Set', 'amount' => 1800],
                    ['label' => 'Stage / Character Costume', 'amount' => 2500],
                ],
            ],
        ];

        foreach ($extraServices as $svc) {
            $tiers = $svc['tiers'];
            unset($svc['tiers']);
            $svc['tags'] = array_column($tiers, 'label');

            $newService = Service::updateOrCreate(
                ['store_id' => $store->id, 'name' => $svc['name']],
                $svc + ['is_active' => true]
            );

            foreach ($tiers as $tier) {
                ServicePricing::updateOrCreate(
                    ['service_id' => $newService->id, 'label' => $tier['label']],
                    ['amount' => $tier['amount']]
                );
            }
        }

        // 7. Single-branch shop: Thread & Needle runs from Poblacion (Main)
        // only. The old Lanang/Matina satellites are retired — their orders
        // and appointments move to Main (never nulled out), then the branch
        // rows go. $branch2/$branch3 stay as aliases of Main so the demo
        // jobs/appointments below still seed, just all at Main.
        $retiredBranchIds = StoreBranch::where('store_id', $store->id)
            ->whereIn('name', ['Lanang Branch', 'Matina Branch'])
            ->pluck('id');
        if ($retiredBranchIds->isNotEmpty()) {
            foreach ([Appointment::class, CatalogOrder::class, JobOrder::class, StaffProfile::class, StoreSpecialHour::class] as $model) {
                $model::withoutGlobalScopes()
                    ->whereIn('store_branch_id', $retiredBranchIds)
                    ->update(['store_branch_id' => $mainBranch->id]);
            }
            StoreBranch::whereIn('id', $retiredBranchIds)->delete();
        }
        $branch2 = $mainBranch;
        $branch3 = $mainBranch;

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
            if (! $sUser->roles()->where('role_id', $staffRole->id)->exists()) {
                $sUser->roles()->attach($staffRole->id);
            }

            if (! $sUser->staffProfile()->exists()) {
                StaffProfile::create([
                    'user_id' => $sUser->id,
                    'store_id' => $store->id,
                    'store_branch_id' => $mainBranch->id,
                    'role' => $sData['role'],
                ]);
            }
            $staffUsers[] = $sUser;
        }

        // Extra staff to spread the demo job stages across — prefers the two
        // invite-flow accounts if they exist, otherwise Main Branch staffers.
        $lanangStaff = User::where('email', 'ferdinand.cruz@sutura.com')->first() ?? $staffUsers[1];
        $matinaStaff = User::where('email', 'rowena.aquino@sutura.com')->first() ?? $staffUsers[0];

        // 9. Seed 3 Customers
        $customerRole = Role::where('name', 'customer')->first();
        $customers = [];
        $customerNames = [
            ['email' => 'customer@sutura.com', 'name' => 'Juan dela Cruz'],
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
            if (! $cUser->roles()->where('role_id', $customerRole->id)->exists()) {
                $cUser->roles()->attach($customerRole->id);
            }

            // Sync with store customers
            $store->customers()->syncWithoutDetaching([$cUser->id]);
            $customers[] = $cUser;
        }

        // 9a. Booking testers — customers with NO appointments. Every customer above already holds
        // an active appointment at this store, and a customer may only have one, so these are the
        // logins for trying "Book an Appointment" from the store, a design, a service or a package.
        foreach ([['booking.tester1@sutura.com', 'Tess Tester'], ['booking.tester2@sutura.com', 'Tomas Tester']] as [$tEmail, $tName]) {
            $tester = User::firstOrCreate(
                ['email' => $tEmail],
                ['name' => $tName, 'password' => Hash::make('password'), 'email_verified_at' => now()]
            );
            if (! $tester->roles()->where('role_id', $customerRole->id)->exists()) {
                $tester->roles()->attach($customerRole->id);
            }
            $store->customers()->syncWithoutDetaching([$tester->id]);
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
            if (! $cUser->roles()->where('role_id', $customerRole->id)->exists()) {
                $cUser->roles()->attach($customerRole->id);
            }
            $store->customers()->syncWithoutDetaching([$cUser->id]);
            $onlineCustomers[$cData['name']] = $cUser;
        }

        // 10. Seed 4 Appointments
        $service1 = Service::where('name', 'Custom Sublimation Team Jerseys')->first();
        $service2 = Service::where('name', 'Bespoke Suit Tailoring')->first();

        Appointment::updateOrCreate(
            [
                'store_id' => $store->id,
                'customer_id' => $customers[0]->id,
                'service_id' => $service2->id,
                'status' => 'confirmed',
                'notes' => 'Bespoke suit fitting session.',
            ],
            [
                'store_branch_id' => $mainBranch->id,
                'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
                'appointment_type' => 'consultation',
                'intake_channel' => 'walk_in',
                'duration_minutes' => 60,
            ]
        );

        Appointment::updateOrCreate(
            [
                'store_id' => $store->id,
                'customer_id' => $customers[1]->id,
                'service_id' => $service1->id,
                'status' => 'pending',
                'notes' => 'Design discussion for basketball jerseys.',
            ],
            [
                'store_branch_id' => $branch2->id,
                'scheduled_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
                'appointment_type' => 'consultation',
                'intake_channel' => 'walk_in',
                'duration_minutes' => 60,
            ]
        );

        Appointment::updateOrCreate(
            [
                'store_id' => $store->id,
                'customer_id' => $customers[2]->id,
                'service_id' => $service2->id,
                'status' => 'completed',
                'notes' => 'Initial consultation completed.',
            ],
            [
                'store_branch_id' => $branch3->id,
                'scheduled_at' => now()->subDays(1)->format('Y-m-d H:i:s'),
                'appointment_type' => 'consultation',
                'intake_channel' => 'walk_in',
                'duration_minutes' => 60,
            ]
        );

        Appointment::updateOrCreate(
            [
                'store_id' => $store->id,
                'customer_id' => $customers[0]->id,
                'service_id' => $service1->id,
                'status' => 'cancelled',
                'notes' => 'Cancelled by customer.',
            ],
            [
                'store_branch_id' => $mainBranch->id,
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
        Appointment::updateOrCreate(
            ['store_id' => $store->id, 'payment_reference' => 'GC-2201394857'],
            [
                'customer_id' => $onlineCustomers['Liza Fernandez']->id,
                'service_id' => $service2->id,
                'store_branch_id' => $mainBranch->id,
                'appointment_type' => 'consultation',
                'intake_channel' => 'online',
                'scheduled_at' => now()->addDays(2),
                'duration_minutes' => 60,
                'status' => 'pending',
                'payment_method' => 'gcash',
                'payment_status' => 'pending',
            ]
        );

        $bridalService = Service::where('name', 'Bridal & Wedding Gown Design')->first();
        Appointment::updateOrCreate(
            ['store_id' => $store->id, 'payment_reference' => 'BDO-88213340'],
            [
                'customer_id' => $onlineCustomers['Mark Villanueva']->id,
                'service_id' => $bridalService?->id,
                'store_branch_id' => $branch2->id,
                'appointment_type' => 'fitting',
                'intake_channel' => 'online',
                'scheduled_at' => now()->addDays(4),
                'duration_minutes' => 60,
                'status' => 'pending',
                'payment_method' => 'gcash',
                'payment_status' => 'pending',
            ]
        );

        $alterationService = Service::where('name', 'Garment Alterations & Repair Services')->first();
        Appointment::updateOrCreate(
            ['store_id' => $store->id, 'payment_reference' => 'GC-3390215671'],
            [
                'customer_id' => $onlineCustomers['Cristina Ramos']->id,
                'service_id' => $alterationService?->id,
                'store_branch_id' => $branch3->id,
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
        $jo1 = JobOrder::firstOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0001'],
            [
                'tracking_code' => 'TNED8K2P',
                'store_branch_id' => $mainBranch->id,
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

        $jo2 = JobOrder::updateOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0002'],
            [
                'tracking_code' => 'TNED4M7B',
                'store_branch_id' => $branch2->id,
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
                    ],
                ],
            ]
        );

        $jo3 = JobOrder::updateOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0003'],
            [
                'tracking_code' => 'TNED9X3A',
                'store_branch_id' => $branch3->id,
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

        $jo4 = JobOrder::firstOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0004'],
            [
                'tracking_code' => 'TNED2V5H',
                'store_branch_id' => $mainBranch->id,
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
                    ],
                ],
            ]
        );

        // 11a. Seed 3 more Job Orders exercising the new 3-Phase Tailoring
        // Tracker statuses introduced by the pipeline redesign (Ready for
        // Fitting/Final Adjustments/QC & Ironing/the bulk-order Mass Cutting
        // & Printing override) so every Kanban column has at least one card.
        $jo5 = JobOrder::firstOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0005'],
            [
                'tracking_code' => 'TNED7N4K',
                'store_branch_id' => $mainBranch->id,
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
        Appointment::firstOrCreate(
            ['store_id' => $store->id, 'job_order_id' => $jo5->id, 'appointment_type' => 'fitting'],
            [
                'store_branch_id' => $mainBranch->id,
                'customer_id' => $jo5->customer_id,
                'intake_channel' => 'walk_in',
                'scheduled_at' => now()->addDay()->setTime(10, 0),
                'duration_minutes' => Appointment::TYPE_DEFAULT_DURATIONS['fitting'],
                'status' => 'pending',
                'notes' => "Auto-generated when Job Order {$jo5->order_number} became Ready for Fitting — please confirm the actual date/time with the customer.",
            ]
        );

        $jo6 = JobOrder::updateOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0006'],
            [
                'tracking_code' => 'TNED6W9E',
                'store_branch_id' => $branch2->id,
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

        $jo7 = JobOrder::firstOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0007'],
            [
                'tracking_code' => 'TNED3P8T',
                'store_branch_id' => $mainBranch->id,
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

        $jo8 = JobOrder::updateOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0008'],
            [
                'tracking_code' => 'TNED5R2Y',
                'store_branch_id' => $branch3->id,
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
        $jo9 = JobOrder::updateOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0009'],
            [
                'tracking_code' => 'TNED8L3X',
                'store_branch_id' => $mainBranch->id,
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

        $jo10 = JobOrder::updateOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0010'],
            [
                'tracking_code' => 'TNED2J7M',
                'store_branch_id' => $branch2->id,
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

        // jo2 (ORD-0002) starts unpaid above but receives a partial payment
        // later in this same seeder (a deliberate "started unpaid, later
        // paid something" scenario) — so nothing genuinely stays unpaid
        // without this one, leaving the "no downpayment collected" alert
        // permanently empty on every reseed.
        $jo11 = JobOrder::updateOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0011'],
            [
                'tracking_code' => 'TNED9B6S',
                'store_branch_id' => $mainBranch->id,
                'customer_id' => $customers[1]->id,
                'service_id' => $service1->id,
                'assigned_staff_id' => $staffUsers[2]->id,
                'total_amount' => 5000.00,
                'balance' => 5000.00,
                'payment_status' => 'unpaid',
                'status' => 'pending',
                'due_date' => now()->addDays(9)->format('Y-m-d'),
                'notes' => 'Full Sublimation Team Jerseys (5 sets). Red & Gold gradient print with team logo on left chest. Fabric: Drifit Honeycomb. Double-stitched seams on collar and armholes.',
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
                JobOrderStaff::updateOrCreate(
                    ['job_order_id' => $jobOrderId, 'user_id' => $s['staff']->id, 'stage' => $s['stage']],
                    [
                        'assigned_at' => now()->subDays($s['assigned']),
                        'completed_at' => $s['completed'] === null ? null : now()->subDays($s['completed']),
                    ]
                );
            }
        }

        // 12. Seed 4 Payments
        Payment::firstOrCreate(
            ['job_order_id' => $jo1->id, 'amount' => 7500.00],
            [
                'payment_method' => 'gcash',
                'recorded_by' => $owner->id,
                'notes' => 'Downpayment for custom suit.',
            ]
        );

        Payment::firstOrCreate(
            ['job_order_id' => $jo3->id, 'amount' => 12000.00],
            [
                'payment_method' => 'gcash',
                'recorded_by' => $owner->id,
                'notes' => 'Full payment.',
            ]
        );

        Payment::firstOrCreate(
            ['job_order_id' => $jo4->id, 'amount' => 3250.00],
            [
                'payment_method' => 'cash',
                'recorded_by' => $owner->id,
                'notes' => 'Settled in cash.',
            ]
        );

        Payment::firstOrCreate(
            ['job_order_id' => $jo2->id, 'amount' => 3250.00],
            [
                'payment_method' => 'gcash',
                'recorded_by' => $owner->id,
                'notes' => 'Partial deposit via GCash.',
            ]
        );

        // Update ORD-0002 balance after payment
        $jo2->update([
            'balance' => 3250.00,
            'payment_status' => 'partial',
        ]);

        // 13. Catalog Items are now dynamically seeded via CatalogItemsSeeder from your custom images folder.

        // 14. Seed 3 Store Reviews
        StoreReview::firstOrCreate(
            ['store_id' => $store->id, 'user_id' => $customers[0]->id],
            [
                'rating' => 5,
                'comment' => 'Exceptional quality. The bespoke suit fits perfectly.',
                'is_featured' => true,
            ]
        );

        StoreReview::firstOrCreate(
            ['store_id' => $store->id, 'user_id' => $customers[1]->id],
            [
                'rating' => 5,
                'comment' => 'Fast turnaround and high-quality sublimation jerseys. Highly recommended!',
                'is_featured' => true,
            ]
        );

        StoreReview::firstOrCreate(
            ['store_id' => $store->id, 'user_id' => $customers[2]->id],
            [
                'rating' => 4,
                'comment' => 'Very professional tailor, although scheduling the fitting session took some time. Overall great experience.',
                'is_featured' => false,
            ]
        );

        $this->call(CatalogItemsSeeder::class);

        // 13b. The two gowns the GCash/Bank Transfer catalog-order demo below
        // uses. These used to be created here as their own rows ("Off-Shoulder
        // Floral Tulle A-Line Gown", "Long-Train Wedding Gown"), but each was
        // the same photo as an item CatalogItemsSeeder already creates — two
        // listings for one design. Look up the canonical items instead;
        // CatalogItemsSeeder merges the old duplicate rows into these.
        $gown1 = CatalogItem::where('store_id', $store->id)
            ->where('name', CatalogItemsSeeder::ANDREA_LEO_NAME)->firstOrFail();
        $gown2 = CatalogItem::where('store_id', $store->id)
            ->where('name', 'Long-Tail White Wedding Gown')->firstOrFail();

        // 13c. Seed 2 pending catalog orders paid via GCash/Bank Transfer,
        // awaiting the owner's manual receipt verification — same "GCash &
        // Bank Receipts" queue as the appointments seeded above. Walk-in
        // doesn't mean cash-only — a customer can still pay digitally before
        // coming in to collect a made-to-order piece.
        CatalogOrder::firstOrCreate(
            ['store_id' => $store->id, 'payment_reference' => 'GC-5563321190'],
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
        CatalogOrder::firstOrCreate(
            ['store_id' => $store->id, 'payment_reference' => 'BPI-77102256'],
            [
                'catalog_item_id' => $gown2->id,
                'customer_id' => $onlineCustomers['Liza Fernandez']->id,
                'type' => 'walkin',
                'status' => 'pending',
                'total_amount' => $gown2->price,
                'payment_status' => 'pending',
                'payment_method' => 'gcash',
                'intake_channel' => 'walk_in',
                'fulfillment_type' => 'pickup',
            ]
        );

        // Link seeded job orders to catalog items to show earnings/performance data
        $item1 = CatalogItem::where('name', CatalogItemsSeeder::ANDREA_LEO_NAME)->first();
        $item2 = CatalogItem::where('name', 'Pro-Fit Cycling Jersey - Team Kit')->first();

        // Two of the online bookings seeded in step 10b were made from a
        // design's own "Book an Appointment" button — link them to that
        // design (plus the size/color picked on its page), the same way
        // PublicBookingController records a real one. Done here rather than
        // in 10b because the catalog doesn't exist yet at that point.
        $suitDesign = CatalogItem::where('store_id', $store->id)
            ->where('name', 'Bespoke Two-Piece Suit - Charcoal Wool')->first();
        Appointment::where('store_id', $store->id)->where('payment_reference', 'GC-2201394857')
            ->update(['catalog_item_id' => $suitDesign?->id, 'selected_size' => '40', 'selected_color' => 'Black']);
        Appointment::where('store_id', $store->id)->where('payment_reference', 'BDO-88213340')
            ->update(['catalog_item_id' => $item1?->id, 'selected_size' => 'M', 'selected_color' => 'Sky Blue']);

        if ($item1 && isset($jo1)) {
            $jo1->update(['catalog_item_id' => $item1->id]);
        }
        if ($item2 && isset($jo2)) {
            $jo2->update(['catalog_item_id' => $item2->id]);
        }

        // Seed some walk-in CatalogOrders across different statuses/stages
        if ($item1 && isset($customers[0])) {
            // 1. Completed walk-in sale
            CatalogOrder::updateOrCreate(
                ['store_id' => $store->id, 'catalog_item_id' => $item1->id, 'customer_id' => $customers[0]->id, 'status' => 'completed'],
                [
                    'type' => 'walkin',
                    // Branch-attributed so Reports' branch comparison has a
                    // real per-branch walk-in-orders split from first seed,
                    // not everything piled into the "Unassigned" bucket.
                    'store_branch_id' => $mainBranch->id,
                    'total_amount' => 4500.00,
                    'payment_status' => 'paid',
                    'payment_method' => 'gcash',
                    'intake_channel' => 'walk_in',
                    'fulfillment_type' => 'pickup',
                ]
            );

            // 2. Ready-for-pickup walk-in order, paid in full at the listed price.
            CatalogOrder::updateOrCreate(
                ['store_id' => $store->id, 'catalog_item_id' => $item1->id, 'customer_id' => $customers[0]->id, 'status' => 'ready'],
                [
                    'type' => 'walkin',
                    'store_branch_id' => $branch2->id,
                    'total_amount' => 4500.00,
                    'discount_amount' => 0,
                    'payment_status' => 'paid',
                    'payment_method' => 'gcash',
                    'intake_channel' => 'walk_in',
                    'fulfillment_type' => 'pickup',
                ]
            );
            // 3. Still being prepped, GCash payment awaiting owner verification.
            // 'partial' isn't a real CatalogOrder payment_status value — that's
            // a JobOrder-only concept (unpaid/partial/paid); CatalogOrderController
            // only ever validates pending/paid/rejected for this model. A seeder
            // writing 'partial' here bypassed that validation and produced a
            // state the real app can never create through any endpoint.
            CatalogOrder::updateOrCreate(
                ['store_id' => $store->id, 'catalog_item_id' => $item1->id, 'customer_id' => $customers[0]->id, 'status' => 'pending'],
                [
                    'type' => 'walkin',
                    'total_amount' => 4500.00,
                    'payment_status' => 'pending',
                    'payment_method' => 'gcash',
                    'intake_channel' => 'walk_in',
                    'fulfillment_type' => 'pickup',
                ]
            );
        }

        if ($item2 && isset($customers[1])) {
            // 4. Seed a walkin completed purchase
            CatalogOrder::updateOrCreate(
                ['store_id' => $store->id, 'catalog_item_id' => $item2->id, 'customer_id' => $customers[1]->id, 'type' => 'walkin', 'status' => 'completed'],
                [
                    'store_branch_id' => $branch3->id,
                    'total_amount' => 1300.00,
                    'payment_status' => 'paid',
                    'payment_method' => 'cash',
                    'intake_channel' => 'walk_in',
                    'fulfillment_type' => 'pickup',
                ]
            );

            // 5. Seed a pending walk-in purchase paid via GCash, awaiting prep
            CatalogOrder::updateOrCreate(
                ['store_id' => $store->id, 'payment_reference' => 'GCASH-REF-884920194'],
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
        StoreSpecialHour::updateOrCreate(
            ['store_id' => $store->id, 'title' => 'Christmas Break 2026'],
            [
                'start_date' => '2026-12-24',
                'end_date' => '2026-12-26',
                'is_closed' => true,
                'announcement_message' => 'Merry Christmas! Thread & Needle will be fully closed from December 24 to 26 to celebrate the holidays with our families. Online bookings on these dates are disabled.',
            ]
        );

        StoreSpecialHour::updateOrCreate(
            ['store_id' => $store->id, 'title' => 'Staff Planning Day'],
            [
                'start_date' => '2026-07-04',
                'end_date' => '2026-07-04',
                'is_closed' => false,
                'special_open_time' => '10:00:00',
                'special_close_time' => '15:00:00',
                'announcement_message' => 'We are having our annual Staff Planning Day on July 4. Custom hours apply: 10:00 AM - 3:00 PM.',
            ]
        );

        // 15. No discounts in the demo data. Earlier seeds applied a repeat-customer
        // discount to one walk-in order and one job order; undo those on re-seed
        // (restore the balance, drop the audit entries) so the numbers match the
        // listed prices. Only the seeded job order is touched, never real ones.
        if (isset($jo2) && (float) $jo2->discount_amount === 500.00) {
            $jo2->update([
                'balance' => (float) $jo2->balance + 500.00,
                'discount_amount' => 0,
            ]);
        }
        CatalogOrder::where('store_id', $store->id)->where('discount_amount', '>', 0)->get()->each(function (CatalogOrder $order) {
            // Walk-in totals are stored net of discount, so add it back.
            $order->update([
                'total_amount' => (float) $order->total_amount + (float) $order->discount_amount,
                'discount_amount' => 0,
            ]);
        });
        AuditLog::where('store_id', $store->id)->where('action', 'discount_applied')->delete();

        // 16. Seed a Service Package (bundle) — combines two existing services.
        $barongService = Service::where('name', 'Barong Tagalog Tailoring')->first();
        if ($barongService && isset($service2)) {
            $package = ServicePackage::updateOrCreate(
                ['store_id' => $store->id, 'name' => 'Groom & Entourage Package'],
                [
                    'description' => 'Bespoke suit for the groom plus Barong Tagalog tailoring for the entourage, bundled as one package.',
                    'bundle_price' => 12000,
                    'is_active' => true,
                    'image_url' => '/catalog/groom-entourage-package.jpg',
                ]
            );
            $package->services()->syncWithoutDetaching([$service2->id, $barongService->id]);
        }

        // 17. Seed Store Posts (completed-work showcase) — otherwise "Our Work"
        // never appears on the public storefront (it only shows once posts exist).
        StorePost::updateOrCreate(
            ['store_id' => $store->id, 'caption' => 'Delivered this custom barong for a client\'s wedding — jusi fabric with hand embroidery on the collar. Order placed 2 weeks ago, released today!'],
            [
                'service_id' => $barongService?->id,
                'image_urls' => ['https://images.unsplash.com/photo-1602810318383-e386cc2a3ccf?q=80&w=800&auto=format&fit=crop'],
            ]
        );
        StorePost::updateOrCreate(
            ['store_id' => $store->id, 'caption' => 'Another satisfied customer picking up their bespoke suit today. Full canvas construction, tailored over 3 fitting sessions.'],
            [
                'service_id' => $service2->id ?? null,
                'image_urls' => ['https://images.unsplash.com/photo-1594938298603-c8148c4dae35?q=80&w=800&auto=format&fit=crop'],
            ]
        );

        // 18. Seed Measurement profiles — otherwise a customer's "Measurements &
        // Specs" tab is always empty, even though the whole point of the
        // measurement repository is to show it's populated and reusable.
        //
        // Jose Rizal's "Bespoke Suit Tailoring" profile — one combined
        // record (the jacket/trouser Top/Bottom split this used to be seeded
        // with was removed), 2 versions demonstrating the version-history
        // feature: an older superseded snapshot and the current one.
        Measurement::updateOrCreate(
            ['store_id' => $store->id, 'customer_id' => $customers[0]->id, 'profile_name' => 'Bespoke Suit Tailoring', 'version' => 1],
            [
                'source' => 'store_owner',
                'metrics' => [
                    'Chest' => '99', 'Shoulder' => '46', 'Sleeve' => '63.5', 'Neck' => '40.5',
                    'Waist' => '84', 'Hip' => '99', 'Inseam' => '81',
                ],
                'notes' => 'Initial fitting — prefers a slightly looser fit around the shoulders.',
                'superseded_at' => now()->subDays(30),
            ]
        );
        Measurement::updateOrCreate(
            ['store_id' => $store->id, 'customer_id' => $customers[0]->id, 'profile_name' => 'Bespoke Suit Tailoring', 'version' => 2],
            [
                'source' => 'store_owner',
                'metrics' => [
                    'Chest' => '101.5', 'Shoulder' => '46', 'Sleeve' => '63.5', 'Neck' => '40.5',
                    'Waist' => '86', 'Hip' => '101.5', 'Inseam' => '81',
                ],
                'notes' => 'Re-measured after a follow-up fitting — chest and waist grew slightly.',
                'superseded_at' => null,
            ]
        );
        Measurement::updateOrCreate(
            ['store_id' => $store->id, 'customer_id' => $customers[2]->id, 'profile_name' => 'Wedding Gown Fitting', 'version' => 1],
            [
                'source' => 'store_owner',
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
        SupportTicket::updateOrCreate(
            ['store_id' => $store->id, 'subject' => 'Payment shows as pending even after customer paid via GCash'],
            [
                'user_id' => $owner->id,
                'message' => 'A customer sent a GCash payment for their appointment deposit and I have the receipt, but it still shows as "pending" on my dashboard. How do I mark it as confirmed?',
                'type' => 'problem',
                'priority' => 'high',
                'status' => 'open',
            ]
        );
        $resolvedTicket = SupportTicket::updateOrCreate(
            ['store_id' => $store->id, 'subject' => 'Requesting a second branch to be added'],
            [
                'user_id' => $owner->id,
                'message' => 'We just opened a second branch in Quezon City. Can you add it to my account so I can start assigning staff and appointments there?',
                'type' => 'update_request',
                'priority' => 'medium',
                'status' => 'resolved',
                'resolved_at' => now()->subDays(2),
            ]
        );
        SupportTicketReply::updateOrCreate(
            ['ticket_id' => $resolvedTicket->id, 'message' => 'Done! Your Quezon City branch has been added — you can find it under Branches. Let us know if you need anything else.'],
            [
                'user_id' => $admin->id,
                'is_admin_reply' => true,
            ]
        );

        // 20. Seed Notifications — a store this active (11 job orders, 40
        // staff stage assignments, 8 appointments, 4 payments) would
        // generate a real stream of in-app notifications, but every one of
        // those rows above was created by writing straight to Eloquent,
        // bypassing the controllers where notification-firing actually
        // lives (JobOrderController@store, the payment/appointment
        // endpoints, etc.) — so the notifications table stayed
        // empty even on a freshly reseeded "realistic" demo store, which
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

        $owner->notify(new PaymentReceivedNotification($jo1, 7500.00));
        $owner->notify(new PaymentReceivedNotification($jo3, 12000.00));
        $owner->notify(new PaymentReceivedNotification($jo4, 3250.00));
        $owner->notify(new PaymentReceivedNotification($jo2, 3250.00));

        // Staff no longer get a dedicated "you've been assigned" notification
        // — that whole manual pre-assignment step was removed in favor of
        // auto-attribution from real status updates (JobOrderController::
        // update()), and there's nothing analogous to seed here; staff just
        // see the job sitting in their branch's queue.
        $seededAppointments = Appointment::where('store_id', $store->id)->latest()->take(3)->get();
        foreach ($seededAppointments as $appt) {
            $owner->notify(new AppointmentBookedNotification($appt));
        }

        $owner->notify(new NewJobOrderNotification($jo9));
        $owner->notify(new NewJobOrderNotification($jo10));
        $owner->notify(new NewJobOrderNotification($jo11));

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

        // Customer-facing notifications — same gap as the owner block above
        // (seeded straight to Eloquent, bypassing AppointmentController/
        // JobOrderController, where customer notification firing already
        // lives for real — verified: every real status change there already
        // calls $customer->notify(...), this is purely backfilling demo
        // data to match, not a production bug). Jose Rizal ends up with
        // zero notifications otherwise, despite having a confirmed
        // appointment, a cancelled one, and an active job order — which is
        // exactly the gap that looked like a bug but was missing seed
        // coverage, same as the owner's case above.
        $customers[0]->notifications()->delete();

        $confirmedAppt = Appointment::where('customer_id', $customers[0]->id)->where('status', 'confirmed')->first();
        if ($confirmedAppt) {
            $customers[0]->notify(new AppointmentStatusNotification($confirmedAppt, 'confirmed'));
        }
        $cancelledAppt = Appointment::where('customer_id', $customers[0]->id)->where('status', 'cancelled')->first();
        if ($cancelledAppt) {
            $customers[0]->notify(new AppointmentStatusNotification($cancelledAppt, 'cancelled'));
        }
        $customers[0]->notify(new JobStatusUpdatedNotification($jo10, 'sewing'));

        $customerNotifications = $customers[0]->notifications()->orderBy('id')->get();
        $cTotal = $customerNotifications->count();
        $cReadCutoff = (int) ceil($cTotal / 2);
        foreach ($customerNotifications as $i => $n) {
            $daysAgo = $cTotal - $i;
            $n->created_at = now()->subDays($daysAgo);
            $n->updated_at = $n->created_at;
            if ($i < $cReadCutoff) {
                $n->read_at = $n->created_at->copy()->addHours(2);
            }
            $n->save();
        }

        // Keep the walk-in order rows consistent: each one belongs to a branch
        // (single-branch shop → Main) and records the size that was ordered
        // (cycled through the design's own sizes), so a design's Orders &
        // Sales table never shows blank branch/size cells.
        CatalogOrder::where('store_id', $store->id)
            ->whereNull('store_branch_id')
            ->update(['store_branch_id' => $mainBranch->id]);
        CatalogOrder::where('store_id', $store->id)
            ->whereNull('selected_size')
            ->with('catalogItem:id,sizes')
            ->get()
            ->each(function (CatalogOrder $order) {
                $sizes = array_values((array) ($order->catalogItem?->sizes ?? []));
                if ($sizes !== []) {
                    $order->update(['selected_size' => $sizes[$order->id % count($sizes)]]);
                }
            });

        // 21/22. The second and third store owners (owner2@ Bautista Custom
        // Tailors, owner3@ Villanueva Bespoke Atelier) were removed on
        // request — the demo is Thread & Needle Tailoring only. Recreating
        // them here would silently undo that on the next db:seed.
    }
}
