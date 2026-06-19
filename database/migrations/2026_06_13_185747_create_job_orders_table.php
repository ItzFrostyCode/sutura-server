<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 50)->unique();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_branch_id')->nullable()->constrained('shop_branches')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services');
            $table->foreignId('assigned_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('measurement_id')->nullable()->constrained('measurements')->nullOnDelete();
            
            $table->decimal('total_amount', 10, 2)->default(0.00);
            $table->decimal('balance', 10, 2)->default(0.00);
            $table->enum('payment_status', ['unpaid', 'partial', 'paid'])->default('unpaid');
            
            $table->enum('status', ['pending', 'cutting', 'sewing', 'fitting', 'ready_for_pickup', 'completed', 'cancelled'])->default('pending');
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_orders');
    }
};
