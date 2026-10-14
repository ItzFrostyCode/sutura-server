<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ordering flow tables: planned tailors, corrections (QC defects, fitting issues, customer change
 * requests), handover records and refund records. None of these moves money, and none is a stock
 * or supplier record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_order_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12)->default('tailor'); // lead | tailor | assistant
            $table->string('note', 255)->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['job_order_id', 'user_id']);
        });

        Schema::create('order_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_order_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // defect | fitting_issue | change_request
            $table->text('description');
            $table->unsignedInteger('affected_quantity')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('target_date')->nullable();
            $table->boolean('shop_fault')->default(true); // shop-caused errors are corrected without charge
            $table->string('status', 20)->default('open'); // open | done | awaiting_approval | cancelled
            // Customer change requests are quoted and approved BEFORE any work is done.
            $table->decimal('additional_amount', 10, 2)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['job_order_id', 'status']);
        });

        Schema::create('order_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('received_by', 120)->nullable(); // who collected the garments
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handed_over_at');
            $table->timestamps();
        });

        Schema::create('order_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_order_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 24); // no_costs_yet | costs_incurred | production_started
            $table->decimal('paid_amount', 10, 2);
            $table->decimal('non_refundable_amount', 10, 2)->default(0); // costs already incurred, as entered by the owner
            $table->string('non_refundable_note', 255)->nullable();
            $table->decimal('suggested_amount', 10, 2);
            $table->decimal('approved_amount', 10, 2);
            $table->json('basis')->nullable();
            $table->text('adjustment_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable(); // set when the shop hands the money back
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_refunds');
        Schema::dropIfExists('order_handovers');
        Schema::dropIfExists('order_corrections');
        Schema::dropIfExists('job_order_assignees');
    }
};
