<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_package_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('rating')->comment('1 to 5 stars');
            $table->timestamps();
            $table->unique(['service_package_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_package_reviews');
    }
};