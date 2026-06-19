<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_branch_id')->nullable()->constrained('shop_branches')->nullOnDelete();
            $table->enum('role', ['head_tailor', 'tailor', 'assistant', 'receptionist'])->default('tailor');
            $table->string('specialization', 255)->nullable();
            $table->text('bio')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('hired_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'shop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_profiles');
    }
};
