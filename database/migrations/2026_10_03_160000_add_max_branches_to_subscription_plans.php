<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branch limit per plan, editable on the admin Plans page like max_staff
 * (-1 = unlimited). Before this it was hardcoded: Premium unlimited, every
 * other plan one branch — existing plans start with exactly that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->integer('max_branches')->nullable()->after('max_staff');
        });

        DB::table('subscription_plans')->where('slug', 'like', '%premium%')->update(['max_branches' => -1]);
        DB::table('subscription_plans')->whereNull('max_branches')->update(['max_branches' => 1]);
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('max_branches');
        });
    }
};
