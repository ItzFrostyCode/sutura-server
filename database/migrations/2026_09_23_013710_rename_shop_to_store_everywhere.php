<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renames the core "Shop" domain entity to "Store" across the live schema
 * — tables, FK columns, and the handful of stored string values that mirror
 * it (the shop_owner role, and two enum-like columns).
 *
 * Uses Schema::rename()/Blueprint::renameColumn() (Laravel's own portable
 * schema builder) instead of raw `RENAME TABLE`/`ALTER TABLE ... RENAME
 * COLUMN ... TO ...` SQL. The raw-SQL version of this migration worked on
 * MySQL 8.0+ and MariaDB 10.5.2+ (both support `RENAME COLUMN ... TO ...`
 * directly) but broke with a 1064 syntax error on an older MariaDB (a
 * teammate's local XAMPP install) that doesn't understand that syntax and
 * only accepts the older `CHANGE old_name new_name <full definition>`
 * form. Laravel 12's schema builder auto-detects the connected server's
 * capability and generates whichever form it actually supports — the
 * fix is delegating to that instead of hand-writing one specific dialect.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Tables first, so later column renames reference the new names.
        Schema::rename('shops', 'stores');
        Schema::rename('shop_branches', 'store_branches');
        Schema::rename('shop_customers', 'store_customers');
        Schema::rename('shop_posts', 'store_posts');
        Schema::rename('shop_reviews', 'store_reviews');
        Schema::rename('shop_special_hours', 'store_special_hours');
        Schema::rename('shop_subscriptions', 'store_subscriptions');

        // 2. FK/self columns.
        Schema::table('appointments', function (Blueprint $table) {
            $table->renameColumn('shop_id', 'store_id');
            $table->renameColumn('shop_branch_id', 'store_branch_id');
        });
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->renameColumn('shop_id', 'store_id');
        });
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->renameColumn('shop_id', 'store_id');
        });
        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->renameColumn('shop_id', 'store_id');
            $table->renameColumn('shop_branch_id', 'store_branch_id');
        });
        Schema::table('job_orders', function (Blueprint $table) {
            $table->renameColumn('shop_id', 'store_id');
            $table->renameColumn('shop_branch_id', 'store_branch_id');
            $table->renameColumn('partner_shop_name', 'partner_store_name');
        });
        Schema::table('measurements', function (Blueprint $table) {
            $table->renameColumn('shop_id', 'store_id');
        });
        Schema::table('service_packages', function (Blueprint $table) {
            $table->renameColumn('shop_id', 'store_id');
        });
        Schema::table('services', function (Blueprint $table) {
            $table->renameColumn('shop_id', 'store_id');
        });
        Schema::table('store_branches', function (Blueprint $table) {
            $table->renameColumn('shop_id', 'store_id');
        });
        Schema::table('store_customers', function (Blueprint $table) {
            $table->renameColumn('shop_id', 'store_id');
        });
        Schema::table('store_posts', function (Blueprint $table) {
            $table->renameColumn('shop_id', 'store_id');
        });
        Schema::table('store_reviews', function (Blueprint $table) {
            $table->renameColumn('shop_id', 'store_id');
        });
        Schema::table('store_special_hours', function (Blueprint $table) {
            $table->renameColumn('shop_id', 'store_id');
            $table->renameColumn('shop_branch_id', 'store_branch_id');
        });
        Schema::table('store_subscriptions', function (Blueprint $table) {
            $table->renameColumn('shop_id', 'store_id');
        });
        Schema::table('stores', function (Blueprint $table) {
            $table->renameColumn('shop_code', 'store_code');
        });
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->renameColumn('shop_id', 'store_id');
            $table->renameColumn('shop_branch_id', 'store_branch_id');
        });
        Schema::table('subscription_events', function (Blueprint $table) {
            $table->renameColumn('shop_id', 'store_id');
            $table->renameColumn('shop_subscription_id', 'store_subscription_id');
        });
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->renameColumn('shop_id', 'store_id');
        });

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

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->renameColumn('store_id', 'shop_id');
        });
        Schema::table('subscription_events', function (Blueprint $table) {
            $table->renameColumn('store_subscription_id', 'shop_subscription_id');
            $table->renameColumn('store_id', 'shop_id');
        });
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->renameColumn('store_branch_id', 'shop_branch_id');
            $table->renameColumn('store_id', 'shop_id');
        });
        Schema::table('stores', function (Blueprint $table) {
            $table->renameColumn('store_code', 'shop_code');
        });
        Schema::table('store_subscriptions', function (Blueprint $table) {
            $table->renameColumn('store_id', 'shop_id');
        });
        Schema::table('store_special_hours', function (Blueprint $table) {
            $table->renameColumn('store_branch_id', 'shop_branch_id');
            $table->renameColumn('store_id', 'shop_id');
        });
        Schema::table('store_reviews', function (Blueprint $table) {
            $table->renameColumn('store_id', 'shop_id');
        });
        Schema::table('store_posts', function (Blueprint $table) {
            $table->renameColumn('store_id', 'shop_id');
        });
        Schema::table('store_customers', function (Blueprint $table) {
            $table->renameColumn('store_id', 'shop_id');
        });
        Schema::table('store_branches', function (Blueprint $table) {
            $table->renameColumn('store_id', 'shop_id');
        });
        Schema::table('services', function (Blueprint $table) {
            $table->renameColumn('store_id', 'shop_id');
        });
        Schema::table('service_packages', function (Blueprint $table) {
            $table->renameColumn('store_id', 'shop_id');
        });
        Schema::table('measurements', function (Blueprint $table) {
            $table->renameColumn('store_id', 'shop_id');
        });
        Schema::table('job_orders', function (Blueprint $table) {
            $table->renameColumn('partner_store_name', 'partner_shop_name');
            $table->renameColumn('store_branch_id', 'shop_branch_id');
            $table->renameColumn('store_id', 'shop_id');
        });
        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->renameColumn('store_branch_id', 'shop_branch_id');
            $table->renameColumn('store_id', 'shop_id');
        });
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->renameColumn('store_id', 'shop_id');
        });
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->renameColumn('store_id', 'shop_id');
        });
        Schema::table('appointments', function (Blueprint $table) {
            $table->renameColumn('store_branch_id', 'shop_branch_id');
            $table->renameColumn('store_id', 'shop_id');
        });

        Schema::rename('stores', 'shops');
        Schema::rename('store_branches', 'shop_branches');
        Schema::rename('store_customers', 'shop_customers');
        Schema::rename('store_posts', 'shop_posts');
        Schema::rename('store_reviews', 'shop_reviews');
        Schema::rename('store_special_hours', 'shop_special_hours');
        Schema::rename('store_subscriptions', 'shop_subscriptions');
    }
};
