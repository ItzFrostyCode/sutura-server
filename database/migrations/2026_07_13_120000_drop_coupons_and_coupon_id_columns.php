<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coupons (reusable discount codes) are replaced by a manual per-order
 * discount the shop owner applies directly, informed by the customer's job
 * order count — see discount_amount, which stays on both tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
        });

        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
        });

        Schema::dropIfExists('coupons');
    }

    public function down(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->enum('discount_type', ['percent', 'fixed']);
            $table->decimal('discount_value', 10, 2);
            $table->enum('applies_to', ['all', 'catalog', 'services'])->default('all');
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['shop_id', 'code']);
        });

        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('selected_size')->constrained()->nullOnDelete();
        });

        Schema::table('job_orders', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
        });
    }
};
