<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stores filled-in custom field values for each job order, e.g.:
        // {"Name on Jersey": "Juan Dela Cruz", "Number": "23", "Size": "XL"}
        Schema::table('job_orders', function (Blueprint $table) {
            $table->json('custom_order_data')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('custom_order_data');
        });
    }
};
