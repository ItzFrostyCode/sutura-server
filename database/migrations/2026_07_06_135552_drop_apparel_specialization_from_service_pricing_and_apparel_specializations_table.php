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
        // FK column must go before the parent table it references.
        Schema::table('service_pricing', function (Blueprint $table) {
            $table->dropForeign(['apparel_specialization_id']);
            $table->dropColumn('apparel_specialization_id');
        });

        Schema::dropIfExists('apparel_specializations');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('apparel_specializations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('category', 100)->nullable();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('starting_price', 10, 2)->nullable();
            $table->integer('production_time_days')->nullable();
            $table->integer('min_order_qty')->default(1);
            $table->timestamps();
        });

        Schema::table('service_pricing', function (Blueprint $table) {
            $table->foreignId('apparel_specialization_id')->nullable()->after('service_id')
                ->constrained('apparel_specializations')->nullOnDelete();
        });
    }
};
