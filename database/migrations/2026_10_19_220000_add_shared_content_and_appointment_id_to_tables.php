<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Additional shared content: a discussion note and multiple reference links
            $table->text('shared_notes')->nullable()->after('shared_images');
            $table->json('shared_links')->nullable()->after('shared_notes');
        });

        Schema::table('measurements', function (Blueprint $table) {
            // Relates a measurement session directly to an appointment (while preserving customer and store ownership)
            $table->foreignId('appointment_id')->nullable()->after('customer_id')->constrained('appointments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('measurements', function (Blueprint $table) {
            $table->dropForeign(['appointment_id']);
            $table->dropColumn('appointment_id');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['shared_notes', 'shared_links']);
        });
    }
};
