<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Whether a customer's measurements are final or still need a fitting check.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('measurements', function (Blueprint $t) {
            $t->string('status', 20)->default('finalized')->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('measurements', fn (Blueprint $t) => $t->dropColumn('status'));
    }
};
