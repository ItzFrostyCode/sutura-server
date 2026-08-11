<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shop_branches', function (Blueprint $table) {
            $table->string('slug', 191)->nullable()->unique()->after('name');
        });

        // Same pattern as Shop::slug generation (ShopController@store) —
        // name-slug plus a uniqid() suffix, so pre-existing branches (main
        // branches especially, which share a name like "Main Branch" across
        // every shop) get a public-safe identifier without a collision risk.
        foreach (DB::table('shop_branches')->whereNull('slug')->get() as $branch) {
            DB::table('shop_branches')
                ->where('id', $branch->id)
                ->update(['slug' => Str::slug($branch->name) . '-' . uniqid()]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_branches', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
