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
        } else {
            // Postgres never gets a native enum TYPE from Laravel's `enum()`
            // schema builder the way this migration's old pgsql branch assumed
            // (there's no `job_orders_status_enum` type to ALTER) -- SQLite has
            // the same story. Both just need the column widened to fit the new
            // values, which the later convert_enum_columns_to_strings_for_role_and_status
            // migration also does permanently.
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
