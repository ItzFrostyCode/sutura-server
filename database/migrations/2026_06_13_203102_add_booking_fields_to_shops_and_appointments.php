<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->text('booking_policy')->nullable()->after('currency');
            $table->json('booking_questions')->nullable()->after('booking_policy');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->json('answers')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['booking_policy', 'booking_questions']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('answers');
        });
    }
};
