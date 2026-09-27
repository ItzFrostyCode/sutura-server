<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes two features that sat outside the approved thesis scope:
 * - order_materials: per-job fabric quantity/cost logging, too close to the
 *   Limitations' exclusion of "inventory tracking, material stock
 *   monitoring... fabric procurement".
 * - job_orders outsourcing fields: subcontracting to a partner shop maps to
 *   no objective and edges into logistics/expense tracking.
 * Customer-supplied material is still recorded via job_orders.material_source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('order_materials');

        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn(['is_outsourced', 'partner_store_name', 'outsourcing_cost']);
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->boolean('is_outsourced')->default(false)->after('status');
            $table->string('partner_store_name')->nullable()->after('is_outsourced');
            $table->decimal('outsourcing_cost', 10, 2)->nullable()->after('partner_store_name');
        });

        Schema::create('order_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_order_id')->constrained()->cascadeOnDelete();
            $table->string('material_name');
            $table->decimal('quantity_used', 8, 2);
            $table->string('unit')->default('yard');
            $table->decimal('unit_cost', 10, 2)->nullable();
            $table->decimal('subtotal_cost', 10, 2)->nullable();
            $table->foreignId('logged_by_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }
};
