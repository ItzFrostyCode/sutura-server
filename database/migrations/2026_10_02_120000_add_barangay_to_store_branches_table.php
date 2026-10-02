<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The barangay (e.g. Ubalde) is finer than the 8 Davao districts discovery filters on (Agdao, Talomo …);
// it is shown in the address and searchable, but it is not a filter dropdown.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_branches', fn (Blueprint $t) => $t->string('barangay', 100)->nullable()->after('landmark'));
    }

    public function down(): void
    {
        Schema::table('store_branches', fn (Blueprint $t) => $t->dropColumn('barangay'));
    }
};
