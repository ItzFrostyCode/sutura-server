<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rental lifecycle management (available → reserved → rented → returned →
 * inspection → cleaning) is explicitly excluded from SUTURA's approved
 * thesis scope. Catalog is made-to-order only. These columns were added for
 * a rental feature that never shipped and are never read anywhere in
 * app/Http — confirmed via a full-codebase grep before dropping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->dropColumn([
                'rental_start_date',
                'rental_end_date',
                'security_deposit_amount',
                'valid_id_captured',
                'valid_id_notes',
                'return_inspection_notes',
                'deposit_deduction_amount',
                'order_mode',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->date('rental_start_date')->nullable();
            $table->date('rental_end_date')->nullable()->after('rental_start_date');
            $table->decimal('security_deposit_amount', 10, 2)->nullable()->after('rental_end_date');
            $table->boolean('valid_id_captured')->default(false)->after('security_deposit_amount');
            $table->string('valid_id_notes')->nullable()->after('valid_id_captured');
            $table->text('return_inspection_notes')->nullable()->after('valid_id_notes');
            $table->decimal('deposit_deduction_amount', 10, 2)->nullable()->after('return_inspection_notes');
            $table->string('order_mode')->default('sale');
        });
    }
};
