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
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedInteger('min_order_qty')->default(1)->after('estimated_days');
            // Small, functional taxonomy driving which conditional Job Order
            // fields appear (see JobCreateForm) — distinct from the free-text
            // `category`/`tags` fields, which stay as rich marketing labels.
            $table->string('service_type', 30)->nullable()->after('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['min_order_qty', 'service_type']);
        });
    }
};
