<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Batches: when people in a group order pay at different times, the order is split into linked orders. Each has its
// own total, deposit, due date, handover and tracking code; the first one is the "root" the others point to.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->foreignId('parent_order_id')->nullable()->constrained('job_orders')->nullOnDelete();
            $table->unsignedTinyInteger('batch_no')->nullable();
            $table->string('paid_by_name', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_order_id');
            $table->dropColumn(['batch_no', 'paid_by_name']);
        });
    }
};
