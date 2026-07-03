<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('measurements', function (Blueprint $table) {
            // Who authored the record: the shop owner/tailor's specialized format,
            // or the customer (walk-in / online public form).
            $table->string('source')->default('shop_owner')->after('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('measurements', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
