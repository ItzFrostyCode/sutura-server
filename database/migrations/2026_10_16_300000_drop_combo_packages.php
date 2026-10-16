<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Combo packages were removed from the product: one order is for one service or design.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['appointments', 'job_orders'] as $table) {
            if (Schema::hasColumn($table, 'service_package_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->dropForeign([ 'service_package_id' ]);
                    $t->dropColumn('service_package_id');
                });
            }
        }
        Schema::dropIfExists('service_package_reviews');
        Schema::dropIfExists('service_package_items');
        Schema::dropIfExists('service_packages');
    }

    public function down(): void
    {
        // Not restorable: the feature and its data are gone on purpose.
    }
};
