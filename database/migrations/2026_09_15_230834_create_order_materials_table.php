<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_order_id')->constrained()->cascadeOnDelete();
            $table->string('material_name');
            $table->decimal('quantity_used', 8, 2);
            $table->string('unit')->default('yard');
            $table->decimal('unit_cost', 10, 2)->nullable();
            // Computed server-side (quantity_used * unit_cost) at write time,
            // not a live-computed column — matches this app's existing
            // pattern of storing amounts rather than recomputing on read
            // (see job_orders.balance/total_amount).
            $table->decimal('subtotal_cost', 10, 2)->nullable();
            $table->foreignId('logged_by_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Per-order attribution only — deliberately no shop-wide/aggregate
            // index or a separate stock-balance table. This is not an
            // inventory ledger (thesis Scope & Limitations, Line 203).
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_materials');
    }
};
