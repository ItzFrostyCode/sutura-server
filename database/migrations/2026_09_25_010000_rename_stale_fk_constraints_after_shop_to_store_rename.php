<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renames every foreign key CONSTRAINT left over from the shop->store
 * rename (2026_09_23_013710_rename_shop_to_store_everywhere.php) to match
 * Laravel's naming convention ({table}_{column}_foreign).
 *
 * MySQL/MariaDB don't rename a FK constraint when the table or column it's
 * defined on is renamed — only the column/table itself changes; the
 * constraint keeps its original name (e.g. appointments_shop_id_foreign
 * stays that name even though the column is store_id now, and
 * shops_owner_id_foreign stays that name even though the table is stores
 * now). Nothing breaks today, but any FUTURE migration calling
 * $table->dropForeign(['store_id']) would have Laravel guess the
 * convention name (appointments_store_id_foreign) and fail with
 * "constraint does not exist", since the real name is still the old one.
 * This closes that gap. MySQL/MariaDB have no ALTER TABLE ... RENAME
 * CONSTRAINT for foreign keys (only CHECK constraints support that, since
 * 8.0.19), so drop + re-add under the new name is the only portable way.
 */
return new class extends Migration
{
    /**
     * [table, column, old constraint name, new constraint name, referenced
     * table, referenced column, onDelete action (null = leave at the
     * MySQL/MariaDB default of NO ACTION)] — captured from the live schema
     * via information_schema so the recreated constraints are identical in
     * behavior, just correctly named.
     *
     * @var array<int, array{0:string,1:string,2:string,3:string,4:string,5:string,6:?string}>
     */
    private array $constraints = [
        ['appointments', 'store_branch_id', 'appointments_shop_branch_id_foreign', 'appointments_store_branch_id_foreign', 'store_branches', 'id', 'set null'],
        ['appointments', 'store_id', 'appointments_shop_id_foreign', 'appointments_store_id_foreign', 'stores', 'id', 'cascade'],
        ['audit_logs', 'store_id', 'audit_logs_shop_id_foreign', 'audit_logs_store_id_foreign', 'stores', 'id', 'cascade'],
        ['catalog_items', 'store_id', 'catalog_items_shop_id_foreign', 'catalog_items_store_id_foreign', 'stores', 'id', 'cascade'],
        ['catalog_orders', 'store_branch_id', 'catalog_orders_shop_branch_id_foreign', 'catalog_orders_store_branch_id_foreign', 'store_branches', 'id', 'set null'],
        ['catalog_orders', 'store_id', 'catalog_orders_shop_id_foreign', 'catalog_orders_store_id_foreign', 'stores', 'id', 'cascade'],
        ['job_orders', 'store_branch_id', 'job_orders_shop_branch_id_foreign', 'job_orders_store_branch_id_foreign', 'store_branches', 'id', 'set null'],
        ['job_orders', 'store_id', 'job_orders_shop_id_foreign', 'job_orders_store_id_foreign', 'stores', 'id', 'cascade'],
        ['measurements', 'store_id', 'measurements_shop_id_foreign', 'measurements_store_id_foreign', 'stores', 'id', 'cascade'],
        ['service_packages', 'store_id', 'service_packages_shop_id_foreign', 'service_packages_store_id_foreign', 'stores', 'id', 'cascade'],
        ['services', 'store_id', 'services_shop_id_foreign', 'services_store_id_foreign', 'stores', 'id', 'cascade'],
        ['staff_profiles', 'store_branch_id', 'staff_profiles_shop_branch_id_foreign', 'staff_profiles_store_branch_id_foreign', 'store_branches', 'id', 'set null'],
        ['staff_profiles', 'store_id', 'staff_profiles_shop_id_foreign', 'staff_profiles_store_id_foreign', 'stores', 'id', 'cascade'],
        ['store_branches', 'store_id', 'shop_branches_shop_id_foreign', 'store_branches_store_id_foreign', 'stores', 'id', 'cascade'],
        ['store_customers', 'store_id', 'shop_customers_shop_id_foreign', 'store_customers_store_id_foreign', 'stores', 'id', 'cascade'],
        ['store_customers', 'user_id', 'shop_customers_user_id_foreign', 'store_customers_user_id_foreign', 'users', 'id', 'cascade'],
        ['store_posts', 'service_id', 'shop_posts_service_id_foreign', 'store_posts_service_id_foreign', 'services', 'id', 'set null'],
        ['store_posts', 'store_id', 'shop_posts_shop_id_foreign', 'store_posts_store_id_foreign', 'stores', 'id', 'cascade'],
        ['store_reviews', 'store_id', 'shop_reviews_shop_id_foreign', 'store_reviews_store_id_foreign', 'stores', 'id', 'cascade'],
        ['store_reviews', 'user_id', 'shop_reviews_user_id_foreign', 'store_reviews_user_id_foreign', 'users', 'id', 'cascade'],
        ['store_special_hours', 'store_branch_id', 'shop_special_hours_shop_branch_id_foreign', 'store_special_hours_store_branch_id_foreign', 'store_branches', 'id', 'set null'],
        ['store_special_hours', 'store_id', 'shop_special_hours_shop_id_foreign', 'store_special_hours_store_id_foreign', 'stores', 'id', 'cascade'],
        ['store_subscriptions', 'plan_id', 'shop_subscriptions_plan_id_foreign', 'store_subscriptions_plan_id_foreign', 'subscription_plans', 'id', null],
        ['store_subscriptions', 'store_id', 'shop_subscriptions_shop_id_foreign', 'store_subscriptions_store_id_foreign', 'stores', 'id', 'cascade'],
        ['stores', 'approved_by', 'shops_approved_by_foreign', 'stores_approved_by_foreign', 'users', 'id', 'set null'],
        ['stores', 'owner_id', 'shops_owner_id_foreign', 'stores_owner_id_foreign', 'users', 'id', 'cascade'],
        ['subscription_events', 'store_id', 'subscription_events_shop_id_foreign', 'subscription_events_store_id_foreign', 'stores', 'id', 'cascade'],
        ['subscription_events', 'store_subscription_id', 'subscription_events_shop_subscription_id_foreign', 'subscription_events_store_subscription_id_foreign', 'store_subscriptions', 'id', 'cascade'],
        ['support_tickets', 'store_id', 'support_tickets_shop_id_foreign', 'support_tickets_store_id_foreign', 'stores', 'id', 'cascade'],
    ];

    /**
     * Existing constraint names on a table, cached per table since several
     * rows in $this->constraints share the same table.
     *
     * @var array<string, array<int, string>>
     */
    private array $existingNamesCache = [];

    private function constraintExists(string $table, string $name): bool
    {
        if (! isset($this->existingNamesCache[$table])) {
            $this->existingNamesCache[$table] = array_map(
                fn (array $fk) => $fk['name'],
                Schema::getForeignKeys($table)
            );
        }

        return in_array($name, $this->existingNamesCache[$table], true);
    }

    private function forgetCache(string $table): void
    {
        unset($this->existingNamesCache[$table]);
    }

    public function up(): void
    {
        // Guarded like 2026_09_23_013710: skip if the new name is already
        // there (done). Otherwise drop the old name IF it's still present
        // (it won't be if a previous run got as far as dropping it but died
        // before the ADD — DDL isn't transactional, so that half-done state
        // is real and must recover by just adding the constraint, not by
        // trying to drop something that's already gone).
        foreach ($this->constraints as [$table, $column, $oldName, $newName, $refTable, $refColumn, $onDelete]) {
            if ($this->constraintExists($table, $newName)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $column, $oldName, $newName, $refTable, $refColumn, $onDelete) {
                if ($this->constraintExists($table, $oldName)) {
                    $blueprint->dropForeign($oldName);
                }
                $fk = $blueprint->foreign($column, $newName)->references($refColumn)->on($refTable);
                if ($onDelete) {
                    $fk->onDelete($onDelete);
                }
            });
            $this->forgetCache($table);
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->constraints) as [$table, $column, $oldName, $newName, $refTable, $refColumn, $onDelete]) {
            if ($this->constraintExists($table, $oldName)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $column, $oldName, $newName, $refTable, $refColumn, $onDelete) {
                if ($this->constraintExists($table, $newName)) {
                    $blueprint->dropForeign($newName);
                }
                $fk = $blueprint->foreign($column, $oldName)->references($refColumn)->on($refTable);
                if ($onDelete) {
                    $fk->onDelete($onDelete);
                }
            });
            $this->forgetCache($table);
        }
    }
};
