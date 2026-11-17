<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A branch's operating_hours holds the same 7-day JSON the store's own
     * (json-typed) column does — 409 bytes for a normal week — but this
     * column was created as varchar(191). The LocalTestSeeder's Thread &
     * Needle hours overflowed it: strict-mode MySQL aborts the seed, and
     * non-strict mode silently truncates the JSON. Text stores the full
     * value on both; the model reads it back as a raw string either way.
     */
    public function up(): void
    {
        Schema::table('store_branches', function (Blueprint $table) {
            $table->text('operating_hours')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('store_branches', function (Blueprint $table) {
            $table->string('operating_hours')->nullable()->change();
        });
    }
};
