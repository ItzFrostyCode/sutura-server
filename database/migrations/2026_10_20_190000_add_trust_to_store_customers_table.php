<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Trusted customer": a per-shop mark set by the owner or a branch manager. New orders for them start with
// no downpayment (the balance is still collected before handover).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_customers', function (Blueprint $table) {
            $table->boolean('is_trusted')->default(false)->after('notes');
            $table->foreignId('trusted_by')->nullable()->after('is_trusted')->constrained('users')->nullOnDelete();
            $table->timestamp('trusted_at')->nullable()->after('trusted_by');
        });
    }

    public function down(): void
    {
        Schema::table('store_customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trusted_by');
            $table->dropColumn(['is_trusted', 'trusted_at']);
        });
    }
};
