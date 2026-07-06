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
        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->boolean('valid_id_captured')->default(false)->after('security_deposit_amount');
            $table->string('valid_id_notes')->nullable()->after('valid_id_captured');
            $table->text('return_inspection_notes')->nullable()->after('valid_id_notes');
            $table->decimal('deposit_deduction_amount', 10, 2)->nullable()->after('return_inspection_notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->dropColumn([
                'valid_id_captured',
                'valid_id_notes',
                'return_inspection_notes',
                'deposit_deduction_amount',
            ]);
        });
    }
};
