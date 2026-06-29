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
        Schema::table('users', function (Blueprint $table) {
            $table->text('bio')->nullable()->after('cover_photo');
            $table->json('experience')->nullable()->after('bio');
            $table->json('education')->nullable()->after('experience');
            $table->json('skills')->nullable()->after('education');
            $table->json('social_links')->nullable()->after('skills');
            $table->json('creations_gallery')->nullable()->after('social_links');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['bio', 'experience', 'education', 'skills', 'social_links', 'creations_gallery']);
        });
    }
};
