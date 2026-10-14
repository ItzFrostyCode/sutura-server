<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The admin Activity Log now records every action on SUTURA (App\Http\
 * Middleware\RecordActivity), so each row carries the actor's role for the
 * role filter, and a sign-in or a guest action may have no subject model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('actor_role', 30)->nullable()->after('user_id');
            $table->string('model_type')->nullable()->change();
            $table->unsignedBigInteger('model_id')->nullable()->change();
            $table->index('actor_role');
            $table->index('created_at');
        });

        // Existing rows: the actor's role today (admin first, then shop side, then customer).
        foreach (['admin', 'store_owner', 'branch_manager', 'staff', 'customer'] as $role) {
            $userIds = DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->where('roles.name', $role)->pluck('role_user.user_id');
            DB::table('audit_logs')->whereNull('actor_role')->whereIn('user_id', $userIds)->update(['actor_role' => $role]);
        }
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['actor_role']);
            $table->dropIndex(['created_at']);
            $table->dropColumn('actor_role');
        });
    }
};
