<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A combo package gets its own service category (so it can be searched and
// filtered like a service), and appointments / job orders remember which
// package they came from — one job order for the whole set.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            $table->string('service_category', 60)->nullable()->after('description');
        });
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('service_package_id')->nullable()->after('service_id')->constrained('service_packages')->nullOnDelete();
        });
        Schema::table('job_orders', function (Blueprint $table) {
            $table->foreignId('service_package_id')->nullable()->after('service_id')->constrained('service_packages')->nullOnDelete();
        });

        // Existing packages: take the category most of their services share.
        foreach (DB::table('service_packages')->pluck('id') as $id) {
            $top = DB::table('service_package_items')
                ->join('services', 'services.id', '=', 'service_package_items.service_id')
                ->where('service_package_items.service_package_id', $id)
                ->whereNotNull('services.service_category')
                ->select('services.service_category', DB::raw('count(*) as n'))
                ->groupBy('services.service_category')
                ->orderByDesc('n')
                ->value('services.service_category');
            if ($top) {
                DB::table('service_packages')->where('id', $id)->update(['service_category' => $top]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_package_id');
        });
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_package_id');
        });
        Schema::table('service_packages', function (Blueprint $table) {
            $table->dropColumn('service_category');
        });
    }
};
