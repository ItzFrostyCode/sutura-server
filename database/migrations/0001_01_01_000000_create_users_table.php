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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('phone', 20)->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        // password_reset_tokens is NOT created here — it's owned by the
        // dedicated 2026_08_06_140230_create_password_reset_tokens_table
        // migration. This file used to create it too (a duplicate left over
        // from before that migration existed) — harmless on the local dev
        // MySQL DB, since both migrations were already recorded as "Ran"
        // before the duplication was introduced, so neither ever re-executes
        // here. But on any genuinely fresh migration run — the test suite's
        // SQLite :memory: DB, a new teammate's first setup, or the real
        // Postgres migration planned for September — this duplicate
        // `Schema::create` collided with the dedicated migration's own
        // create and failed outright with "table already exists" every
        // single time. Confirmed live: all 38 feature tests were failing on
        // this exact error before removing the duplicate.
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
