<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A follow-up order (a free fix, a repeat or a customer's change after pick-up) remembers the completed order it
// follows. Not parent_order_id: that column means "same batch / group".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->foreignId('follow_up_of_id')->nullable()->after('parent_order_id')
                ->constrained('job_orders')->nullOnDelete();
            $table->index('follow_up_of_id');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropForeign(['follow_up_of_id']);
            $table->dropIndex(['follow_up_of_id']);
            $table->dropColumn('follow_up_of_id');
        });
    }
};
