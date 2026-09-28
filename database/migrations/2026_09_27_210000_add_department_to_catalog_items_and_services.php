<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The public header nav (men/women/wedding/office) sends
        // ?department= on nearly every link, but nothing on the backend
        // ever read it — every department under a given category returned
        // the exact same, undifferentiated result set. One shared taxonomy
        // dimension for both catalog items and services (Service already
        // has its own free-text `category`/`categories` for marketing
        // copy — this is a separate, structured field, not a replacement).
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->string('department', 20)->nullable()->after('garment_type');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->string('department', 20)->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn('department');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('department');
        });
    }
};
