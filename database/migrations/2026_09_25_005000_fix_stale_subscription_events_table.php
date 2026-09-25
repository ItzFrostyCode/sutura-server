<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unconditional repair for a class of machine that a file edit alone can't
 * fix: 2026_06_13_185006_create_subscription_events_table already got
 * recorded as "Ran" in the migrations table on some machines — from an
 * earlier attempt, before that file's own guard was fixed (twice), that
 * silently "succeeded" by returning early on a stale, shop_id-named
 * leftover table (see that file's docblock for the full history). Once a
 * migration is marked Ran, `php artisan migrate` never re-executes it just
 * because its file content later changed — so improving that file's guard
 * did nothing for a machine already in this state, and
 * 2026_09_25_010000_rename_stale_fk_constraints kept failing on the same
 * "Key column 'store_id' doesn't exist" error every time.
 *
 * This migration is new (never yet run anywhere), so it always executes
 * regardless of what the migrations table says about 2026_06_13_185006,
 * and repairs the actual table state directly: if subscription_events
 * exists but is still shop_id-named, drop and recreate it correctly. A
 * genuinely fresh install (or a machine where 2026_06_13_185006 already
 * created it correctly) hits neither branch — pure no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subscription_events') && ! Schema::hasColumn('subscription_events', 'store_id')) {
            Schema::dropIfExists('subscription_events');
        }

        if (Schema::hasTable('subscription_events')) {
            return;
        }

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
        // Intentionally a no-op — this migration only repairs a stale
        // leftover; there's nothing meaningful to reverse. Rolling back
        // 2026_06_13_185006 already drops the table if that's the intent.
    }
};
