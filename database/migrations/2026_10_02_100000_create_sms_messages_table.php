<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Every text message goes through this outbox first: it is drafted, shown to the shop to check the number and
// the wording, and only then sent (or auto-sent, if the shop chose that and the system is out of test mode).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();      // the customer it is for
            $t->string('event', 40);                                                     // appointment_confirmed, order_ready_pickup …
            $t->string('related_type', 40)->nullable();
            $t->unsignedBigInteger('related_id')->nullable();
            $t->string('dedupe_key', 120)->nullable()->unique();                         // one text per event per record
            $t->string('to_number', 20)->nullable();                                     // +639XXXXXXXXX, null when the number is unusable
            $t->string('raw_number', 40)->nullable();                                    // exactly what was on file
            $t->string('body', 400);
            $t->unsignedTinyInteger('segments')->default(1);
            $t->string('status', 16)->default('draft');                                  // draft|approved|sent|failed|blocked|cancelled
            $t->string('blocked_reason')->nullable();
            $t->boolean('is_test')->default(false);                                      // sent through the log driver: nothing was delivered
            $t->string('provider', 20)->nullable();
            $t->string('provider_message_id')->nullable();
            $t->text('error')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->index(['store_id', 'status']);
        });

        Schema::table('stores', function (Blueprint $t) {
            $t->string('sms_mode', 10)->default('review');   // off | review (check each text first) | auto
        });
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('sms_opt_out')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('sms_opt_out'));
        Schema::table('stores', fn (Blueprint $t) => $t->dropColumn('sms_mode'));
        Schema::dropIfExists('sms_messages');
    }
};
