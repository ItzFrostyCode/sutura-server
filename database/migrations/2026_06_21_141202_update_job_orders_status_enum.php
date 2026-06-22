<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::connection($this->getConnection())->getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE job_orders MODIFY COLUMN status ENUM('pending', 'cutting', 'sewing', 'fitting', 'ready_for_pickup', 'packed', 'handed_to_courier', 'completed', 'cancelled') NOT NULL DEFAULT 'pending'");
        } elseif ($driver === 'pgsql') {
            // PostgreSQL enum update
            DB::statement("ALTER TYPE job_orders_status_enum ADD VALUE IF NOT EXISTS 'packed'");
            DB::statement("ALTER TYPE job_orders_status_enum ADD VALUE IF NOT EXISTS 'handed_to_courier'");
        } else {
            // SQLite/Fallback
            Schema::table('job_orders', function (Blueprint $table) {
                $table->string('status')->default('pending')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::connection($this->getConnection())->getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE job_orders MODIFY COLUMN status ENUM('pending', 'cutting', 'sewing', 'fitting', 'ready_for_pickup', 'completed', 'cancelled') NOT NULL DEFAULT 'pending'");
        } elseif ($driver === 'pgsql') {
            // PGSQL does not support dropping enum values easily
        } else {
            Schema::table('job_orders', function (Blueprint $table) {
                $table->string('status')->default('pending')->change();
            });
        }
    }
};
