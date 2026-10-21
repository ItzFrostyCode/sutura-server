<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Linked orders come in two kinds: 'batch' (the same design split by who pays) and 'set' (different services the same
// customer asked for at once, each made and paid on its own). Null = never linked.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('group_kind', 10)->nullable();
        });
        // Orders already split before this column existed were batches.
        \Illuminate\Support\Facades\DB::table('job_orders')->whereNotNull('batch_no')->update(['group_kind' => 'batch']);
    }

    public function down(): void
    {
        Schema::table('job_orders', fn (Blueprint $table) => $table->dropColumn('group_kind'));
    }
};
