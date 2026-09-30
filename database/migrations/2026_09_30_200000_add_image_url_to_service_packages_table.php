<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 2026_09_30_143931 already adds service_packages.image_url. This one only
// fills the gap on a database that somehow lacks it, so a fresh migrate never
// hits "duplicate column".
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('service_packages', 'image_url')) {
            return;
        }
        Schema::table('service_packages', function (Blueprint $table) {
            $table->string('image_url', 2048)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        // Owned by 2026_09_30_143931 — rolling this one back must not drop it.
    }
};
