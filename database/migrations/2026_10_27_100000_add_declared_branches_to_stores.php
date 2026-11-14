<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Other locations a shop listed at registration ("Branches (Optional)"):
// [{name, address}], shown to the admin and in the profile's About tab.
// Display only, on any plan; real branches (pin, booking) stay in
// store_branches and need the Premium plan.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->json('declared_branches')->nullable()->after('custom_services');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('declared_branches');
        });
    }
};
