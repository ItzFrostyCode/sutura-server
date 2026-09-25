<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recently_viewed', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Polymorphic (viewable_type/viewable_id) rather than three
            // nullable FKs — one row shape covers Store/CatalogItem/Service
            // alike, same pattern AuditLog already uses in this codebase.
            $table->morphs('viewable');
            // Separate from created_at: re-viewing something already in the
            // table updates this (bumps it to the top of "recently viewed")
            // without touching created_at or creating a duplicate row.
            $table->timestamp('viewed_at');
            $table->timestamps();

            $table->unique(['user_id', 'viewable_type', 'viewable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recently_viewed');
    }
};
