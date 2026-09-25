<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renames the core "Shop" domain entity to "Store" across the live schema
 * — tables, FK columns, and the handful of stored string values that mirror
 * it (the shop_owner role, and two enum-like columns). Written as raw SQL
 * (RENAME TABLE / RENAME COLUMN) rather than Schema::table()->renameColumn()
 * to avoid a doctrine/dbal dependency on the rename path; MySQL 8.0+
 * supports both directly. Foreign key constraints themselves are left as-is
 * — renaming a column preserves the constraint, it just keeps its old
 * auto-generated name (e.g. `catalog_items_shop_id_foreign`), which is
 * purely cosmetic and never referenced by app code.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Tables first, so later column renames reference the new names.
        DB::statement('RENAME TABLE shops TO stores');
        DB::statement('RENAME TABLE shop_branches TO store_branches');
        DB::statement('RENAME TABLE shop_customers TO store_customers');
        DB::statement('RENAME TABLE shop_posts TO store_posts');
        DB::statement('RENAME TABLE shop_reviews TO store_reviews');
        DB::statement('RENAME TABLE shop_special_hours TO store_special_hours');
        DB::statement('RENAME TABLE shop_subscriptions TO store_subscriptions');

        // 2. FK/self columns.
        DB::statement('ALTER TABLE appointments RENAME COLUMN shop_id TO store_id');
        DB::statement('ALTER TABLE appointments RENAME COLUMN shop_branch_id TO store_branch_id');
        DB::statement('ALTER TABLE audit_logs RENAME COLUMN shop_id TO store_id');
        DB::statement('ALTER TABLE catalog_items RENAME COLUMN shop_id TO store_id');
        DB::statement('ALTER TABLE catalog_orders RENAME COLUMN shop_id TO store_id');
        DB::statement('ALTER TABLE catalog_orders RENAME COLUMN shop_branch_id TO store_branch_id');
        DB::statement('ALTER TABLE job_orders RENAME COLUMN shop_id TO store_id');
        DB::statement('ALTER TABLE job_orders RENAME COLUMN shop_branch_id TO store_branch_id');
        DB::statement('ALTER TABLE job_orders RENAME COLUMN partner_shop_name TO partner_store_name');
        DB::statement('ALTER TABLE measurements RENAME COLUMN shop_id TO store_id');
        DB::statement('ALTER TABLE service_packages RENAME COLUMN shop_id TO store_id');
        DB::statement('ALTER TABLE services RENAME COLUMN shop_id TO store_id');
        DB::statement('ALTER TABLE store_branches RENAME COLUMN shop_id TO store_id');
        DB::statement('ALTER TABLE store_customers RENAME COLUMN shop_id TO store_id');
        DB::statement('ALTER TABLE store_posts RENAME COLUMN shop_id TO store_id');
        DB::statement('ALTER TABLE store_reviews RENAME COLUMN shop_id TO store_id');
        DB::statement('ALTER TABLE store_special_hours RENAME COLUMN shop_id TO store_id');
        DB::statement('ALTER TABLE store_special_hours RENAME COLUMN shop_branch_id TO store_branch_id');
        DB::statement('ALTER TABLE store_subscriptions RENAME COLUMN shop_id TO store_id');
        DB::statement('ALTER TABLE stores RENAME COLUMN shop_code TO store_code');
        DB::statement('ALTER TABLE staff_profiles RENAME COLUMN shop_id TO store_id');
        DB::statement('ALTER TABLE staff_profiles RENAME COLUMN shop_branch_id TO store_branch_id');
        DB::statement('ALTER TABLE subscription_events RENAME COLUMN shop_id TO store_id');
        DB::statement('ALTER TABLE subscription_events RENAME COLUMN shop_subscription_id TO store_subscription_id');
        DB::statement('ALTER TABLE support_tickets RENAME COLUMN shop_id TO store_id');

        // 3. Stored values that mirror the renamed entity — the code side of
        // each of these three was already updated by the same rename pass,
        // so existing rows need to follow or they'll silently stop matching.
        DB::table('roles')->where('name', 'shop_owner')->update(['name' => 'store_owner']);
        DB::table('stores')->where('business_type', 'tailoring_shop')->update(['business_type' => 'tailoring_store']);
        DB::table('job_orders')->where('material_source', 'shop_supplied')->update(['material_source' => 'store_supplied']);
    }

    public function down(): void
    {
        DB::table('job_orders')->where('material_source', 'store_supplied')->update(['material_source' => 'shop_supplied']);
        DB::table('stores')->where('business_type', 'tailoring_store')->update(['business_type' => 'tailoring_shop']);
        DB::table('roles')->where('name', 'store_owner')->update(['name' => 'shop_owner']);

        DB::statement('ALTER TABLE support_tickets RENAME COLUMN store_id TO shop_id');
        DB::statement('ALTER TABLE subscription_events RENAME COLUMN store_subscription_id TO shop_subscription_id');
        DB::statement('ALTER TABLE subscription_events RENAME COLUMN store_id TO shop_id');
        DB::statement('ALTER TABLE staff_profiles RENAME COLUMN store_branch_id TO shop_branch_id');
        DB::statement('ALTER TABLE staff_profiles RENAME COLUMN store_id TO shop_id');
        DB::statement('ALTER TABLE stores RENAME COLUMN store_code TO shop_code');
        DB::statement('ALTER TABLE store_subscriptions RENAME COLUMN store_id TO shop_id');
        DB::statement('ALTER TABLE store_special_hours RENAME COLUMN store_branch_id TO shop_branch_id');
        DB::statement('ALTER TABLE store_special_hours RENAME COLUMN store_id TO shop_id');
        DB::statement('ALTER TABLE store_reviews RENAME COLUMN store_id TO shop_id');
        DB::statement('ALTER TABLE store_posts RENAME COLUMN store_id TO shop_id');
        DB::statement('ALTER TABLE store_customers RENAME COLUMN store_id TO shop_id');
        DB::statement('ALTER TABLE store_branches RENAME COLUMN store_id TO shop_id');
        DB::statement('ALTER TABLE services RENAME COLUMN store_id TO shop_id');
        DB::statement('ALTER TABLE service_packages RENAME COLUMN store_id TO shop_id');
        DB::statement('ALTER TABLE measurements RENAME COLUMN store_id TO shop_id');
        DB::statement('ALTER TABLE job_orders RENAME COLUMN partner_store_name TO partner_shop_name');
        DB::statement('ALTER TABLE job_orders RENAME COLUMN store_branch_id TO shop_branch_id');
        DB::statement('ALTER TABLE job_orders RENAME COLUMN store_id TO shop_id');
        DB::statement('ALTER TABLE catalog_orders RENAME COLUMN store_branch_id TO shop_branch_id');
        DB::statement('ALTER TABLE catalog_orders RENAME COLUMN store_id TO shop_id');
        DB::statement('ALTER TABLE catalog_items RENAME COLUMN store_id TO shop_id');
        DB::statement('ALTER TABLE audit_logs RENAME COLUMN store_id TO shop_id');
        DB::statement('ALTER TABLE appointments RENAME COLUMN store_branch_id TO shop_branch_id');
        DB::statement('ALTER TABLE appointments RENAME COLUMN store_id TO shop_id');

        DB::statement('RENAME TABLE stores TO shops');
        DB::statement('RENAME TABLE store_branches TO shop_branches');
        DB::statement('RENAME TABLE store_customers TO shop_customers');
        DB::statement('RENAME TABLE store_posts TO shop_posts');
        DB::statement('RENAME TABLE store_reviews TO shop_reviews');
        DB::statement('RENAME TABLE store_special_hours TO shop_special_hours');
        DB::statement('RENAME TABLE store_subscriptions TO shop_subscriptions');
    }
};
