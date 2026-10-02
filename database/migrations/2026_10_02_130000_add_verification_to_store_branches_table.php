<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A branch only appears on the public map, in search, in "near me" and in the booking form once the System Admin has
// checked its location. Branches that already existed are treated as checked (default 'verified'); the two real
// creation paths — a shop owner adding a branch, and a shop application — set 'pending' explicitly.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_branches', function (Blueprint $t) {
            $t->string('verification_status', 12)->default('verified')->after('status');   // pending | verified | rejected
            $t->timestamp('verified_at')->nullable()->after('verification_status');
            $t->foreignId('verified_by')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
            $t->string('verification_note', 300)->nullable()->after('verified_by');         // the admin's reason when rejected
            $t->index('verification_status');
        });
    }

    public function down(): void
    {
        Schema::table('store_branches', function (Blueprint $t) {
            $t->dropConstrainedForeignId('verified_by');
            $t->dropIndex(['verification_status']);
            $t->dropColumn(['verification_status', 'verified_at', 'verification_note']);
        });
    }
};
