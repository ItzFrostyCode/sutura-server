<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happens once a job passes its store.fitting_limit: charge
 * fitting_fee (existing default) or refuse to schedule another fitting
 * round at all ("no more adjustments").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('fitting_limit_policy')->default('fee')->after('fitting_limit');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('fitting_limit_policy');
        });
    }
};
