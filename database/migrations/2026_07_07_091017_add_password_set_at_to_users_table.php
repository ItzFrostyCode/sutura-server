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
        Schema::table('users', function (Blueprint $table) {
            // Null = an unclaimed "shadow" account auto-created by a guest
            // booking (random, never-communicated password). Set once a real
            // password is chosen — either by self-registering or by an owner
            // creating a staff account directly. Lets /auth/register tell the
            // two cases apart instead of just blocking on a duplicate email.
            $table->timestamp('password_set_at')->nullable()->after('password');
        });

        // Backfill: only shop owners and staff definitely have a real,
        // known password (self-registered or set by the owner directly).
        // Plain customer-only accounts are left unclaimed (null), since many
        // of those are guest-booking shadow accounts with an unknown
        // randomly-generated password.
        \DB::table('users')
            ->whereNull('password_set_at')
            ->whereIn('id', function ($query) {
                $query->select('user_id')
                    ->from('role_user')
                    ->join('roles', 'roles.id', '=', 'role_user.role_id')
                    ->whereIn('roles.name', ['shop_owner', 'staff', 'branch_manager', 'admin']);
            })
            ->update(['password_set_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_set_at');
        });
    }
};
