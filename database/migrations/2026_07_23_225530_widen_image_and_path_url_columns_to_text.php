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
        // These were all left at the default varchar(255) for image/file URL
        // columns. That's fine for a short local relative path like
        // '/catalog/name.jpg', but real cloud storage URLs (R2/S3: domain +
        // bucket + URL-encoded filename) routinely exceed 255 characters --
        // confirmed live during the R2 migration test, where a long, verbose
        // seeded catalog filename hit "value too long for type character
        // varying(255)" on Postgres. job_orders.completion_photo_url,
        // services.image_url, and shop_special_hours.announcement_image_url
        // were already widened for exactly this reason at some point -- this
        // just applies the same fix everywhere else it was missed, using TEXT
        // (no practical length limit) instead of another arbitrary number.
        Schema::table('shops', function (Blueprint $table) {
            $table->text('logo_path')->nullable()->change();
        });

        Schema::table('shop_branches', function (Blueprint $table) {
            $table->text('guide_image_url')->nullable()->change();
        });

        Schema::table('catalog_images', function (Blueprint $table) {
            $table->text('image_url')->change();
        });

        Schema::table('catalog_items', function (Blueprint $table) {
            $table->text('fabric_image_url')->nullable()->change();
            $table->text('size_chart_image_url')->nullable()->change();
            $table->text('external_gallery_url')->nullable()->change();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->text('size_chart_image_url')->nullable()->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->text('receipt_path')->nullable()->change();
        });

        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->text('payment_receipt_path')->nullable()->change();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->text('payment_receipt_path')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->change();
        });

        Schema::table('shop_branches', function (Blueprint $table) {
            $table->string('guide_image_url')->nullable()->change();
        });

        Schema::table('catalog_images', function (Blueprint $table) {
            $table->string('image_url')->change();
        });

        Schema::table('catalog_items', function (Blueprint $table) {
            $table->string('fabric_image_url')->nullable()->change();
            $table->string('size_chart_image_url')->nullable()->change();
            $table->string('external_gallery_url')->nullable()->change();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->string('size_chart_image_url')->nullable()->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('receipt_path')->nullable()->change();
        });

        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->string('payment_receipt_path')->nullable()->change();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->string('payment_receipt_path')->nullable()->change();
        });
    }
};
