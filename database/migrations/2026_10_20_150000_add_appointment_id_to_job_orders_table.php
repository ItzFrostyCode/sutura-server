<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The consultation a job order was made from. (appointments.job_order_id keeps its job's fittings and pick-ups; this is the other direction.)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $t) {
            $t->foreignId('appointment_id')->nullable()->after('store_branch_id')->constrained()->nullOnDelete();
        });

        // Orders made from a consultation before this: the appointment already points at its order.
        DB::statement("UPDATE job_orders SET appointment_id = (SELECT MIN(a.id) FROM appointments a WHERE a.job_order_id = job_orders.id AND a.kind != 'production') WHERE appointment_id IS NULL");
    }

    public function down(): void
    {
        Schema::table('job_orders', fn (Blueprint $t) => $t->dropConstrainedForeignId('appointment_id'));
    }
};
