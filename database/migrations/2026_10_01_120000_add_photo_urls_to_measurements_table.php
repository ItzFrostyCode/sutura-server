<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('measurements', function (Blueprint $table) {
            // Photos of a paper measurement sheet, attached instead of (or next to) typed values.
            $table->json('photo_urls')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('measurements', function (Blueprint $table) {
            $table->dropColumn('photo_urls');
        });
    }
};
