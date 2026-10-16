<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-production steps (additive). The order keeps its status column; these columns and tables record
 * what happened BEFORE production: the shop accepting a request, the quotation versions, the customer's
 * decision, the locked payment terms, the explicit production authorization, and who was planned
 * on the order over time. Existing rows are backfilled so nothing already in progress is blocked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->timestamp('shop_accepted_at')->nullable();
            $table->unsignedBigInteger('shop_accepted_by')->nullable();
            $table->timestamp('quotation_sent_at')->nullable();
            $table->date('quotation_valid_until')->nullable();
            $table->string('customer_decision', 20)->nullable(); // accepted | changes_requested | declined
            $table->text('customer_decision_note')->nullable();
            $table->timestamp('customer_decided_at')->nullable();
            $table->timestamp('payment_terms_locked_at')->nullable();
            $table->timestamp('production_authorized_at')->nullable();
            $table->unsignedBigInteger('production_authorized_by')->nullable();
        });

        Schema::create('order_quotation_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_order_id')->constrained('job_orders')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 12); // estimate | final
            $table->decimal('total', 10, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('payment_policy', 12)->nullable();
            $table->unsignedSmallInteger('payment_policy_percent')->nullable();
            $table->json('snapshot')->nullable();
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['job_order_id', 'version']);
        });

        Schema::create('job_order_assignee_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_order_id')->constrained('job_orders')->cascadeOnDelete();
            $table->string('action', 12); // added | removed | replaced
            $table->unsignedBigInteger('from_user_id')->nullable();
            $table->unsignedBigInteger('to_user_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('by_user_id')->nullable();
            $table->timestamps();

            $table->index('job_order_id');
        });

        // Backfill: an order that already got past a step must not be sent back to it.
        DB::table('job_orders')->update(['shop_accepted_at' => DB::raw('created_at')]);
        DB::table('job_orders')->whereNotNull('customer_approved_at')->update([
            'customer_decision' => 'accepted',
            'customer_decided_at' => DB::raw('customer_approved_at'),
            'payment_terms_locked_at' => DB::raw('customer_approved_at'),
        ]);
        DB::table('job_orders')->where('quotation_status', 'final')->update(['quotation_sent_at' => DB::raw('updated_at')]);
        DB::table('job_orders')->whereNotNull('confirmed_at')->update(['production_authorized_at' => DB::raw('confirmed_at')]);
    }

    public function down(): void
    {
        Schema::dropIfExists('job_order_assignee_events');
        Schema::dropIfExists('order_quotation_versions');

        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn([
                'shop_accepted_at', 'shop_accepted_by', 'quotation_sent_at', 'quotation_valid_until',
                'customer_decision', 'customer_decision_note', 'customer_decided_at',
                'payment_terms_locked_at', 'production_authorized_at', 'production_authorized_by',
            ]);
        });
    }
};
