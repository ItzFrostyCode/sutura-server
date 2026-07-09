<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Plural, array-valued replacements for the old single-value
            // `category`/`service_type` columns — a service can now belong to
            // more than one marketing category and/or drive more than one set
            // of conditional Job Order fields. The old columns are left in
            // place (unused going forward) rather than dropped, since altering/
            // dropping columns on sqlite needs doctrine/dbal, which isn't
            // installed, and keeping them costs nothing.
            $table->json('categories')->nullable()->after('category');
            $table->json('service_types')->nullable()->after('service_type');
        });

        // Backfill existing single values into their one-element array equivalents.
        DB::table('services')->whereNotNull('category')->where('category', '!=', '')->get(['id', 'category'])->each(function ($row) {
            DB::table('services')->where('id', $row->id)->update(['categories' => json_encode([$row->category])]);
        });
        DB::table('services')->whereNotNull('service_type')->where('service_type', '!=', '')->get(['id', 'service_type'])->each(function ($row) {
            DB::table('services')->where('id', $row->id)->update(['service_types' => json_encode([$row->service_type])]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['categories', 'service_types']);
        });
    }
};
