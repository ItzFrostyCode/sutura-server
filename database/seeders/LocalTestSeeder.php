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
    /**
     * The demo logins used to be generic (staff@, manager@, customer@, booking.testerN@sutura.com).
     * They are named after the person now — rename an existing database's rows in place, so a
     * re-seed keeps their history instead of creating a second set of accounts.
     */
    private const RENAMED_ACCOUNTS = [
        'owner@sutura.com' => 'maria.cruz@gmail.com',
        'staff@sutura.com' => 'juan.delacruz@sutura.com',
        'manager@sutura.com' => 'miguel.manager@sutura.com',
    ];

    private function renameLegacyAccounts(): void
    {
        foreach (self::RENAMED_ACCOUNTS as $old => $new) {
            if (User::where('email', $old)->exists() && ! User::where('email', $new)->exists()) {
                User::where('email', $old)->update(['email' => $new]);
            }
        }

        // The display name matches the account, whatever an older seed called it.
        foreach ([
            'juan.delacruz@sutura.com' => 'Juan Dela Cruz',
            'miguel.manager@sutura.com' => 'Miguel Manager',
        ] as $email => $name) {
            User::where('email', $email)->update(['name' => $name]);
        }

        foreach ([
            'admin@sutura.com' => 'male',
            'maria.cruz@gmail.com' => 'female',
            'juan.delacruz@sutura.com' => 'male',
            'miguel.manager@sutura.com' => 'male',
            'ana.santos@sutura.com' => 'female',
            'pedro.penduko@sutura.com' => 'male',
            'pia.santos@gmail.com' => 'female',
        ] as $email => $gender) {
            User::where('email', $email)->whereNull('gender')->update(['gender' => $gender]);
        }
    }

    public function run(): void
    {
        $this->renameLegacyAccounts();

        $adminRole = Role::where('name', 'admin')->first();
        $ownerRole = Role::where('name', 'store_owner')->first();
        $staffRole = Role::where('name', 'staff')->first();

        // 1. Create the Admin (For Jossua's Future Testing)
        $admin = User::firstOrCreate(
            ['email' => 'admin@sutura.com'],
            [
                'name' => 'System Admin',
                'gender' => 'male',
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
                'gender' => 'female',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        if (! $owner->roles()->where('role_id', $ownerRole->id)->exists()) {
            $owner->roles()->attach($ownerRole->id);
        }

        // 3. Create your Pre-Approved Store — keyed on the unique slug, so an owner-account
        // mismatch can never try to insert a second 'thread-needle' row.
        $store = Store::firstOrCreate(
            ['slug' => 'thread-needle'],
            [
                'owner_id' => $owner->id,
                'name' => 'Thread & Needle Tailoring',
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
                'is_featured' => true,
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

        // Active Premium (yearly) — a paid-up Premium shop is what the landing
        // page's "Featured & Recommended" spotlight shows (Store::scopePremiumActive),
        // so a fresh seed always has one there. (A 30-day trial used to expire.)
        $premiumPlan = SubscriptionPlan::where('slug', 'premium')->first();
        if ($premiumPlan && ! $store->subscription()->exists()) {
            StoreSubscription::create([
                'store_id' => $store->id,
                'plan_id' => $premiumPlan->id,
                'status' => 'active',
                'billing_cycle' => 'yearly',
                'starts_at' => now(),
                'ends_at' => now()->addYear(),
            ]);
        }

        // 3b. Sample Store #2: Multi-Branch & Expiring Subscription (Item 10 & 11)
        $owner2 = User::firstOrCreate(
            ['email' => 'ricardo.santos@sutura.com'],
            [
                'name' => 'Ricardo Santos',
                'gender' => 'male',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        if (! $owner2->roles()->where('role_id', $ownerRole->id)->exists()) {
            $owner2->roles()->attach($ownerRole->id);
        }

        $store2 = Store::firstOrCreate(
            ['slug' => 'davao-formal-wear'],
            [
                'owner_id' => $owner2->id,
                'name' => 'Davao Formal Wear Co.',
                'store_code' => 'DFWC',
                'description' => 'Fine bespoke tailoring, barongs, and formal corporate suits.',
                'specializations' => CanonicalTaxonomy::storeSpecializations(),
                'address' => '456 JP Laurel Avenue, Bajada',
                'city' => 'Davao City',
                'province' => 'Davao del Sur',
                'email' => 'ricardo@davaoformal.com',
                'gcash_number' => '0917 987 6543',
                'gcash_account_name' => 'Davao Formal Wear Co.',
                'phone' => '+639179876543',
                'status' => 'approved',
                'approved_at' => now(),
                'is_featured' => false,
            ]
        );

        $proPlan = SubscriptionPlan::where('slug', 'pro')->first();
        if ($proPlan && ! $store2->subscription()->exists()) {
            // Subscription expiring in 2 days (Item 11: tests Admin 3-day reminder widget!)
            StoreSubscription::create([
                'store_id' => $store2->id,
                'plan_id' => $proPlan->id,
                'status' => 'active',
                'billing_cycle' => 'monthly',
                'starts_at' => now()->subDays(28),
                'ends_at' => now()->addDays(2),
            ]);
        }

        // Seed Main Branch
        $mainBranch = StoreBranch::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Poblacion Branch'],
            [
                'slug' => Str::slug('Poblacion Branch').'-'.uniqid(),
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
            ['email' => 'juan.delacruz@sutura.com'],
            [
                'name' => 'Juan Dela Cruz',
                'gender' => 'male',
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

        // 4b. Branch Manager demo login — kept separate from juan.delacruz@sutura.com (which is plain
        // Staff) so testers can compare the two roles side by side.
        $branchManagerRole = Role::where('name', 'branch_manager')->first();
        $manager = User::firstOrCreate(
            ['email' => 'miguel.manager@sutura.com'],
            ['name' => 'Miguel Manager', 'gender' => 'male', 'password' => Hash::make('password'), 'email_verified_at' => now()]
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

        // 5. Update Store Profile Details, Location, Storefront Branding & Social Links
        $store->update([
            'logo_path' => Storage::url('logos/thread_needle_logo.jpg'),
            'banner_path' => Storage::url('banners/thread_needle_banner.jpg'),
            'gallery_images' => [
                Storage::url('banners/thread_needle_banner.jpg'),
                Storage::url('banners/thread_needle_storefront.jpg'),
                Storage::url('logos/thread_needle_logo.jpg'),
            ],
            'description' => "Davao City's premier bespoke tailoring atelier and sublimation production house. Specializing in custom-fit suits, traditional and modern barongs, corporate uniforms, and full-sublimation athletic jerseys with master-tailored craftsmanship.",
            'address' => '123 Rizal Avenue, Poblacion',
            'landmark' => 'Across Rizal Park, near San Pedro Cathedral',
            'city' => 'Davao City',
            'province' => 'Davao del Sur',
            'postal_code' => '8000',
            'phone' => '+63 917 123 4567',
            'email' => 'hello@threadneedle.com',
            'business_type' => 'tailoring_store',
            'latitude' => '7.0702',
            'longitude' => '125.6077',
            'max_appointments_per_day' => 12,
            'booking_policy' => 'Consultations and fittings are by appointment. Walk-ins welcome for inquiries and basic measurements.',
            'booking_questions' => [
                'What type of garment or uniform do you need?',
                'Do you have your own fabric, or will you select from our in-house textiles?',
                'Target completion or event date?',
            ],
            'cancellation_policy' => 'Full refund if cancelled before fabric cutting and production start. 50% refund once materials have been cut.',
            'fitting_fee' => 0.00,
            'fitting_limit' => 3,
            'fitting_limit_policy' => 'fee',
            'rush_available' => true,
            'rush_fee_mode' => 'percent',
            'rush_fee_percent' => 20.0,
            'rush_fee_per_piece' => 0.0,
            'repair_requires_downpayment' => true,
            'default_measurement_requirement' => 'shop',
            'default_measurement_unit' => 'in',
            'default_fitting_requirement' => 'optional',
            'default_payment_policy' => 'deposit',
            'default_payment_percent' => 50,
            'is_featured' => true,
            'is_hidden' => false,
            'gcash_number' => '0917 123 4567',
            'gcash_account_name' => 'Thread & Needle Tailoring',
            'bank_name' => 'BDO Unibank',
            'bank_account_name' => 'Thread & Needle Tailoring Inc.',
            'bank_account_number' => '0012 3456 7890',
            'operating_hours' => [
                'monday' => ['is_open' => true, 'open' => '08:30', 'close' => '18:30'],
                'tuesday' => ['is_open' => true, 'open' => '08:30', 'close' => '18:30'],
                'wednesday' => ['is_open' => true, 'open' => '08:30', 'close' => '18:30'],
                'thursday' => ['is_open' => true, 'open' => '08:30', 'close' => '18:30'],
                'friday' => ['is_open' => true, 'open' => '08:30', 'close' => '18:30'],
                'saturday' => ['is_open' => true, 'open' => '09:00', 'close' => '17:00'],
                'sunday' => ['is_open' => false, 'open' => '09:00', 'close' => '17:00'],
            ],
            'social_links' => [
                ['label' => 'Facebook', 'url' => 'https://facebook.com/threadandneedledavao'],
                ['label' => 'Instagram', 'url' => 'https://instagram.com/threadneedledavao'],
                ['label' => 'TikTok', 'url' => 'https://tiktok.com/@threadneedledavao'],
                ['label' => 'YouTube', 'url' => 'https://youtube.com/@threadneedledavao'],
                ['label' => 'Threads', 'url' => 'https://threads.net/@threadneedledavao'],
                ['label' => 'Pinterest', 'url' => 'https://pinterest.com/threadneedledavao'],
                ['label' => 'LinkedIn', 'url' => 'https://linkedin.com/company/thread-needle-tailoring'],
                ['label' => 'Official Website', 'url' => 'https://threadneedle.ph'],
            ],
            'specializations' => CanonicalTaxonomy::storeSpecializations(),
        ]);

        // Keep Main Branch details and coordinates fully synced
        $mainBranch->update([
            'address' => '123 Rizal Avenue, Poblacion',
            'street' => 'Rizal Avenue',
            'landmark' => 'Across Rizal Park, near San Pedro Cathedral',
            'barangay' => 'Barangay 2-A',
            'city' => 'Davao City',
            'district' => 'Poblacion',
            'province' => 'Davao del Sur',
            'latitude' => 7.0702,
            'longitude' => 125.6077,
            'contact_number' => '+63 917 123 4567',
            'guide_image_url' => Storage::url('banners/thread_needle_banner.jpg'),
            'landmark_image_url' => Storage::url('banners/thread_needle_storefront.jpg'),
            'status' => 'active',
            'verification_status' => 'verified',
            'verified_at' => now(),
            'operating_hours' => [
                'monday' => ['is_open' => true, 'open' => '08:30', 'close' => '18:30'],
                'tuesday' => ['is_open' => true, 'open' => '08:30', 'close' => '18:30'],
                'wednesday' => ['is_open' => true, 'open' => '08:30', 'close' => '18:30'],
                'thursday' => ['is_open' => true, 'open' => '08:30', 'close' => '18:30'],
                'friday' => ['is_open' => true, 'open' => '08:30', 'close' => '18:30'],
                'saturday' => ['is_open' => true, 'open' => '09:00', 'close' => '17:00'],
                'sunday' => ['is_open' => false, 'open' => '09:00', 'close' => '17:00'],
            ],
        ]);

        // Seed initial verified customer reviews so store rating is immediately available
        $customerRole = Role::where('name', 'customer')->first();
        $sampleReviewers = [
            ['email' => 'pia.santos@gmail.com', 'name' => 'Pia Santos', 'gender' => 'female', 'rating' => 5, 'comment' => 'Superb quality bespoke suits! The craftsmanship on my barong was impeccable.'],
            ['email' => 'carlos.mendoza@gmail.com', 'name' => 'Carlos Mendoza', 'gender' => 'male', 'rating' => 5, 'comment' => 'Our company jerseys and tournament uniforms were finished ahead of schedule. Excellent sublimation print!'],
            ['email' => 'bea.alonzo@gmail.com', 'name' => 'Bea Alonzo', 'gender' => 'female', 'rating' => 5, 'comment' => 'Very accommodating staff and head tailor. Precise measurements and very clean stitching.'],
        ];
        foreach ($sampleReviewers as $r) {
            $reviewer = User::firstOrCreate(
                ['email' => $r['email']],
                ['name' => $r['name'], 'gender' => $r['gender'], 'password' => Hash::make('password'), 'email_verified_at' => now()]
            );
            if ($customerRole && ! $reviewer->roles()->where('role_id', $customerRole->id)->exists()) {
                $reviewer->roles()->attach($customerRole->id);
            }
            StoreReview::updateOrCreate(
                ['store_id' => $store->id, 'user_id' => $reviewer->id],
                ['rating' => $r['rating'], 'comment' => $r['comment'], 'is_featured' => true]
            );
        }

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
                'categories' => ['Custom Jersey Printing', 'Corporate & Team Uniforms'],
                'service_category' => 'uniform_production', // jerseys: Uniform, Jersey & Teamwear (2026-10-14)
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
                'categories' => ['Suit & Tuxedo Tailoring', 'Formal & Cultural Wear'],
                'service_category' => 'custom_tailoring',
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
                'categories' => ['Barong Tagalog Tailoring', 'Formal & Cultural Wear'],
                'service_category' => 'custom_tailoring',
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
                'service_category' => 'custom_tailoring',
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
                'service_category' => 'uniform_production',
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
                'service_category' => 'uniform_production', // jerseys: Uniform, Jersey & Teamwear (2026-10-14)
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
                'categories' => ['Embroidered Logos & Team Names', 'Custom Apparel, Printing & Embroidery'],
                // Embroidery on the customer's garments: one of the 4 approved services (2026-10-14).
                'service_category' => 'garment_customization',
                'service_types' => ['custom_tailoring'],
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
                'categories' => ['Costume Tailoring', 'Formal & Cultural Wear'],
                'service_category' => 'custom_tailoring', // costumes: Custom Tailoring + Costumes garments (2026-10-14)
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

        // 7. Multi-branch setup for Thread & Needle:
        // - Poblacion Branch: is_main = true, status = 'active', verification_status = 'verified'
        // - Lanang Branch: is_main = false, status = 'active', verification_status = 'verified' (tests multiple branches)
        // - Matina Branch: is_main = false, status = 'inactive', verification_status = 'verified' (tests hidden branch filtering!)
        $branch2 = StoreBranch::updateOrCreate(
            ['store_id' => $store->id, 'name' => 'Lanang Branch'],
            [
                'slug' => 'lanang-branch-sat',
                'address' => 'KM 7 Lanang, Davao City',
                'city' => 'Davao City',
                'district' => 'Lanang',
                'landmark' => 'Near SM Lanang Premier',
                'contact_number' => '+63 917 100 0002',
                'operating_hours' => 'Mon-Sat 9:00 AM - 6:00 PM',
                'latitude' => 7.0988,
                'longitude' => 125.6315,
                'is_main' => false,
                'status' => 'active',
                'verification_status' => 'verified',
            ]
        );

        $branch3 = StoreBranch::updateOrCreate(
            ['store_id' => $store->id, 'name' => 'Matina Branch'],
            [
                'slug' => 'matina-branch-sat',
                'address' => 'McArthur Highway, Matina, Davao City',
                'city' => 'Davao City',
                'district' => 'Matina',
                'landmark' => 'Across Ateneo de Davao Matina Campus',
                'contact_number' => '+63 917 100 0003',
                'operating_hours' => 'Mon-Sat 8:00 AM - 5:00 PM',
                'latitude' => 7.0515,
                'longitude' => 125.5901,
                'is_main' => false,
                'status' => 'inactive', // HIDDEN BRANCH for testing hiding/filtering!
                'verification_status' => 'verified',
            ]
        );

        // 8. Seed 2 Additional Tailoring Staff Members (making it 3 staff in total)
        $staffNames = [
            ['email' => 'ana.santos@sutura.com', 'name' => 'Ana Santos', 'gender' => 'female', 'role' => 'senior_designer'],
            ['email' => 'pedro.penduko@sutura.com', 'name' => 'Pedro Penduko', 'gender' => 'male', 'role' => 'cutter_sewer'],
        ];

        $staffUsers = [];
        $staffUsers[] = $staff; // Add the first staff we created earlier

        foreach ($staffNames as $sData) {
            $sUser = User::firstOrCreate(
                ['email' => $sData['email']],
                [
                    'name' => $sData['name'],
                    'gender' => $sData['gender'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );
            if (! $sUser->gender) {
                $sUser->update(['gender' => $sData['gender']]);
            }
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

        // 9. Seed Customers (Disabled per user request - only seeded if SEED_DEMO_CUSTOMERS=true)
        if (filter_var(env('SEED_DEMO_CUSTOMERS', false), FILTER_VALIDATE_BOOL)) {
        $customerRole = Role::where('name', 'customer')->first();
        $customers = [];
        $customerNames = [
            ['email' => 'juan.delacruz@gmail.com', 'name' => 'Juan dela Cruz', 'gender' => 'male'],
            ['email' => 'jose.rizal@gmail.com', 'name' => 'Jose Rizal', 'gender' => 'male'],
            ['email' => 'andres.b@gmail.com', 'name' => 'Andres Bonifacio', 'gender' => 'male'],
            ['email' => 'maria.clara@gmail.com', 'name' => 'Maria Clara', 'gender' => 'female'],
        ];

        foreach ($customerNames as $cData) {
            $cUser = User::firstOrCreate(
                ['email' => $cData['email']],
                [
                    'name' => $cData['name'],
                    'gender' => $cData['gender'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );
            if (! $cUser->gender) {
                $cUser->update(['gender' => $cData['gender']]);
            }
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
        foreach ([['tess.tester@gmail.com', 'Tess Tester', 'female'], ['tomas.tester@gmail.com', 'Tomas Tester', 'male']] as [$tEmail, $tName, $tGender]) {
            $tester = User::firstOrCreate(
                ['email' => $tEmail],
                ['name' => $tName, 'gender' => $tGender, 'password' => Hash::make('password'), 'email_verified_at' => now()]
            );
            if (! $tester->gender) {
                $tester->update(['gender' => $tGender]);
            }
            if (! $tester->roles()->where('role_id', $customerRole->id)->exists()) {
                $tester->roles()->attach($customerRole->id);
            }
            $store->customers()->syncWithoutDetaching([$tester->id]);
        }

        // 9c. Joshua's real account — the developer's own login. When he opens
        // /account/orders he sees his own orders with full measurement data,
        // payment history (via GCash), and financial breakdown. This account
        // already exists from the data snapshot (user 19), so firstOrCreate
        // picks it up without duplicating it.
        $joshuaUser = User::firstOrCreate(
            ['email' => 'itzjoshuawayman@gmail.com'],
            [
                'name' => 'Joshua A. Arabejo',
                'gender' => 'male',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        if (! $joshuaUser->roles()->where('role_id', $customerRole->id)->exists()) {
            $joshuaUser->roles()->attach($customerRole->id);
        }
        $store->customers()->syncWithoutDetaching([$joshuaUser->id]);

        // 9b. Seed 3 more customers used specifically for the GCash/Bank Transfer
        // receipt-verification demo scenarios below — these existed only as ad
        // hoc records before, not in this seeder, so a fresh reseed lost them.
        $onlineCustomerNames = [
            ['email' => 'liza.fernandez@example.com', 'name' => 'Liza Fernandez', 'gender' => 'female'],
            ['email' => 'mark.villanueva@example.com', 'name' => 'Mark Villanueva', 'gender' => 'male'],
            ['email' => 'cristina.ramos@example.com', 'name' => 'Cristina Ramos', 'gender' => 'female'],
        ];
        $onlineCustomers = [];
        foreach ($onlineCustomerNames as $cData) {
            $cUser = User::firstOrCreate(
                ['email' => $cData['email']],
                ['name' => $cData['name'], 'gender' => $cData['gender'], 'password' => Hash::make('password'), 'email_verified_at' => now()]
            );
            if (! $cUser->gender) {
                $cUser->update(['gender' => $cData['gender']]);
            }
            if (! $cUser->roles()->where('role_id', $customerRole->id)->exists()) {
                $cUser->roles()->attach($customerRole->id);
            }
            $store->customers()->syncWithoutDetaching([$cUser->id]);
            $onlineCustomers[$cData['name']] = $cUser;
        }

        // Set profile pictures for demo customers (persisted avatars in storage/app/public/avatars/)
        // These are tracked in git so they persist across machines.
        $avatarUrls = [
            'pia.santos@gmail.com'       => Storage::url('avatars/customer-1.jpg'),
            'carlos.mendoza@gmail.com'   => Storage::url('avatars/customer-2.jpg'),
            'juan.delacruz@gmail.com'    => Storage::url('avatars/customer-1.jpg'), // reuse first avatar
        ];
        foreach ($avatarUrls as $email => $url) {
            User::where('email', $email)->update(['profile_picture' => $url]);
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

        // 11. Seed 16 Job Orders showcasing all distinct production statuses and if/else workflow branches
        $repairService = Service::where('name', 'Garment Alterations & Repair Services')->first() ?? $service1;
        $barongService = Service::where('name', 'Barong Tagalog Tailoring')->first() ?? $service2;

        // ORD-0001: PENDING (Quotation Approval Gate)
        $jo1 = JobOrder::updateOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0001'],
            [
                'tracking_code' => 'TNED8K2P',
                'store_branch_id' => $mainBranch->id,
                'customer_id' => $customers[0]->id,
                'service_id' => $barongService->id,
                'assigned_staff_id' => $staffUsers[0]->id,
                'total_amount' => 8500.00,
                'balance' => 8500.00,
                'payment_status' => 'unpaid',
                'status' => 'pending',
                'due_date' => now()->addDays(14)->format('Y-m-d'),
                'notes' => 'Piña Seda bespoke barong — quotation pending customer sign-off.',
                'intake_channel' => 'online',
                'fulfillment_type' => 'pickup',
                'quotation_status' => 'final',
                'custom_order_data' => [
                    'size' => 'Custom Fit',
                    'unit' => 'in',
                    'measurements' => [
                        'Chest' => 38,
                        'Waist' => 32,
                        'Shoulder' => 17.5,
                        'Sleeve Length' => 24.5,
                        'Barong Length' => 29,
                        'Neck' => 15.5,
                    ],
                    'fabric_name' => 'Piña Seda (Natural Beige)',
                ],
            ]
        );

        // ORD-0002: CUTTING (Bulk Order, Rush Priority, Discount, Team Roster)
        $jo2 = JobOrder::updateOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0002'],
            [
                'tracking_code' => 'TNED4M7B',
                'store_branch_id' => $branch2->id,
                'customer_id' => $customers[1]->id,
                'service_id' => $service1->id,
                'assigned_staff_id' => $lanangStaff->id,
                'total_amount' => 7000.00,
                'balance' => 3750.00,
                'rush_fee' => 1000.00,
                'discount_amount' => 500.00,
                'is_rush' => true,
                'balance_due_by' => now()->addDays(5)->format('Y-m-d'),
                'estimated_ready_at' => now()->addDays(7),
                'payment_status' => 'partial',
                'status' => 'cutting',
                'due_date' => now()->addDays(7)->format('Y-m-d'),
                'notes' => '10 jerseys set for tournament (Expedited Rush Turnaround)',
                'intake_channel' => 'online',
                'fulfillment_type' => 'pickup',
                'custom_order_data' => [
                    'team_name' => 'Davao Eagles',
                    'unit' => 'in',
                    'team_roster' => [
                        ['name' => 'Juan Dela Cruz', 'gender' => 'Male', 'print_name' => 'JUAN', 'number' => '10', 'size' => 'L', 'cut' => "Men's"],
                        ['name' => 'Pedro Penduko', 'gender' => 'Male', 'print_name' => 'PEDRO', 'number' => '7', 'size' => 'M', 'cut' => "Men's"],
                        ['name' => 'Maria Makiling', 'gender' => 'Female', 'print_name' => 'MARIA', 'number' => '23', 'size' => 'S', 'cut' => "Ladies'"],
                        ['name' => 'Ramon Bautista', 'gender' => 'Male', 'print_name' => 'RAMON', 'number' => '5', 'size' => 'L', 'cut' => "Men's"],
                        ['name' => 'Carlo Reyes', 'gender' => 'Male', 'print_name' => 'CARLO', 'number' => '11', 'size' => 'M', 'cut' => "Men's"],
                        ['name' => 'Nico Santos', 'gender' => 'Male', 'print_name' => 'NICO', 'number' => '3', 'size' => 'XL', 'cut' => "Men's"],
                        ['name' => 'Ella Villanueva', 'gender' => 'Female', 'print_name' => 'ELLA', 'number' => '8', 'size' => 'S', 'cut' => "Ladies'"],
                        ['name' => 'Miguel Torres', 'gender' => 'Male', 'print_name' => 'MIGUEL', 'number' => '14', 'size' => 'L', 'cut' => "Men's"],
                        ['name' => 'Diego Fernandez', 'gender' => 'Male', 'print_name' => 'DIEGO', 'number' => '22', 'size' => 'M', 'cut' => "Men's"],
                        ['name' => 'Paolo Cruz', 'gender' => 'Male', 'print_name' => 'PAOLO', 'number' => '9', 'size' => 'L', 'cut' => "Men's"],
                    ],
                ],
            ]
        );

        // ORD-0003: READY_FOR_PICKUP (Bespoke Corporate Suit, Fully Paid)
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
                'due_date' => now()->subDays(5)->format('Y-m-d'),
                'notes' => 'Bespoke corporate dress suit — customer notified for pickup.',
                'intake_channel' => 'walk_in',
                'fulfillment_type' => 'pickup',
                'custom_order_data' => [
                    'size' => 'Custom Fit',
                    'unit' => 'in',
                    'measurements' => [
                        'Bust' => 36,
                        'Waist' => 28,
                        'Hips' => 38,
                        'Shoulder' => 15.5,
                        'Blazer Length' => 26,
                        'Skirt Length' => 22,
                    ],
                ],
            ]
        );
        $jo3->forceFill(['ready_for_pickup_at' => now()->subDays(5)])->save();

        // ORD-0004: SEWING (Tailored Trousers in Production)
        $jo4 = JobOrder::updateOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0004'],
            [
                'tracking_code' => 'TNED2V5H',
                'store_branch_id' => $mainBranch->id,
                'customer_id' => $customers[0]->id,
                'service_id' => $service2->id,
                'assigned_staff_id' => $staffUsers[2]->id,
                'total_amount' => 3500.00,
                'balance' => 1750.00,
                'payment_status' => 'partial',
                'status' => 'sewing',
                'due_date' => now()->addDays(4)->format('Y-m-d'),
                'notes' => 'Tailored charcoal trousers — assembling waistband and inseam.',
                'intake_channel' => 'walk_in',
                'fulfillment_type' => 'pickup',
                'custom_order_data' => [
                    'size' => 'Custom Fit',
                    'unit' => 'in',
                    'measurements' => [
                        'Waist' => 33,
                        'Hips' => 39,
                        'Inseam' => 30,
                        'Outseam' => 40,
                        'Thigh' => 23,
                        'Knee' => 17,
                        'Leg Opening' => 14,
                    ],
                ],
            ]
        );

        // ORD-0005: CONFIRMED (Gown Downpayment Approved, Awaiting Production Start)
        $jo5 = JobOrder::updateOrCreate(
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
                'status' => 'confirmed',
                'due_date' => now()->addDays(21)->format('Y-m-d'),
                'notes' => 'Custom Wedding Gown — 50% deposit received, production scheduled.',
                'intake_channel' => 'online',
                'fulfillment_type' => 'pickup',
                'custom_order_data' => [
                    'size' => 'Custom Fit',
                    'unit' => 'in',
                    'measurements' => [
                        'Bust' => 34,
                        'Underbust' => 29,
                        'Waist' => 26,
                        'Hips' => 36,
                        'Hollow to Hem' => 58,
                        'Shoulder to Waist' => 15.5,
                    ],
                ],
            ]
        );

        // ORD-0006: AWAITING_MATERIALS (Customer Drop-off Pending)
        $jo6 = JobOrder::updateOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0006'],
            [
                'tracking_code' => 'TNED6W9E',
                'store_branch_id' => $branch2->id,
                'customer_id' => $customers[2]->id,
                'service_id' => $service2->id,
                'assigned_staff_id' => $lanangStaff->id,
                'total_amount' => 6000.00,
                'balance' => 3000.00,
                'payment_status' => 'partial',
                'status' => 'awaiting_materials',
                'due_date' => now()->addDays(15)->format('Y-m-d'),
                'notes' => 'Customer providing 3 yards of silk fabric; waiting for drop-off at Lanang.',
                'intake_channel' => 'online',
                'fulfillment_type' => 'pickup',
                'customer_material_status' => 'safe',
                'custom_order_data' => [
                    'size' => 'L',
                    'unit' => 'in',
                    'measurements' => [
                        'Chest' => 40,
                        'Waist' => 34,
                        'Shoulder' => 18,
                        'Sleeve' => 25,
                    ],
                ],
            ]
        );

        // ORD-0007: DESIGN (Sketch & Swatch Selection)
        $jo7 = JobOrder::updateOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0007'],
            [
                'tracking_code' => 'TNED3P8T',
                'store_branch_id' => $mainBranch->id,
                'customer_id' => $customers[0]->id,
                'service_id' => $service2->id,
                'assigned_staff_id' => $staffUsers[0]->id,
                'total_amount' => 16000.00,
                'balance' => 8000.00,
                'payment_status' => 'partial',
                'status' => 'design',
                'due_date' => now()->addDays(18)->format('Y-m-d'),
                'notes' => 'Bespoke Evening Gown — senior designer drafting custom neckline sketch.',
                'intake_channel' => 'walk_in',
                'fulfillment_type' => 'pickup',
                'custom_order_data' => [
                    'size' => 'Custom Fit',
                    'unit' => 'in',
                    'measurements' => [
                        'Bust' => 35,
                        'Waist' => 27,
                        'Hips' => 37,
                        'Shoulder' => 15,
                        'Full Length' => 56,
                    ],
                ],
            ]
        );

        // ORD-0008: PATTERN_MAKING (Drafting Bespoke Sloper)
        $jo8 = JobOrder::updateOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0008'],
            [
                'tracking_code' => 'TNED5R2Y',
                'store_branch_id' => $branch2->id,
                'customer_id' => $customers[1]->id,
                'service_id' => $service2->id,
                'assigned_staff_id' => $lanangStaff->id,
                'total_amount' => 9500.00,
                'balance' => 4750.00,
                'payment_status' => 'partial',
                'status' => 'pattern_making',
                'due_date' => now()->addDays(11)->format('Y-m-d'),
                'notes' => 'Custom Blazer — drafting calibrated paper pattern based on measurements.',
                'intake_channel' => 'online',
                'fulfillment_type' => 'pickup',
                'custom_order_data' => [
                    'size' => 'M',
                    'unit' => 'in',
                    'measurements' => [
                        'Chest' => 39,
                        'Waist' => 33,
                        'Shoulder' => 17,
                        'Sleeve' => 24.5,
                        'Back Length' => 28.5,
                    ],
                ],
            ]
        );

        // ORD-0009: MASS_CUTTING_PRINTING (Bulk Sublimation Print)
        $jo9 = JobOrder::updateOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0009'],
            [
                'tracking_code' => 'TNED8L3X',
                'store_branch_id' => $branch2->id,
                'customer_id' => $customers[2]->id,
                'service_id' => $service1->id,
                'assigned_staff_id' => $lanangStaff->id,
                'total_amount' => 9750.00,
                'balance' => 4875.00,
                'payment_status' => 'partial',
'status' => 'mass_cutting',
                'due_date' => now()->addDays(8)->format('Y-m-d'),
                'notes' => '15 volleyball jerseys — bulk sublimation cutting (skipped Pattern Making)',
                'intake_channel' => 'online',
                'fulfillment_type' => 'pickup',
                'custom_order_data' => [
                    'team_name' => 'Matina Spikers',
                    'fabric_preference' => 'Drifit Mesh',
                    'unit' => 'in',
                    'team_roster' => [
                        ['name' => 'Anna Reyes', 'gender' => 'Female', 'print_name' => 'ANNA', 'number' => '4', 'size' => 'M', 'cut' => "Ladies'"],
                        ['name' => 'Bea Santos', 'gender' => 'Female', 'print_name' => 'BEA', 'number' => '12', 'size' => 'S', 'cut' => "Ladies'"],
                        ['name' => 'Carla Cruz', 'gender' => 'Female', 'print_name' => 'CARLA', 'number' => '7', 'size' => 'L', 'cut' => "Ladies'"],
                        ['name' => 'Diana Mendoza', 'gender' => 'Female', 'print_name' => 'DIANA', 'number' => '1', 'size' => 'M', 'cut' => "Ladies'"],
                        ['name' => 'Elena Garcia', 'gender' => 'Female', 'print_name' => 'ELENA', 'number' => '9', 'size' => 'S', 'cut' => "Ladies'"],
                    ],
                ],
            ]
        );

        // ORD-0010: READY_FOR_FITTING (Fitting Gate with Linked Appointment)
        $jo10 = JobOrder::updateOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0010'],
            [
                'tracking_code' => 'TNED2J7M',
                'store_branch_id' => $mainBranch->id,
                'customer_id' => $customers[0]->id,
                'service_id' => $service2->id,
                'assigned_staff_id' => $staffUsers[1]->id,
                'total_amount' => 14000.00,
                'balance' => 7000.00,
                'payment_status' => 'partial',
                'status' => 'ready_for_fitting',
                'due_date' => now()->addDays(6)->format('Y-m-d'),
                'notes' => 'Bespoke Suit — basted fitting ready, scheduled for client check.',
                'intake_channel' => 'walk_in',
                'fulfillment_type' => 'pickup',
                'custom_order_data' => [
                    'size' => 'Custom Fit',
                    'unit' => 'in',
                    'measurements' => [
                        'Chest' => 41,
                        'Waist' => 35,
                        'Hips' => 41,
                        'Shoulder' => 18.5,
                        'Sleeve Length' => 25,
                    ],
                ],
            ]
        );
        Appointment::firstOrCreate(
            ['store_id' => $store->id, 'job_order_id' => $jo10->id, 'appointment_type' => 'fitting'],
            [
                'store_branch_id' => $mainBranch->id,
                'customer_id' => $jo10->customer_id,
                'intake_channel' => 'walk_in',
                'scheduled_at' => now()->addDay()->setTime(10, 0),
                'duration_minutes' => Appointment::TYPE_DEFAULT_DURATIONS['fitting'],
                'status' => 'pending',
                'notes' => 'Scheduled fitting session for ORD-0010.',
            ]
        );

        // ORD-0011: FINAL_ADJUSTMENTS (Post-Fitting Adjustments)
        $jo11 = JobOrder::updateOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0011'],
            [
                'tracking_code' => 'TNED9B6S',
                'store_branch_id' => $mainBranch->id,
                'customer_id' => $customers[1]->id,
                'service_id' => $service2->id,
                'assigned_staff_id' => $staffUsers[0]->id,
                'total_amount' => 14500.00,
                'balance' => 7250.00,
                'payment_status' => 'partial',
                'status' => 'final_adjustments',
                'due_date' => now()->addDays(3)->format('Y-m-d'),
                'notes' => 'Fitting revealed shoulder slope needs 0.5in taper — final adjustments.',
                'intake_channel' => 'walk_in',
                'fulfillment_type' => 'pickup',
                'custom_order_data' => [
                    'size' => 'Custom Fit',
                    'unit' => 'in',
                    'measurements' => [
                        'Chest' => 38,
                        'Waist' => 31.5,
                        'Hips' => 38,
                        'Shoulder' => 17,
                        'Sleeve' => 24,
                    ],
                ],
            ]
        );

        // ORD-0012: QC_IRONING (Steam Pressing & Inspection)
        $jo12 = JobOrder::updateOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0012'],
            [
                'tracking_code' => 'TNED7V1C',
                'store_branch_id' => $branch2->id,
                'customer_id' => $customers[2]->id,
                'service_id' => $service2->id,
                'assigned_staff_id' => $lanangStaff->id,
                'total_amount' => 11000.00,
                'balance' => 5500.00,
                'payment_status' => 'partial',
                'status' => 'qc_ironing',
                'due_date' => now()->addDays(2)->format('Y-m-d'),
                'notes' => 'Final quality inspection, lining check, and industrial steam pressing.',
                'intake_channel' => 'walk_in',
                'fulfillment_type' => 'pickup',
                'custom_order_data' => [
                    'size' => 'Custom Fit',
                    'unit' => 'in',
                    'measurements' => [
                        'Bust' => 37,
                        'Waist' => 29,
                        'Hips' => 39,
                        'Shoulder' => 16,
                        'Full Length' => 42,
                    ],
                ],
            ]
        );

        // ORD-0013: IN_REPAIR (Alterations & Repair Pipeline)
        $jo13 = JobOrder::updateOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0013'],
            [
                'tracking_code' => 'TNED1R8Z',
                'store_branch_id' => $mainBranch->id,
                'customer_id' => $customers[0]->id,
                'service_id' => $repairService->id,
                'assigned_staff_id' => $staffUsers[2]->id,
                'total_amount' => 450.00,
                'balance' => 0.00,
                'payment_status' => 'paid',
                'status' => 'in_repair',
                'due_date' => now()->addDays(2)->format('Y-m-d'),
                'notes' => 'Denim jacket brass zipper replacement and reinforce elbow patches.',
                'intake_channel' => 'online',
                'fulfillment_type' => 'pickup',
                'custom_order_data' => [
                    'service_kind' => 'repair',
                    'repair_note' => 'Denim jacket brass zipper replacement and reinforce elbow patches.',
                    'alteration_points' => [
                        'Zipper' => 'Heavy-duty Brass 24 inch',
                        'Hem' => 'Take in 1 inch',
                    ],
                ],
            ]
        );

        // ORD-0014: BALANCE_HANDOVER (The Audited Order #14: Custom 3-Piece Suit at Counter Handover)
        $jo14 = JobOrder::updateOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0014'],
            [
                'tracking_code' => 'TNED4H9L',
                'store_branch_id' => $mainBranch->id,
                'customer_id' => $customers[0]->id,
                'service_id' => $service2->id,
                'assigned_staff_id' => $staffUsers[0]->id,
                'total_amount' => 16500.00,
                'balance' => 8250.00,
                'rush_fee' => 0.00,
                'discount_amount' => 500.00,
                'is_rush' => false,
                'balance_due_by' => now()->addDays(2)->format('Y-m-d'),
                'estimated_ready_at' => now()->addDays(2),
                'payment_status' => 'partial',
                'status' => 'balance_handover',
                'due_date' => now()->addDays(2)->format('Y-m-d'),
                'notes' => 'Custom 3-Piece Charcoal Wool Suit — finished at counter for final handover and balance payment.',
                'intake_channel' => 'online',
                'fulfillment_type' => 'pickup',
                'custom_order_data' => [
                    'size' => 'Custom Fit',
                    'unit' => 'in',
                    'measurements' => [
                        'Chest' => 40,
                        'Waist' => 33,
                        'Hips' => 40.5,
                        'Shoulder Width' => 18,
                        'Sleeve Length' => 25,
                        'Jacket Length' => 30.5,
                        'Vest Length' => 24,
                        'Pants Inseam' => 31,
                        'Neck' => 16,
                    ],
                    'fabric_preference' => 'Charcoal Grey Wool Serge with Satin Lapels',
                ],
            ]
        );

        // ORD-0015: COMPLETED (Released, Fully Paid, Customer Reviewed)
        $jo15 = JobOrder::updateOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0015'],
            [
                'tracking_code' => 'TNED5C3Q',
                'store_branch_id' => $mainBranch->id,
                'customer_id' => $customers[1]->id,
                'service_id' => $barongService->id,
                'assigned_staff_id' => $staffUsers[1]->id,
                'total_amount' => 13500.00,
                'balance' => 0.00,
                'payment_status' => 'paid',
                'status' => 'completed',
                'due_date' => now()->subDays(10)->format('Y-m-d'),
                'notes' => 'Graduation Barong Tagalog — released and worn for ceremony.',
                'intake_channel' => 'walk_in',
                'fulfillment_type' => 'pickup',
                'custom_order_data' => [
                    'size' => 'Custom Fit',
                    'unit' => 'in',
                    'measurements' => [
                        'Chest' => 39,
                        'Waist' => 32,
                        'Shoulder' => 17.5,
                        'Sleeve' => 24.5,
                        'Barong Length' => 29.5,
                    ],
                ],
            ]
        );

        // ORD-0016: QUEUED (Materials Verified, Scheduled on Cutting Floor)
        $jo16 = JobOrder::updateOrCreate(
            ['store_id' => $store->id, 'order_number' => 'ORD-0016'],
            [
                'tracking_code' => 'TNED6Q1W',
                'store_branch_id' => $branch2->id,
                'customer_id' => $customers[2]->id,
                'service_id' => $service2->id,
                'assigned_staff_id' => $lanangStaff->id,
                'total_amount' => 8200.00,
                'balance' => 4100.00,
                'payment_status' => 'partial',
                'status' => 'queued',
                'due_date' => now()->addDays(12)->format('Y-m-d'),
                'notes' => 'Materials verified and cut sheet prepared — queued on cutting table.',
                'intake_channel' => 'online',
                'fulfillment_type' => 'pickup',
                'custom_order_data' => [
                    'size' => 'Custom Fit',
                    'unit' => 'in',
                    'measurements' => [
                        'Chest' => 42,
                        'Waist' => 36,
                        'Hips' => 43,
                        'Shoulder' => 19,
                        'Sleeve' => 25.5,
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
            $jo1->id => [ // status: pending (Quotation Approval Gate)
                ['stage' => 'design', 'staff' => $staffUsers[0], 'assigned' => 1, 'completed' => null],
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
            $jo4->id => [ // status: sewing — cutting done, sewing in progress
                ['stage' => 'design', 'staff' => $staffUsers[2], 'assigned' => 9, 'completed' => 8],
                ['stage' => 'pattern_making', 'staff' => $staffUsers[2], 'assigned' => 8, 'completed' => 7],
                ['stage' => 'cutting', 'staff' => $staffUsers[2], 'assigned' => 7, 'completed' => 5],
                ['stage' => 'sewing', 'staff' => $staffUsers[2], 'assigned' => 5, 'completed' => null],
            ],
            $jo5->id => [ // status: confirmed — 50% deposit confirmed, queued for design
                ['stage' => 'design', 'staff' => $staffUsers[1], 'assigned' => 1, 'completed' => null],
            ],
            $jo6->id => [ // status: awaiting_materials — waiting customer cloth drop-off
                ['stage' => 'design', 'staff' => $lanangStaff, 'assigned' => 2, 'completed' => 1],
            ],
            $jo7->id => [ // status: design — sketch in progress
                ['stage' => 'design', 'staff' => $staffUsers[0], 'assigned' => 2, 'completed' => null],
            ],
            $jo8->id => [ // status: pattern_making — paper pattern drafting in progress
                ['stage' => 'design', 'staff' => $lanangStaff, 'assigned' => 3, 'completed' => 2],
                ['stage' => 'pattern_making', 'staff' => $lanangStaff, 'assigned' => 2, 'completed' => null],
            ],
            $jo9->id => [ // status: mass_cutting — bulk order, sublimation cutting (skipped Pattern Making)
                ['stage' => 'design', 'staff' => $lanangStaff, 'assigned' => 3, 'completed' => 2],
                ['stage' => 'cutting', 'staff' => $lanangStaff, 'assigned' => 2, 'completed' => null],
            ],
            $jo10->id => [ // status: ready_for_fitting — sewing done, ready for fitting appointment
                ['stage' => 'design', 'staff' => $staffUsers[1], 'assigned' => 8, 'completed' => 7],
                ['stage' => 'pattern_making', 'staff' => $staffUsers[1], 'assigned' => 7, 'completed' => 6],
                ['stage' => 'cutting', 'staff' => $staffUsers[1], 'assigned' => 6, 'completed' => 4],
                ['stage' => 'sewing', 'staff' => $staffUsers[1], 'assigned' => 4, 'completed' => 1],
            ],
            $jo11->id => [ // status: final_adjustments — post-fitting shoulder/waist alteration
                ['stage' => 'design', 'staff' => $staffUsers[0], 'assigned' => 10, 'completed' => 9],
                ['stage' => 'pattern_making', 'staff' => $staffUsers[0], 'assigned' => 9, 'completed' => 8],
                ['stage' => 'cutting', 'staff' => $staffUsers[0], 'assigned' => 8, 'completed' => 6],
                ['stage' => 'sewing', 'staff' => $staffUsers[0], 'assigned' => 6, 'completed' => 2],
            ],
            $jo12->id => [ // status: qc_ironing — final steam press & quality control inspection
                ['stage' => 'design', 'staff' => $lanangStaff, 'assigned' => 8, 'completed' => 7],
                ['stage' => 'pattern_making', 'staff' => $lanangStaff, 'assigned' => 7, 'completed' => 6],
                ['stage' => 'cutting', 'staff' => $lanangStaff, 'assigned' => 6, 'completed' => 4],
                ['stage' => 'sewing', 'staff' => $lanangStaff, 'assigned' => 4, 'completed' => 2],
                ['stage' => 'qc_ironing', 'staff' => $lanangStaff, 'assigned' => 1, 'completed' => null],
            ],
            $jo13->id => [ // status: in_repair — zipper & patch repair in progress
                ['stage' => 'sewing', 'staff' => $staffUsers[2], 'assigned' => 1, 'completed' => null],
            ],
            $jo14->id => [ // status: balance_handover — all stages closed out, ready at counter
                ['stage' => 'design', 'staff' => $staffUsers[0], 'assigned' => 10, 'completed' => 9],
                ['stage' => 'pattern_making', 'staff' => $staffUsers[0], 'assigned' => 9, 'completed' => 8],
                ['stage' => 'cutting', 'staff' => $staffUsers[0], 'assigned' => 8, 'completed' => 6],
                ['stage' => 'sewing', 'staff' => $staffUsers[0], 'assigned' => 6, 'completed' => 3],
                ['stage' => 'qc_ironing', 'staff' => $staffUsers[0], 'assigned' => 2, 'completed' => 1],
            ],
            $jo15->id => [ // status: completed — all stages closed out
                ['stage' => 'design', 'staff' => $staffUsers[1], 'assigned' => 14, 'completed' => 13],
                ['stage' => 'pattern_making', 'staff' => $staffUsers[1], 'assigned' => 13, 'completed' => 12],
                ['stage' => 'cutting', 'staff' => $staffUsers[1], 'assigned' => 12, 'completed' => 10],
                ['stage' => 'sewing', 'staff' => $staffUsers[1], 'assigned' => 10, 'completed' => 6],
                ['stage' => 'qc_ironing', 'staff' => $staffUsers[1], 'assigned' => 5, 'completed' => 4],
            ],
            $jo16->id => [ // status: queued — cut sheet prepared, queued on cutting floor
                ['stage' => 'design', 'staff' => $lanangStaff, 'assigned' => 2, 'completed' => 1],
                ['stage' => 'pattern_making', 'staff' => $lanangStaff, 'assigned' => 1, 'completed' => 0],
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

        // 12. Seed Payments with explicit types (deposit, balance, full)
        // ORD-0002: 50% deposit paid
        Payment::firstOrCreate(
            ['job_order_id' => $jo2->id, 'amount' => 3250.00],
            [
                'payment_method' => 'gcash',
                'type' => 'deposit',
                'source' => 'online',
                'verified_at' => now()->subDays(3),
                'verified_by' => $owner->id,
                'recorded_by' => $owner->id,
                'notes' => '50% initial deposit via GCash for bulk team orders.',
            ]
        );

        // ORD-0003: Full payment settled
        Payment::firstOrCreate(
            ['job_order_id' => $jo3->id, 'amount' => 12000.00],
            [
                'payment_method' => 'gcash',
                'type' => 'full',
                'source' => 'online',
                'verified_at' => now()->subDays(5),
                'verified_by' => $owner->id,
                'recorded_by' => $owner->id,
                'notes' => 'Full payment settled via GCash for bespoke corporate suit.',
            ]
        );

        // ORD-0004: Deposit paid
        Payment::firstOrCreate(
            ['job_order_id' => $jo4->id, 'amount' => 3250.00],
            [
                'payment_method' => 'cash',
                'type' => 'deposit',
                'source' => 'walk_in',
                'verified_at' => now()->subDays(4),
                'verified_by' => $owner->id,
                'recorded_by' => $owner->id,
                'notes' => '50% deposit settled in cash at Poblacion branch.',
            ]
        );

        // ORD-0005: Wedding Gown 50% deposit paid
        Payment::firstOrCreate(
            ['job_order_id' => $jo5->id, 'amount' => 14000.00],
            [
                'payment_method' => 'gcash',
                'type' => 'deposit',
                'source' => 'online',
                'verified_at' => now()->subDay(),
                'verified_by' => $owner->id,
                'recorded_by' => $owner->id,
                'notes' => '50% initial downpayment for bespoke wedding gown via GCash.',
            ]
        );

        // ORD-0006: Awaiting materials 50% deposit paid
        Payment::firstOrCreate(
            ['job_order_id' => $jo6->id, 'amount' => 3000.00],
            [
                'payment_method' => 'gcash',
                'type' => 'deposit',
                'source' => 'online',
                'verified_at' => now()->subDays(2),
                'verified_by' => $owner->id,
                'recorded_by' => $owner->id,
                'notes' => '50% initial deposit via GCash.',
            ]
        );

        // ORD-0007: Bespoke Gown 50% deposit paid
        Payment::firstOrCreate(
            ['job_order_id' => $jo7->id, 'amount' => 8000.00],
            [
                'payment_method' => 'cash',
                'type' => 'deposit',
                'source' => 'walk_in',
                'verified_at' => now()->subDays(2),
                'verified_by' => $owner->id,
                'recorded_by' => $owner->id,
                'notes' => '50% initial deposit in cash upon consultation.',
            ]
        );

        // ORD-0008: Blazer 50% deposit paid
        Payment::firstOrCreate(
            ['job_order_id' => $jo8->id, 'amount' => 4750.00],
            [
                'payment_method' => 'gcash',
                'type' => 'deposit',
                'source' => 'online',
                'verified_at' => now()->subDays(2),
                'verified_by' => $owner->id,
                'recorded_by' => $owner->id,
                'notes' => '50% downpayment via GCash.',
            ]
        );

        // ORD-0009: Bulk Sublimation 50% deposit paid
        Payment::firstOrCreate(
            ['job_order_id' => $jo9->id, 'amount' => 4875.00],
            [
                'payment_method' => 'gcash',
                'type' => 'deposit',
                'source' => 'online',
                'verified_at' => now()->subDays(3),
                'verified_by' => $owner->id,
                'recorded_by' => $owner->id,
                'notes' => '50% deposit for 15 volleyball jerseys.',
            ]
        );

        // ORD-0010: Suit with Fitting Gate 50% deposit paid
        Payment::firstOrCreate(
            ['job_order_id' => $jo10->id, 'amount' => 7000.00],
            [
                'payment_method' => 'gcash',
                'type' => 'deposit',
                'source' => 'online',
                'verified_at' => now()->subDays(4),
                'verified_by' => $owner->id,
                'recorded_by' => $owner->id,
                'notes' => '50% deposit for bespoke suit via GCash.',
            ]
        );

        // ORD-0011: Suit in Final Adjustments 50% deposit paid
        Payment::firstOrCreate(
            ['job_order_id' => $jo11->id, 'amount' => 7250.00],
            [
                'payment_method' => 'gcash',
                'type' => 'deposit',
                'source' => 'online',
                'verified_at' => now()->subDays(6),
                'verified_by' => $owner->id,
                'recorded_by' => $owner->id,
                'notes' => '50% deposit for custom suit.',
            ]
        );

        // ORD-0012: Suit in QC Ironing 50% deposit paid
        Payment::firstOrCreate(
            ['job_order_id' => $jo12->id, 'amount' => 5500.00],
            [
                'payment_method' => 'cash',
                'type' => 'deposit',
                'source' => 'walk_in',
                'verified_at' => now()->subDays(5),
                'verified_by' => $owner->id,
                'recorded_by' => $owner->id,
                'notes' => '50% deposit settled in cash.',
            ]
        );

        // ORD-0013: Repair paid in full
        Payment::firstOrCreate(
            ['job_order_id' => $jo13->id, 'amount' => 450.00],
            [
                'payment_method' => 'cash',
                'type' => 'full',
                'source' => 'walk_in',
                'verified_at' => now()->subDay(),
                'verified_by' => $owner->id,
                'recorded_by' => $owner->id,
                'notes' => 'Full payment in cash upon repair intake.',
            ]
        );

        // ORD-0014: Audited Order #14 — 50% deposit paid via GCash, remaining balance ₱8,250
        Payment::firstOrCreate(
            ['job_order_id' => $jo14->id, 'amount' => 8250.00],
            [
                'payment_method' => 'gcash',
                'type' => 'deposit',
                'source' => 'online',
                'verified_at' => now()->subDays(2),
                'verified_by' => $owner->id,
                'recorded_by' => $owner->id,
                'notes' => '50% downpayment via GCash for custom 3-piece charcoal suit.',
            ]
        );

        // ORD-0015: Completed Barong Tagalog — paid in full
        Payment::firstOrCreate(
            ['job_order_id' => $jo15->id, 'amount' => 13500.00],
            [
                'payment_method' => 'gcash',
                'type' => 'full',
                'source' => 'online',
                'verified_at' => now()->subDays(10),
                'verified_by' => $owner->id,
                'recorded_by' => $owner->id,
                'notes' => 'Full payment via GCash for Graduation Barong Tagalog.',
            ]
        );

        // ORD-0016: Queued Order — 50% deposit paid
        Payment::firstOrCreate(
            ['job_order_id' => $jo16->id, 'amount' => 4100.00],
            [
                'payment_method' => 'gcash',
                'type' => 'deposit',
                'source' => 'online',
                'verified_at' => now()->subDay(),
                'verified_by' => $owner->id,
                'recorded_by' => $owner->id,
                'notes' => '50% initial deposit via GCash.',
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

        // 15. Keep discounts and rush fees in demo data for customer receipt and audit verification (Item 7 & 8)

        $barongService = Service::where('name', 'Barong Tagalog Tailoring')->first();

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

        // Joshua's (the developer's) own measurement profiles for itzjoshuawayman@gmail.com
        $devUser = User::where('email', 'itzjoshuawayman@gmail.com')->first();
        if ($devUser) {
            Measurement::updateOrCreate(
                ['store_id' => $store->id, 'customer_id' => $devUser->id, 'profile_name' => 'Bespoke Suit & Barong', 'version' => 1],
                [
                    'source' => 'store_owner',
                    'metrics' => [
                        'Chest' => '39', 'Shoulder' => '17.5', 'Sleeve' => '24.5', 'Neck' => '15.5',
                        'Waist' => '32', 'Hip' => '39', 'Inseam' => '30',
                    ],
                    'notes' => 'Measured by head tailor — comfortable fit with standard posture.',
                    'superseded_at' => null,
                ]
            );

            $sakuragiStore = Store::find(4) ?? Store::where('name', 'like', '%Sakuragi%')->first();
            if ($sakuragiStore) {
                $sakuragiStore->update([
                    'social_links' => [
                        ['label' => 'Facebook', 'url' => 'https://www.facebook.com/sakuragi.tailoring'],
                    ],
                ]);
                Measurement::updateOrCreate(
                    ['store_id' => $sakuragiStore->id, 'customer_id' => $devUser->id, 'profile_name' => 'Polo Shirt & Corporate Casual', 'version' => 1],
                    [
                        'source' => 'store_owner',
                        'status' => 'finalized',
                        'metrics' => [
                            'Chest' => '39', 'Shoulder' => '17.5', 'Sleeve Length' => '9.5',
                            'Shirt Length' => '28', 'Neck' => '16', 'Armhole' => '18',
                        ],
                        'notes' => 'Custom polo shirt fitting — tailored fit, comfortable shoulder posture.',
                        'superseded_at' => null,
                    ]
                );
            }
        }

        // 18b. Seed Joshua's (itzjoshuawayman@gmail.com) job orders — 6 clean orders
        // distributed across all 5 customer order tabs (All, In Production,
        // Ready for Pickup, Completed, Cancelled). These are soft-deleted or
        // cancelled in a targeted way so the account never floods with 15+
        // duplicate test orders on a fresh seed.
        $devUserForOrders = User::where('email', 'itzjoshuawayman@gmail.com')->first();
        if ($devUserForOrders) {
            $joshuaCustomer = $store->customers()->where('user_id', $devUserForOrders->id)->first() ?? $devUserForOrders;
            $joshuaCustomerId = $joshuaCustomer instanceof \App\Models\Customer
                ? $joshuaCustomer->id
                : $store->customers()->where('user_id', $devUserForOrders->id)->value('id');
            // Fallback: look up customer record by user_id directly
            if (!$joshuaCustomerId) {
                $joshuaCustomerId = \App\Models\Customer::where('user_id', $devUserForOrders->id)->value('id');
            }

            $embroideryService = Service::find(14) ?? $service1;        // Quality Embroidery & Custom Polo
            $alterationService2 = Service::where('name', 'Alteration and Repairs for Barong')->first() ?? $repairService;

            if ($joshuaCustomerId) {
                // 1. Completed — ORD-0001: Custom Polo Shirt, GCash paid, with measurements
                JobOrder::updateOrCreate(
                    ['store_id' => $sakuragiStore?->id ?? $store->id, 'order_number' => 'ORD-0001'],
                    [
                        'tracking_code' => 'TNED8K2P',
                        'store_branch_id' => $mainBranch->id,
                        'customer_id' => $joshuaCustomerId,
                        'service_id' => $embroideryService->id,
                        'total_amount' => 650.00,
                        'balance' => 0.00,
                        'payment_status' => 'paid',
                        'status' => 'completed',
                        'garment_category' => 'polo_shirt',
                        'due_date' => now()->subDays(30)->format('Y-m-d'),
                        'notes' => 'Custom polo shirt with embroidered logo — completed and collected.',
                        'intake_channel' => 'online',
                        'fulfillment_type' => 'pickup',
                    ]
                );

                // 2. In Production — ORD-0002: Bespoke barong in Pattern Making
                JobOrder::updateOrCreate(
                    ['store_id' => $store->id, 'order_number' => 'ORD-0002'],
                    [
                        'tracking_code' => 'TNED4M7B',
                        'store_branch_id' => $mainBranch->id,
                        'customer_id' => $joshuaCustomerId,
                        'service_id' => $barongService->id,
                        'total_amount' => 4500.00,
                        'balance' => 2250.00,
                        'payment_status' => 'partial',
                        'status' => 'pattern_making',
                        'due_date' => now()->addDays(10)->format('Y-m-d'),
                        'notes' => 'Piña Seda barong — in pattern making stage.',
                        'intake_channel' => 'online',
                        'fulfillment_type' => 'pickup',
                    ]
                );

                // 3. In Production — ORD-0003: Pending confirmation
                JobOrder::updateOrCreate(
                    ['store_id' => $store->id, 'order_number' => 'ORD-0003'],
                    [
                        'tracking_code' => 'TNED9C3X',
                        'store_branch_id' => $mainBranch->id,
                        'customer_id' => $joshuaCustomerId,
                        'service_id' => $barongService->id,
                        'total_amount' => 3200.00,
                        'balance' => 3200.00,
                        'payment_status' => 'unpaid',
                        'status' => 'pending',
                        'due_date' => now()->addDays(21)->format('Y-m-d'),
                        'notes' => 'Corporate barong — awaiting design approval.',
                        'intake_channel' => 'online',
                        'fulfillment_type' => 'pickup',
                    ]
                );

                // 4. Ready for Pickup — ORD-0005: Balance handover
                JobOrder::updateOrCreate(
                    ['store_id' => $store->id, 'order_number' => 'ORD-0005'],
                    [
                        'tracking_code' => 'TNED5P8R',
                        'store_branch_id' => $mainBranch->id,
                        'customer_id' => $joshuaCustomerId,
                        'service_id' => $barongService->id,
                        'total_amount' => 2800.00,
                        'balance' => 1400.00,
                        'payment_status' => 'partial',
                        'status' => 'balance_handover',
                        'due_date' => now()->addDays(2)->format('Y-m-d'),
                        'notes' => 'Barong ready — customer to settle balance on pickup.',
                        'intake_channel' => 'online',
                        'fulfillment_type' => 'pickup',
                    ]
                );

                // 5. Cancelled — ORD-0011: Customer changed mind before production
                JobOrder::updateOrCreate(
                    ['store_id' => $store->id, 'order_number' => 'ORD-0011'],
                    [
                        'tracking_code' => 'TNED1Q7W',
                        'store_branch_id' => $mainBranch->id,
                        'customer_id' => $joshuaCustomerId,
                        'service_id' => $barongService->id,
                        'total_amount' => 2200.00,
                        'balance' => 2200.00,
                        'payment_status' => 'unpaid',
                        'status' => 'cancelled',
                        'cancellation_reason' => 'customer_request',
                        'due_date' => now()->subDays(5)->format('Y-m-d'),
                        'notes' => 'Customer cancelled — changed design preference before production started.',
                        'intake_channel' => 'online',
                        'fulfillment_type' => 'pickup',
                    ]
                );

                // 6. In Production (Alteration) — ORD-0012: Alteration in progress
                JobOrder::updateOrCreate(
                    ['store_id' => $store->id, 'order_number' => 'ORD-0012'],
                    [
                        'tracking_code' => 'TNED2A5L',
                        'store_branch_id' => $mainBranch->id,
                        'customer_id' => $joshuaCustomerId,
                        'service_id' => $alterationService2->id,
                        'total_amount' => 350.00,
                        'balance' => 0.00,
                        'payment_status' => 'paid',
                        'status' => 'in_repair',
                        'garment_category' => 'alteration',
                        'due_date' => now()->addDays(3)->format('Y-m-d'),
                        'notes' => 'Waist adjustment on existing barong — alteration in progress.',
                        'intake_channel' => 'walk_in',
                        'fulfillment_type' => 'pickup',
                    ]
                );

                // Soft-delete all other Joshua orders that are not in the 6 above
                // so a re-seed never accumulates duplicate test clutter.
                $keepNumbers = ['ORD-0001', 'ORD-0002', 'ORD-0003', 'ORD-0005', 'ORD-0011', 'ORD-0012'];
                JobOrder::where('customer_id', $joshuaCustomerId)
                    ->whereNotIn('order_number', $keepNumbers)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => now()]);
            }
        }

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

        // Staff-filed ticket: proves the dashboard support page's non-owner
        // scoping (the staff account sees only this row, never the owner's
        // two) and gives the admin ticket list a "Staff" label to show.
        SupportTicket::updateOrCreate(
            ['store_id' => $store->id, 'subject' => 'Progress photos keep timing out at the branch'],
            [
                'user_id' => $staff->id,
                'message' => "Every time I upload a progress photo from the branch phone the upload times out and I have to re-take the shot. It's slowing down the QC step — can someone check?",
                'type' => 'problem',
                'priority' => 'medium',
                'status' => 'in_progress',
                'assigned_to' => $admin->id,
            ]
        );

        // Customer "Report This Product" — lands exactly like a live report
        // (CatalogInteractionController::report()), so the reporting
        // customer's My Support Tickets list isn't empty on a fresh seed
        // and the admin queue shows a Customer row.
        $reportTicket = SupportTicket::updateOrCreate(
            ['store_id' => $store->id, 'subject' => 'Product Report: '.$item1->name],
            [
                'user_id' => $customers[0]->id,
                'message' => "Reason: Copyright\n\nThis design looks exactly like one I've seen from another brand — please check.",
                'type' => 'product_report',
                'catalog_item_id' => $item1->id,
                'priority' => 'medium',
                'status' => 'open',
            ]
        );
        SupportTicketReply::updateOrCreate(
            ['ticket_id' => $reportTicket->id, 'message' => 'Thanks — we\'ve received your report and will look into it.'],
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

    }
}
