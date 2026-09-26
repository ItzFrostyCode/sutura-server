<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\CatalogImage;
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
use App\Models\StoreReview;
use App\Models\StoreSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * LocalTestSeeder only ever built ONE store-owner tenant (owner@sutura.com /
 * Thread & Needle Tailoring). Every CRUD/logic audit this whole project has
 * only ever exercised store-scoping by coincidence — a bug that filters by
 * the wrong column, or forgets a store_id check entirely, would never surface
 * with just one tenant in the database, since every query would happen to
 * return the "right" data whether or not the scoping clause is even doing
 * anything. This adds two more fully independent store-owner tenants, each
 * with their own branches/staff/customers/jobs/appointments/payments/catalog,
 * specifically so isolation between stores can actually be tested for real.
 */
class AdditionalStoresSeeder extends Seeder
{
    public function run(): void
    {
        $ownerRole = Role::where('name', 'store_owner')->first();
        $staffRole = Role::where('name', 'staff')->first();
        $customerRole = Role::where('name', 'customer')->first();
        $premiumPlan = SubscriptionPlan::where('slug', 'premium')->first();
        $basicPlan = SubscriptionPlan::where('slug', 'basic')->first();

        $this->seedStoreTwo($ownerRole, $staffRole, $customerRole, $basicPlan);
        $this->seedStoreThree($ownerRole, $staffRole, $customerRole, $premiumPlan);
    }

