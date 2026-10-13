<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which cycle a paid subscription was bought on, so the admin Reports page
 * can split each plan's live stores into Monthly / Yearly. Null for trials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_subscriptions', function (Blueprint $table) {
            $table->string('billing_cycle', 10)->nullable()->after('status'); // monthly | yearly
        });

        // Existing paid rows: SubscriptionSwitcher gives yearly 365 days and
        // monthly 30 (an approved application a year or a month), so the
        // length tells them apart.
        DB::table('store_subscriptions')->where('status', '!=', 'trial')
            ->whereNotNull('starts_at')->whereNotNull('ends_at')
            ->get(['id', 'starts_at', 'ends_at'])
            ->each(function ($sub) {
                $days = (strtotime($sub->ends_at) - strtotime($sub->starts_at)) / 86400;
                if ($days > 0) {
                    DB::table('store_subscriptions')->where('id', $sub->id)
                        ->update(['billing_cycle' => $days > 60 ? 'yearly' : 'monthly']);
                }
            });
    }

    public function down(): void
    {
        Schema::table('store_subscriptions', function (Blueprint $table) {
            $table->dropColumn('billing_cycle');
        });
    }
};
