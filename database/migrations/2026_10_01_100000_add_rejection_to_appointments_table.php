<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Set when the shop declines a pending request (status = rejected). A rejection is not a cancellation.
            $table->string('rejection_reason_code', 40)->nullable()->after('cancellation_reason');
            $table->text('rejection_note')->nullable()->after('rejection_reason_code');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['rejection_reason_code', 'rejection_note']);
        });
    }
};
