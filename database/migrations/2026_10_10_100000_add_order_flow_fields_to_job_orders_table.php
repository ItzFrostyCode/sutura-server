<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ordering flow (Flow 2 "customer decision -> production started" and Flow 3 "production -> completed").
 *
 * Purely additive. `uses_confirmation_flow` is the opt-in switch: only orders created through the
 * new flow carry it, so every existing order keeps its old behaviour.
 *
 * Scope note: there is deliberately no material list, supplier, cost or stock here -- the approved
 * thesis excludes inventory, procurement and purchase orders. Materials are a yes/no "ready" sign-off
 * with a note, plus an assessment of the customer's own fabric when they bring it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->boolean('uses_confirmation_flow')->default(false);
            $table->string('quotation_status', 16)->nullable(); // estimate | final
            $table->json('quotation')->nullable();

            $table->timestamp('specs_approved_at')->nullable();
            $table->foreignId('specs_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('measurements_verified_at')->nullable();
            $table->foreignId('measurements_verified_by')->nullable()->constrained('users')->nullOnDelete();

            // Flow 2 step 3: the customer's own fabric / items, assessed before the price is final.
            $table->string('customer_items_assessment', 12)->nullable(); // suitable | not_suitable
            // Flow 2 step 7: the shop confirms the materials are ready (a yes/no, not a stock record).
            $table->timestamp('materials_verified_at')->nullable();
            $table->foreignId('materials_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('materials_note')->nullable();

            $table->timestamp('customer_approved_at')->nullable();
            $table->string('customer_approval_channel', 12)->nullable(); // online | in_person
            $table->json('customer_approval_snapshot')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('production_started_at')->nullable();

            // Flow 3: quality check passed, and the customer's fitting passed.
            $table->timestamp('qc_passed_at')->nullable();
            $table->foreignId('qc_passed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fitting_passed_at')->nullable();
        });

        Schema::table('stores', function (Blueprint $table) {
            // Shown to the customer before they approve a quotation. Null = the built-in default text.
            $table->text('cancellation_policy')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('cancellation_policy');
        });

        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('specs_approved_by');
            $table->dropConstrainedForeignId('measurements_verified_by');
            $table->dropConstrainedForeignId('materials_verified_by');
            $table->dropConstrainedForeignId('qc_passed_by');
            $table->dropColumn([
                'uses_confirmation_flow', 'quotation_status', 'quotation',
                'specs_approved_at', 'measurements_verified_at',
                'customer_items_assessment', 'materials_verified_at', 'materials_note',
                'customer_approved_at', 'customer_approval_channel', 'customer_approval_snapshot',
                'confirmed_at', 'production_started_at', 'qc_passed_at', 'fitting_passed_at',
            ]);
        });
    }
};
