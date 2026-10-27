<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Services a shop typed in at registration ("Add Other Service") that aren't
// in the standard list. Kept apart from `specializations`, which stays the
// fixed set that search/map filter chips use.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->json('custom_services')->nullable()->after('specializations');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('custom_services');
        });
    }
};
