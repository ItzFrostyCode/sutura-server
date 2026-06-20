<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add custom_fields to services
        // Stored as JSON array of field definitions, e.g.:
        // [
        //   {"id": "uuid", "label": "Name on Jersey", "type": "text", "required": true},
        //   {"id": "uuid", "label": "Number", "type": "number", "required": true},
        //   {"id": "uuid", "label": "Size", "type": "size", "required": true},
        // ]
        Schema::table('services', function (Blueprint $table) {
            $table->json('custom_fields')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('custom_fields');
        });
    }
};
