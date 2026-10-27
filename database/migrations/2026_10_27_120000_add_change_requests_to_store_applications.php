<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Request Changes" on a shop application. The store stays status=pending
// (hidden everywhere) the whole time; review_state says where the round is:
// changes_requested (waiting on the owner) → resubmitted (back with the admin).
// The owner can't sign in before approval, so they update through a private
// emailed link: only its sha256 hash is stored, and it expires.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_applications', function (Blueprint $table) {
            $table->string('review_state', 30)->nullable()->after('reviewed_at');
            $table->json('change_request_items')->nullable()->after('review_state');
            $table->text('change_request_note')->nullable()->after('change_request_items');
            $table->timestamp('change_requested_at')->nullable()->after('change_request_note');
            $table->foreignId('change_requested_by')->nullable()->after('change_requested_at')->constrained('users')->nullOnDelete();
            $table->timestamp('resubmitted_at')->nullable()->after('change_requested_by');
            $table->string('update_token_hash', 64)->nullable()->unique()->after('resubmitted_at');
            $table->timestamp('update_token_expires_at')->nullable()->after('update_token_hash');
            // Past rounds: [{items, note, requested_at, requested_by, resubmitted_at}].
            $table->json('change_history')->nullable()->after('update_token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('store_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('change_requested_by');
            $table->dropUnique(['update_token_hash']);
            $table->dropColumn([
                'review_state', 'change_request_items', 'change_request_note', 'change_requested_at',
                'resubmitted_at', 'update_token_hash', 'update_token_expires_at', 'change_history',
            ]);
        });
    }
};
