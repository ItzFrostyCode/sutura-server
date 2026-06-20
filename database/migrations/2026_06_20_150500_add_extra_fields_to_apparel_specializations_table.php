<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apparel_specializations', function (Blueprint $table) {
            $table->decimal('starting_price', 10, 2)->nullable()->after('description');
            $table->integer('production_time_days')->nullable()->after('starting_price');
            $table->integer('min_order_qty')->default(1)->after('production_time_days');
        });
    }

    public function down(): void
    {
        Schema::table('apparel_specializations', function (Blueprint $table) {
            $table->dropColumn(['starting_price', 'production_time_days', 'min_order_qty']);
        });
    }
};
