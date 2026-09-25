<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backfills a genuinely missing migration — app/Models/SubscriptionEvent.php
 * and the code that writes to it (SubscriptionController, AnalyticsController,
 * ExpireSubscriptions) have existed for a while, but no migration in this
 * repo ever created the underlying table. It worked on every machine that
 * happened to already have it (created ad hoc at some point, never
 * committed as a migration), which is why this was invisible until Bongo's
 * genuinely fresh `migrate --seed` hit
 * 2026_09_25_010000_rename_stale_fk_constraints... trying to add a
 * constraint onto a table that doesn't exist.
 *
 * Uses store_id/store_subscription_id and references stores/
 * store_subscriptions directly — NOT the shop_* names the filename's era
 * would suggest. Despite its filename, create_shops_table.php (this
 * migration's neighbor, 2026_06_13_185004) already does
 * Schema::create('stores', ...) directly, same for
 * create_shop_subscriptions_table.php -> Schema::create('store_subscriptions', ...)
 * — every historical migration's *content* was rewritten to final naming
 * as part of the shop->store rename, only the filenames stayed on their
 * original dates. 2026_09_23_013710's rename step is a no-op safety net
 * for pre-existing databases that still have real shop_id-named data, not
 * something a fresh install ever produces to rename. An earlier version of
 * this migration wrongly assumed the shop_* names still existed here and
 * referenced 'shops'/'shop_subscriptions', which don't exist on a fresh
 * install — exactly what broke for Bongo (errno 150, can't create table).
 *
 * The hasTable() guard alone isn't enough either — reproduced and fixed
 * after Bongo hit a THIRD failure from this same table. His DB had a
 * leftover subscription_events table from that earlier failed shop_id-based
 * attempt: Schema::create()'s CREATE TABLE half succeeded (all columns,
 * under the old shop_id/shop_subscription_id names) before the FK ALTER to
 * the nonexistent 'shops' table failed, and since the exception meant this
 * migration was never marked "Ran", a hasTable()-only guard on the next
 * attempt saw the table "already there" and returned early — silently
 * leaving the stale shop_id-named structure in place, which then broke the
 * next migration's `store_id` FK rename with "Key column 'store_id'
 * doesn't exist". Guarding on hasColumn('store_id') instead — and dropping
 * + recreating when it's missing — means a stale/incomplete leftover from
 * any earlier failed attempt gets replaced with the correct structure
 * instead of being trusted as-is. Safe to drop: a table whose own creation
 * migration never completed was never marked "Ran", so nothing in the app
 * could have written real data through it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subscription_events') && Schema::hasColumn('subscription_events', 'store_id')) {
            return;
        }

        Schema::dropIfExists('subscription_events');

        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('store_subscription_id')->constrained('store_subscriptions')->cascadeOnDelete();
            $table->enum('event_type', ['created', 'renewed', 'upgraded', 'downgraded', 'expired']);
            $table->foreignId('plan_id')->constrained('subscription_plans');
            $table->foreignId('previous_plan_id')->nullable()->constrained('subscription_plans');
            $table->string('billing_cycle')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
    }
};
