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
 * Named/dated to sit right after 2026_06_13_185005_create_shop_subscriptions_table
 * and use its era's shop_* column names on purpose — 2026_09_23_013710
 * already has store-rename guards that expect + convert those to
 * store_id/store_subscription_id like every other shop-era table, so this
 * just lets that existing, already-tested rename path run instead of
 * special-casing this one table to skip it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subscription_events')) {
            return;
        }

        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('shop_subscription_id')->constrained('shop_subscriptions')->cascadeOnDelete();
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
