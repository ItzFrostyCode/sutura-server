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
 * COLUMN ... TO ...` SQL — the raw-SQL version broke with a 1064 syntax
 * error on an older MariaDB that only accepts the `CHANGE old new <full
 * definition>` form.
 *
 * Every rename below is guarded with a hasTable/hasColumn existence check.
 * Table/column DDL isn't transactional in MySQL/MariaDB (each statement
 * auto-commits), so a run that fails partway through leaves the schema
 * partially renamed while Laravel's migrations table still records this
 * migration as NOT run (the exception aborts before the row is written) —
 * the next `migrate` attempt re-runs everything from the top and immediately
 * collides with whatever already got renamed ("Table 'stores' already
 * exists"). Guarding each step means a re-run always converges to the same
 * end state no matter which step it previously died on, instead of
 * requiring an exact "drop and recreate the database first" ritual.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private array $tableRenames = [
        'shops' => 'stores',
        'shop_branches' => 'store_branches',
        'shop_customers' => 'store_customers',
        'shop_posts' => 'store_posts',
        'shop_reviews' => 'store_reviews',
        'shop_special_hours' => 'store_special_hours',
        'shop_subscriptions' => 'store_subscriptions',
    ];

    /** @var array<int, array{0:string,1:string,2:string}> table, old column, new column */
    private array $columnRenames = [
        ['appointments', 'shop_id', 'store_id'],
        ['appointments', 'shop_branch_id', 'store_branch_id'],
        ['audit_logs', 'shop_id', 'store_id'],
        ['catalog_items', 'shop_id', 'store_id'],
        ['catalog_orders', 'shop_id', 'store_id'],
        ['catalog_orders', 'shop_branch_id', 'store_branch_id'],
        ['job_orders', 'shop_id', 'store_id'],
        ['job_orders', 'shop_branch_id', 'store_branch_id'],
        ['job_orders', 'partner_shop_name', 'partner_store_name'],
        ['measurements', 'shop_id', 'store_id'],
        ['service_packages', 'shop_id', 'store_id'],
        ['services', 'shop_id', 'store_id'],
        ['store_branches', 'shop_id', 'store_id'],
        ['store_customers', 'shop_id', 'store_id'],
        ['store_posts', 'shop_id', 'store_id'],
        ['store_reviews', 'shop_id', 'store_id'],
        ['store_special_hours', 'shop_id', 'store_id'],
        ['store_special_hours', 'shop_branch_id', 'store_branch_id'],
        ['store_subscriptions', 'shop_id', 'store_id'],
        ['stores', 'shop_code', 'store_code'],
        ['staff_profiles', 'shop_id', 'store_id'],
        ['staff_profiles', 'shop_branch_id', 'store_branch_id'],
        ['subscription_events', 'shop_id', 'store_id'],
        ['subscription_events', 'shop_subscription_id', 'store_subscription_id'],
        ['support_tickets', 'shop_id', 'store_id'],
    ];

    public function up(): void
    {
        // 1. Tables first, so later column renames reference the new names.
        //    Skip any pair already renamed (or never created yet) so a
        //    re-run after a partial failure doesn't collide.
        foreach ($this->tableRenames as $from => $to) {
            if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }

        // 2. FK/self columns — same guard, per column.
        foreach ($this->columnRenames as [$table, $from, $to]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $from) && ! Schema::hasColumn($table, $to)) {
                Schema::table($table, function (Blueprint $blueprint) use ($from, $to) {
                    $blueprint->renameColumn($from, $to);
                });
            }
        }

        // 3. Stored values that mirror the renamed entity — the code side of
        // each of these three was already updated by the same rename pass,
        // so existing rows need to follow or they'll silently stop matching.
        // where()->update() is naturally idempotent (0 rows match once
        // already applied), no guard needed.
        DB::table('roles')->where('name', 'shop_owner')->update(['name' => 'store_owner']);
        DB::table('stores')->where('business_type', 'tailoring_shop')->update(['business_type' => 'tailoring_store']);
        DB::table('job_orders')->where('material_source', 'shop_supplied')->update(['material_source' => 'store_supplied']);
    }

    public function down(): void
    {
        DB::table('job_orders')->where('material_source', 'store_supplied')->update(['material_source' => 'shop_supplied']);
        DB::table('stores')->where('business_type', 'tailoring_store')->update(['business_type' => 'tailoring_shop']);
        DB::table('roles')->where('name', 'store_owner')->update(['name' => 'shop_owner']);

        foreach (array_reverse($this->columnRenames) as [$table, $from, $to]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $to) && ! Schema::hasColumn($table, $from)) {
                Schema::table($table, function (Blueprint $blueprint) use ($from, $to) {
                    $blueprint->renameColumn($to, $from);
                });
            }
        }

        foreach (array_reverse($this->tableRenames) as $from => $to) {
            if (Schema::hasTable($to) && ! Schema::hasTable($from)) {
                Schema::rename($to, $from);
            }
        }
    }
};