    /**
     * Store #2 — "Davao Formal Wear Co.", 2 branches (Toril + Bajada), formal/
     * corporate specialty, Basic plan (deliberately different tier than store
     * #1's Premium, to exercise tier-gating with a real non-Premium tenant).
     */
    private function seedStoreTwo($ownerRole, $staffRole, $customerRole, $basicPlan): void
    {
        $owner = User::firstOrCreate(
            ['email' => 'ricardo@sutura.com'],
            ['name' => 'Ricardo Santos', 'password' => Hash::make('password'), 'email_verified_at' => now()]
        );
        if (! $owner->roles()->where('role_id', $ownerRole->id)->exists()) {
            $owner->roles()->attach($ownerRole->id);
        }

        $store = Store::firstOrCreate(
            ['owner_id' => $owner->id],
            [
                'name' => 'Davao Formal Wear Co.',
                'slug' => 'davao-formal-wear',
                'description' => 'Corporate and formal wear specialists — suits, barongs, and office uniforms for Davao City businesses.',
                'address' => '45 Quimpo Blvd, Toril',
                'city' => 'Davao City',
                'province' => 'Davao del Sur',
                'email' => 'hello@davaoformalwear.com',
                'phone' => '+639171234567',
                'status' => 'approved',
                'approved_at' => now(),
                'operating_hours' => [
                    'monday' => ['is_open' => true, 'open' => '08:00', 'close' => '17:00'],
                    'tuesday' => ['is_open' => true, 'open' => '08:00', 'close' => '17:00'],
                    'wednesday' => ['is_open' => true, 'open' => '08:00', 'close' => '17:00'],
                    'thursday' => ['is_open' => true, 'open' => '08:00', 'close' => '17:00'],
                    'friday' => ['is_open' => true, 'open' => '08:00', 'close' => '17:00'],
                    'saturday' => ['is_open' => true, 'open' => '09:00', 'close' => '14:00'],
                    'sunday' => ['is_open' => false, 'open' => '09:00', 'close' => '14:00'],
                ],
                'logo_path' => \Illuminate\Support\Facades\Storage::url('logos/bautista_tailors_logo.jpg'),
                'banner_path' => \Illuminate\Support\Facades\Storage::url('banners/bautista_tailors_banner.jpg'),
            ]
        );

        if ($basicPlan && ! $store->subscription()->exists()) {
            StoreSubscription::create([
                'store_id' => $store->id,
                'plan_id' => $basicPlan->id,
                'status' => 'active',
                'starts_at' => now()->subDays(20),
                'ends_at' => now()->addDays(10),
            ]);
        }

        $mainBranch = StoreBranch::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Toril Main Branch'],
            [
                'slug' => Str::slug('Toril Main Branch').'-'.uniqid(),
                'address' => '45 Quimpo Blvd, Toril',
                'city' => 'Davao City',
                'latitude' => 6.9814,
                'longitude' => 125.4964,
                'contact_number' => '+63 917 123 4567',
                'is_main' => true,
            ]
        );
        $bajadaBranch = StoreBranch::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Bajada Branch'],
            [
                'slug' => Str::slug('Bajada Branch').'-'.uniqid(),
                'address' => 'JP Laurel Ave, Bajada',
                'city' => 'Davao City',
                'latitude' => 7.1004,
                'longitude' => 125.6134,
                'contact_number' => '+63 917 555 8899',
                'is_main' => false,
            ]
        );

        // Staff — 2, each tied to a different branch of THIS store only.
        $staff1 = User::firstOrCreate(
            ['email' => 'lito.cruz@davaoformalwear.com'],
            ['name' => 'Lito Cruz', 'password' => Hash::make('password'), 'email_verified_at' => now()]
        );
        if (! $staff1->roles()->where('role_id', $staffRole->id)->exists()) {
            $staff1->roles()->attach($staffRole->id);
        }
        if (! $staff1->staffProfile()->exists()) {
            StaffProfile::create([
                'user_id' => $staff1->id, 'store_id' => $store->id, 'store_branch_id' => $mainBranch->id,
                'role' => 'head_tailor', 'is_branch_manager' => true,
            ]);
        }

        $staff2 = User::firstOrCreate(
            ['email' => 'grace.uy@davaoformalwear.com'],
            ['name' => 'Grace Uy', 'password' => Hash::make('password'), 'email_verified_at' => now()]
        );
        if (! $staff2->roles()->where('role_id', $staffRole->id)->exists()) {
            $staff2->roles()->attach($staffRole->id);
        }
        if (! $staff2->staffProfile()->exists()) {
            StaffProfile::create([
                'user_id' => $staff2->id, 'store_id' => $store->id, 'store_branch_id' => $bajadaBranch->id,
                'role' => 'cutter_sewer',
            ]);
        }

        // Services
        $suitService = Service::updateOrCreate(
            ['store_id' => $store->id, 'name' => 'Corporate Suit Tailoring'],
            [
                'description' => 'Made-to-measure business suits for corporate clients, priced per fabric grade.',
                'category' => 'Custom Tailoring & Bespoke',
                'categories' => ['Suit & Tuxedo Tailoring'],
                'service_types' => ['custom_tailoring'],
                'base_price' => 3200,
                'estimated_days' => 12,
                'is_active' => true,
                'tags' => ['Poly-Wool Blend', 'Premium Wool'],
            ]
        );
        ServicePricing::updateOrCreate(['service_id' => $suitService->id, 'label' => 'Poly-Wool Blend'], ['amount' => 3200]);
        ServicePricing::updateOrCreate(['service_id' => $suitService->id, 'label' => 'Premium Wool'], ['amount' => 5800]);

        $uniformService = Service::updateOrCreate(
            ['store_id' => $store->id, 'name' => 'Office Uniform Sewing'],
            [
                'description' => 'Bulk office uniform sets, sized per employee roster.',
                'category' => 'Institutional & Uniform Wear',
                'categories' => ['Corporate & Team Uniforms'],
                'service_types' => ['bulk_sublimation'],
                'base_price' => 900,
                'min_order_qty' => 5,
                'estimated_days' => 15,
                'is_active' => true,
                'tags' => ['Standard Set'],
            ]
        );
        ServicePricing::updateOrCreate(['service_id' => $uniformService->id, 'label' => 'Standard Set'], ['amount' => 900]);

        // Customers — belong ONLY to this store
        $custNames = [
            ['email' => 'ferdie.marasigan@example.com', 'name' => 'Ferdie Marasigan'],
            ['email' => 'gina.lopez@example.com', 'name' => 'Gina Lopez'],
            ['email' => 'tonyo.cruz@example.com', 'name' => 'Tonyo Cruz'],
        ];
        $customers = [];
        foreach ($custNames as $c) {
            $u = User::firstOrCreate(['email' => $c['email']], ['name' => $c['name'], 'password' => Hash::make('password'), 'email_verified_at' => now()]);
            if (! $u->roles()->where('role_id', $customerRole->id)->exists()) {
                $u->roles()->attach($customerRole->id);
            }
            $store->customers()->syncWithoutDetaching([$u->id]);
            $customers[] = $u;
        }

        // Appointments — mixed statuses across both branches
        Appointment::updateOrCreate(
            ['store_id' => $store->id, 'customer_id' => $customers[0]->id, 'service_id' => $suitService->id, 'status' => 'confirmed'],
            ['store_branch_id' => $mainBranch->id, 'scheduled_at' => now()->addDays(3)->format('Y-m-d H:i:s'), 'appointment_type' => 'measurement', 'intake_channel' => 'walk_in', 'duration_minutes' => 60]
        );
        Appointment::updateOrCreate(
            ['store_id' => $store->id, 'customer_id' => $customers[1]->id, 'service_id' => $uniformService->id, 'status' => 'pending'],
            ['store_branch_id' => $bajadaBranch->id, 'scheduled_at' => now()->addDays(5)->format('Y-m-d H:i:s'), 'appointment_type' => 'consultation', 'intake_channel' => 'online', 'duration_minutes' => 30]
        );

        // Job Orders — mixed statuses, tied to specific branches/staff of THIS store only
        $jo1 = JobOrder::firstOrCreate(
            ['store_id' => $store->id, 'order_number' => 'DFW-1001'],
            [
                'store_branch_id' => $mainBranch->id, 'customer_id' => $customers[0]->id, 'service_id' => $suitService->id,
                'assigned_staff_id' => $staff1->id, 'total_amount' => 5800.00, 'balance' => 2900.00,
                'payment_status' => 'partial', 'status' => 'sewing', 'due_date' => now()->addDays(8)->format('Y-m-d'),
                'notes' => 'Premium wool 2-piece suit', 'intake_channel' => 'walk_in', 'fulfillment_type' => 'pickup',
            ]
        );
        $jo2 = JobOrder::firstOrCreate(
            ['store_id' => $store->id, 'order_number' => 'DFW-1002'],
            [
                'store_branch_id' => $bajadaBranch->id, 'customer_id' => $customers[1]->id, 'service_id' => $uniformService->id,
                'assigned_staff_id' => $staff2->id, 'total_amount' => 9000.00, 'balance' => 9000.00,
                'payment_status' => 'unpaid', 'status' => 'pending', 'due_date' => now()->addDays(15)->format('Y-m-d'),
                'notes' => '10-set office uniform roster', 'intake_channel' => 'online', 'fulfillment_type' => 'pickup',
                'custom_order_data' => [
                    'team_name' => 'Bajada Realty Corp',
                    'team_roster' => [
                        ['name' => 'Employee A', 'print_name' => '', 'number' => '', 'size' => 'M'],
                        ['name' => 'Employee B', 'print_name' => '', 'number' => '', 'size' => 'L'],
                    ],
                ],
            ]
        );
        $jo3 = JobOrder::firstOrCreate(
            ['store_id' => $store->id, 'order_number' => 'DFW-1003'],
            [
                'store_branch_id' => $mainBranch->id, 'customer_id' => $customers[2]->id, 'service_id' => $suitService->id,
                'assigned_staff_id' => $staff1->id, 'total_amount' => 3200.00, 'balance' => 0.00,
                'payment_status' => 'paid', 'status' => 'completed', 'due_date' => now()->subDays(3)->format('Y-m-d'),
                'notes' => 'Standard poly-wool suit — released', 'intake_channel' => 'walk_in', 'fulfillment_type' => 'pickup',
            ]
        );

        foreach ([$jo1->id => $staff1, $jo3->id => $staff1] as $jobId => $staffUser) {
            JobOrderStaff::updateOrCreate(
                ['job_order_id' => $jobId, 'user_id' => $staffUser->id, 'stage' => 'cutting'],
                ['assigned_at' => now()->subDays(3), 'completed_at' => now()->subDays(1)]
            );
        }

        Payment::firstOrCreate(
            ['job_order_id' => $jo1->id, 'amount' => 2900.00],
            ['payment_method' => 'gcash', 'recorded_by' => $owner->id, 'notes' => 'Downpayment for suit.']
        );
        Payment::firstOrCreate(
            ['job_order_id' => $jo3->id, 'amount' => 3200.00],
            ['payment_method' => 'cash', 'recorded_by' => $owner->id, 'notes' => 'Paid in full on pickup.']
        );

        // Catalog items — reuse existing static image assets (already on disk
        // in sutura-client/public/catalog/), but as genuinely separate rows
        // scoped to THIS store, priced/named for a formal-wear specialty.
        $catalogSeed = [
            ['name' => 'Classic Navy Business Suit', 'price' => 5800, 'material' => 'Premium Wool', 'garment_type' => 'suit', 'image' => 'tailor-made-suit-bespoke.jpg'],
            ['name' => 'Double-Breasted Pinstripe Suit', 'price' => 6500, 'material' => 'Premium Wool', 'garment_type' => 'suit', 'image' => 'Custom Tuxedos for Memorable Events.jpeg'],
            ['name' => 'Barong Tagalog — Office Formal', 'price' => 3200, 'material' => 'Jusi Fabric', 'garment_type' => 'barong', 'image' => 'Traditional Barong Tagalog Polo Shirt for Men.jpeg'],
            ['name' => 'Corporate Office Blazer', 'price' => 4200, 'material' => 'Poly-Wool Blend', 'garment_type' => 'suit', 'image' => 'Bespoke_Suits.png'],
            ['name' => 'Standard Office Uniform Set', 'price' => 900, 'material' => 'Poly-Cotton', 'garment_type' => 'uniform', 'image' => 'Esports-Jersey-women.jpg'],
        ];
        foreach ($catalogSeed as $c) {
            $item = CatalogItem::updateOrCreate(
                ['store_id' => $store->id, 'name' => $c['name']],
                [
                    'price' => $c['price'], 'material' => $c['material'], 'garment_type' => $c['garment_type'],
                    'estimated_days' => 10, 'listing_type' => 'made_to_order', 'is_active' => true,
                    'description' => $c['name'].' — made to order, tailored to your measurements.',
                ]
            );
            CatalogImage::firstOrCreate(
                ['catalog_item_id' => $item->id, 'image_url' => '/catalog/'.$c['image']],
                ['view_angle' => 'front', 'is_primary' => true]
            );
        }

        StoreReview::firstOrCreate(
            ['store_id' => $store->id, 'user_id' => $customers[2]->id],
            ['rating' => 5, 'comment' => 'Sharp, professional suits. Delivered on time for our company event.', 'is_featured' => true]
        );
        StoreReview::firstOrCreate(
            ['store_id' => $store->id, 'user_id' => $customers[0]->id],
            ['rating' => 4, 'comment' => 'Good quality, turnaround took a bit longer than quoted.', 'is_featured' => false]
        );

        Measurement::updateOrCreate(
            ['store_id' => $store->id, 'customer_id' => $customers[0]->id, 'profile_name' => 'Default', 'version' => 1],
            [
                'source' => 'store_owner',
                'metrics' => ['Chest' => '41', 'Waist' => '35', 'Shoulder' => '19', 'Sleeve' => '26'],
                'notes' => 'Standard corporate fit.',
                'superseded_at' => null,
            ]
        );
    }

    /**
     * Store #3 — "Fely's Alterations & Uniforms", deliberately the smallest
     * tenant: 1 branch only, 1 staff, no Premium features exercised. Tests
     * that the system holds up for a minimal/starter store, not just a
     * fully-loaded one.
     */
    private function seedStoreThree($ownerRole, $staffRole, $customerRole, $premiumPlan): void
    {
        $owner = User::firstOrCreate(
            ['email' => 'fely@sutura.com'],
            ['name' => 'Fely Aquino', 'password' => Hash::make('password'), 'email_verified_at' => now()]
        );
        if (! $owner->roles()->where('role_id', $ownerRole->id)->exists()) {
            $owner->roles()->attach($ownerRole->id);
        }

        $store = Store::firstOrCreate(
            ['owner_id' => $owner->id],
            [
                'name' => "Fely's Alterations & Uniforms",
                'slug' => 'felys-alterations',
                'description' => 'Neighborhood alterations store specializing in school uniforms and everyday clothing repairs.',
                'address' => 'Buhangin Road, Buhangin',
                'city' => 'Davao City',
                'province' => 'Davao del Sur',
                'email' => 'fely.alterations@example.com',
                'phone' => '+639285551234',
                'status' => 'approved',
                'approved_at' => now(),
                'operating_hours' => [
                    'monday' => ['is_open' => true, 'open' => '08:00', 'close' => '18:00'],
                    'tuesday' => ['is_open' => true, 'open' => '08:00', 'close' => '18:00'],
                    'wednesday' => ['is_open' => true, 'open' => '08:00', 'close' => '18:00'],
                    'thursday' => ['is_open' => true, 'open' => '08:00', 'close' => '18:00'],
                    'friday' => ['is_open' => true, 'open' => '08:00', 'close' => '18:00'],
                    'saturday' => ['is_open' => true, 'open' => '08:00', 'close' => '18:00'],
                    'sunday' => ['is_open' => true, 'open' => '08:00', 'close' => '12:00'],
                ],
                'logo_path' => \Illuminate\Support\Facades\Storage::url('logos/villanueva_atelier_logo.jpg'),
                'banner_path' => \Illuminate\Support\Facades\Storage::url('banners/villanueva_atelier_banner.jpg'),
            ]
        );

        // Deliberately no StoreSubscription row — exercises the "no active
        // plan" / free-tier path the Billing page and tier-gating both need
        // to handle without crashing.

        $mainBranch = StoreBranch::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Buhangin Branch'],
            [
                'slug' => Str::slug('Buhangin Branch').'-'.uniqid(),
                'address' => 'Buhangin Road, Buhangin',
                'city' => 'Davao City',
                'latitude' => 7.1197,
                'longitude' => 125.6294,
                'contact_number' => '+63 928 555 1234',
                'is_main' => true,
            ]
        );

        $staff1 = User::firstOrCreate(
            ['email' => 'nena.dela.cruz@felys.example.com'],
            ['name' => 'Nena Dela Cruz', 'password' => Hash::make('password'), 'email_verified_at' => now()]
        );
        if (! $staff1->roles()->where('role_id', $staffRole->id)->exists()) {
            $staff1->roles()->attach($staffRole->id);
        }
        if (! $staff1->staffProfile()->exists()) {
            StaffProfile::create([
                'user_id' => $staff1->id, 'store_id' => $store->id, 'store_branch_id' => $mainBranch->id,
                'role' => 'seamstress',
            ]);
        }

        $alterationService = Service::updateOrCreate(
            ['store_id' => $store->id, 'name' => 'Clothing Alterations & Repairs'],
            [
                'description' => 'Hemming, resizing, zipper replacement, and general repair work.',
                'category' => 'Alterations & Adjustments',
                'categories' => ['General Clothing Alterations'],
                'service_types' => ['alteration_repair'],
                'base_price' => 150,
                'estimated_days' => 3,
                'is_active' => true,
                'tags' => ['Hem Pants', 'Zipper Replacement'],
            ]
        );
        ServicePricing::updateOrCreate(['service_id' => $alterationService->id, 'label' => 'Hem Pants'], ['amount' => 150]);
        ServicePricing::updateOrCreate(['service_id' => $alterationService->id, 'label' => 'Zipper Replacement'], ['amount' => 250]);

        $uniformService = Service::updateOrCreate(
            ['store_id' => $store->id, 'name' => 'School Uniform Sewing'],
            [
                'description' => 'Elementary and high school uniform sets, sewn per student.',
                'category' => 'Institutional & Uniform Wear',
                'categories' => ['School Uniforms'],
                'service_types' => ['bulk_sublimation'],
                'base_price' => 450,
                'estimated_days' => 7,
                'is_active' => true,
                'tags' => ['Elementary Set', 'High School Set'],
            ]
        );
        ServicePricing::updateOrCreate(['service_id' => $uniformService->id, 'label' => 'Elementary Set'], ['amount' => 450]);
        ServicePricing::updateOrCreate(['service_id' => $uniformService->id, 'label' => 'High School Set'], ['amount' => 650]);

        $custNames = [
            ['email' => 'rowena.tan@example.com', 'name' => 'Rowena Tan'],
            ['email' => 'benjie.uy@example.com', 'name' => 'Benjie Uy'],
        ];
        $customers = [];
        foreach ($custNames as $c) {
            $u = User::firstOrCreate(['email' => $c['email']], ['name' => $c['name'], 'password' => Hash::make('password'), 'email_verified_at' => now()]);
            if (! $u->roles()->where('role_id', $customerRole->id)->exists()) {
                $u->roles()->attach($customerRole->id);
            }
            $store->customers()->syncWithoutDetaching([$u->id]);
            $customers[] = $u;
        }

        Appointment::updateOrCreate(
            ['store_id' => $store->id, 'customer_id' => $customers[0]->id, 'service_id' => $alterationService->id, 'status' => 'pending'],
            ['store_branch_id' => $mainBranch->id, 'scheduled_at' => now()->addDays(1)->format('Y-m-d H:i:s'), 'appointment_type' => 'alteration', 'intake_channel' => 'walk_in', 'duration_minutes' => 30]
        );

        $jo1 = JobOrder::firstOrCreate(
            ['store_id' => $store->id, 'order_number' => 'FA-1001'],
            [
                'store_branch_id' => $mainBranch->id, 'customer_id' => $customers[0]->id, 'service_id' => $alterationService->id,
                'assigned_staff_id' => $staff1->id, 'total_amount' => 250.00, 'balance' => 0.00,
                'payment_status' => 'paid', 'status' => 'completed', 'due_date' => now()->subDays(1)->format('Y-m-d'),
                'notes' => 'Zipper replacement on jacket', 'intake_channel' => 'walk_in', 'fulfillment_type' => 'pickup',
            ]
        );
        $jo2 = JobOrder::firstOrCreate(
            ['store_id' => $store->id, 'order_number' => 'FA-1002'],
            [
                'store_branch_id' => $mainBranch->id, 'customer_id' => $customers[1]->id, 'service_id' => $uniformService->id,
                'assigned_staff_id' => $staff1->id, 'total_amount' => 1950.00, 'balance' => 1950.00,
                'payment_status' => 'unpaid', 'status' => 'cutting', 'due_date' => now()->addDays(6)->format('Y-m-d'),
                'notes' => '3-set elementary uniform order', 'intake_channel' => 'walk_in', 'fulfillment_type' => 'pickup',
            ]
        );

        Payment::firstOrCreate(
            ['job_order_id' => $jo1->id, 'amount' => 250.00],
            ['payment_method' => 'cash', 'recorded_by' => $owner->id, 'notes' => 'Paid on pickup.']
        );

        $catalogSeed = [
            ['name' => 'Elementary Uniform Set', 'price' => 450, 'material' => 'Poly-Cotton', 'garment_type' => 'uniform', 'image' => 'VBALL_PRE-2001_800x800.webp'],
            ['name' => 'High School PE Uniform', 'price' => 650, 'material' => 'Drifit Mesh', 'garment_type' => 'uniform', 'image' => 'volleyballroundneckSET.webp'],
            ['name' => 'Simple Alteration Reference — Hemline', 'price' => 150, 'material' => 'N/A', 'garment_type' => 'other', 'image' => 'Riders_Long_Sleeves.jpg'],
        ];
        foreach ($catalogSeed as $c) {
            $item = CatalogItem::updateOrCreate(
                ['store_id' => $store->id, 'name' => $c['name']],
                [
                    'price' => $c['price'], 'material' => $c['material'], 'garment_type' => $c['garment_type'],
                    'estimated_days' => 5, 'listing_type' => 'made_to_order', 'is_active' => true,
                    'description' => $c['name'].' — made to order.',
                ]
            );
            CatalogImage::firstOrCreate(
                ['catalog_item_id' => $item->id, 'image_url' => '/catalog/'.$c['image']],
                ['view_angle' => 'front', 'is_primary' => true]
            );
        }

        // One walk-in catalog order, in a non-terminal status so the Walk-in
        // Orders "Cancel" fix (ready -> cancelled) has a real row to test.
        $firstItem = CatalogItem::where('store_id', $store->id)->first();
        if ($firstItem) {
            CatalogOrder::updateOrCreate(
                ['store_id' => $store->id, 'catalog_item_id' => $firstItem->id, 'customer_id' => $customers[1]->id, 'status' => 'ready'],
                [
                    'type' => 'walkin', 'total_amount' => $firstItem->price, 'payment_status' => 'paid',
                    'payment_method' => 'cash', 'intake_channel' => 'walk_in', 'fulfillment_type' => 'pickup',
                ]
            );
        }

        StoreReview::firstOrCreate(
            ['store_id' => $store->id, 'user_id' => $customers[0]->id],
            ['rating' => 5, 'comment' => 'Mabilis at maganda ang tahi. Sulit na sulit!', 'is_featured' => true]
        );
    }
}
