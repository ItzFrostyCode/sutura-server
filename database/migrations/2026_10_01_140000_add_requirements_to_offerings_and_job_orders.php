<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Configurable measurement / final-fitting / payment requirements.
// Precedence: design -> its linked service -> store default (a combo uses its
// own setting, else the store default). A job order snapshots the result at
// creation so later edits never rewrite it.
return new class extends Migration
{
    public function up(): void
    {
        // Nullable = "inherit from the next level up".
        foreach (['services', 'catalog_items', 'service_packages'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('measurement_requirement', 16)->nullable();
                $t->string('fitting_requirement', 16)->nullable();
                $t->string('payment_policy', 16)->nullable();
                $t->unsignedTinyInteger('payment_policy_percent')->nullable();
            });
        }

        Schema::table('stores', function (Blueprint $t) {
            $t->string('default_measurement_requirement', 16)->default('shop');
            $t->string('default_fitting_requirement', 16)->default('optional');
            $t->string('default_payment_policy', 16)->default('deposit');
            $t->unsignedTinyInteger('default_payment_percent')->default(50);
        });

        Schema::table('job_orders', function (Blueprint $t) {
            $t->string('measurement_requirement', 16)->default('shop');
            $t->string('fitting_requirement', 16)->default('optional');
            $t->string('payment_policy', 16)->default('deposit');
            $t->unsignedTinyInteger('payment_policy_percent')->default(50);
        });

        // Existing jobs keep exactly the behaviour they had: the old 50% gate,
        // optional fitting, and no new measurement expectation.
        DB::table('job_orders')->update(['measurement_requirement' => 'none']);
    }

    public function down(): void
    {
        foreach (['services', 'catalog_items', 'service_packages'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['measurement_requirement', 'fitting_requirement', 'payment_policy', 'payment_policy_percent']));
        }
        Schema::table('stores', fn (Blueprint $t) => $t->dropColumn(['default_measurement_requirement', 'default_fitting_requirement', 'default_payment_policy', 'default_payment_percent']));
        Schema::table('job_orders', fn (Blueprint $t) => $t->dropColumn(['measurement_requirement', 'fitting_requirement', 'payment_policy', 'payment_policy_percent']));
    }
};
