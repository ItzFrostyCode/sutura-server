<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop accounts now sign in with an admin-issued login on the shop domain
 * (e.g. threadneedle@sutura.shop), never the owner's personal Gmail — so a
 * shop login can't collide with, or be mistaken for, a customer account.
 *
 * - contact_email: the owner's real inbox. User::routeNotificationForMail()
 *   sends every email there (approval, credentials, password resets), since
 *   the shop-domain login address doesn't receive mail.
 * - must_change_password: set when an admin issues a temporary password;
 *   cleared once the owner picks their own. Not fillable — only the approval
 *   flow and the password endpoints touch it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('contact_email')->nullable()->after('email');
            $table->boolean('must_change_password')->default(false)->after('password_set_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['contact_email', 'must_change_password']);
        });
    }
};
