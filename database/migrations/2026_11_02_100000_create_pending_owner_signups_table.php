<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shop owner's sign-up before the email is confirmed. Nothing is created in
 * `users` until the owner opens the link in the confirmation email, so a typo
 * or a stranger's address never becomes an account. The row keeps the chosen
 * password already hashed; `poll_token` lets the waiting sign-up page ask
 * "has the link been opened yet?" without exposing the emailed token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_owner_signups', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('token_hash', 64)->unique();
            $table->string('poll_token', 64)->unique();
            $table->timestamp('terms_accepted_at');
            $table->string('terms_version', 20);
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_owner_signups');
    }
};
