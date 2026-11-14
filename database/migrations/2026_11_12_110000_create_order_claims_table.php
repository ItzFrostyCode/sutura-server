<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * After-sale claims ("Report a problem"): within 3 days of an order being completed, the customer reports
 * a problem (e.g. poor stitching) and asks for a free repair or a refund. The shop accepts, declines, or
 * (on a refund claim) offers a repair instead. An accepted repair becomes a linked P0 order; a refund is
 * recorded here only (SUTURA never moves money).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_order_id')->constrained('job_orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('filed_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('type', 20);            // repair | refund (the customer's choice)
            $table->string('reason', 30);          // poor_stitching | wrong_fit | damaged | other
            $table->text('description');
            $table->json('photos');                // 1-5 image URLs

            // submitted -> declined | repair_offered -> submitted | repairing -> resolved | awaiting_return -> refunded
            $table->string('status', 20)->default('submitted');
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decline_reason')->nullable();

            // "Offer repair instead" on a refund claim: due date + staff + note. One offer per claim.
            $table->date('offered_due_date')->nullable();
            $table->foreignId('offered_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('offer_note')->nullable();
            $table->timestamp('repair_offered_at')->nullable();
            $table->timestamp('offer_declined_at')->nullable();

            // A declined customer can ask SUTURA support to review it (once).
            $table->foreignId('escalated_ticket_id')->nullable()->constrained('support_tickets')->nullOnDelete();

            // Repair path: the linked P0 free-fix job order.
            $table->foreignId('repair_job_order_id')->nullable()->constrained('job_orders')->nullOnDelete();

            // Refund path (recorded only; the shop pays outside SUTURA).
            $table->timestamp('garment_returned_at')->nullable();
            $table->decimal('refund_amount', 10, 2)->nullable();
            $table->string('refund_method', 20)->nullable();   // cash | gcash
            $table->string('refund_reference')->nullable();
            $table->text('refund_proof_path')->nullable();     // URL: TEXT, not varchar(255)
            $table->timestamp('refunded_at')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index('job_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_claims');
    }
};
