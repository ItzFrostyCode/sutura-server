<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // These three columns were originally created as rigid database-level
        // ENUMs with a short initial value list. Since then, StaffProfile::ROLES,
        // JobOrder::STATUSES, and Appointment::STATUSES (the real, single source
        // of truth already enforced via Rule::in() at the application layer)
        // all grew well past what the ENUM definitions still allow — 13 roles
        // vs 4, 12 job statuses vs 9, 6 appointment statuses vs 4. SQLite never
        // enforces ENUM value sets the way MySQL does, so this drift was
        // completely invisible in local dev and would have silently rejected/
        // truncated data (e.g. 'senior_designer', 'design', 'in_progress') the
        // moment this ran on real MySQL/PlanetScale. Converting to plain
        // strings — validated only at the app layer, which was already the
        // real authority — removes this whole class of bug going forward.
        //
        // On Postgres specifically, the original enum() calls created named
        // CHECK constraints (staff_profiles_role_check, job_orders_status_check,
        // appointments_status_check) that a plain string()->change() does NOT
        // drop -- it only changes the column type while the old constraint
        // keeps silently enforcing the original short value list underneath.
        // Drop those explicitly first so the conversion actually removes the
        // restriction on Postgres, not just on paper.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE staff_profiles DROP CONSTRAINT IF EXISTS staff_profiles_role_check');
            DB::statement('ALTER TABLE job_orders DROP CONSTRAINT IF EXISTS job_orders_status_check');
            DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_status_check');
        }

        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->string('role', 50)->default('tailor')->change();
        });

        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('status', 30)->default('pending')->change();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->enum('role', ['head_tailor', 'tailor', 'assistant', 'receptionist'])->default('tailor')->change();
        });

        Schema::table('job_orders', function (Blueprint $table) {
            $table->enum('status', ['pending', 'cutting', 'sewing', 'fitting', 'ready_for_pickup', 'packed', 'handed_to_courier', 'completed', 'cancelled'])->default('pending')->change();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->enum('status', ['pending', 'confirmed', 'completed', 'cancelled'])->default('pending')->change();
        });
    }
};
