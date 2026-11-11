<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B8-O01 + permissions 12 and 13 (DSD-02 Annex B, WD-05 / WD-19): the Owner grants each delegable permission to a
 * Branch Manager individually, per branch. Revoked grants stay as history (revoked_at set).
 *
 * Existing Branch Managers keep what their role already let them do (permissions 1, 2, 3, 4, 5, 8, 9, 12, 13), so this
 * change does not silently take abilities away from shops already using SUTURA; the Owner can revoke any of them.
 * A Branch Manager added from now on starts with none.
 */
return new class extends Migration
{
    private const ROLE_ERA = ['production_authorization', 'refund_approval', 'payment_verification', 'payment_correction',
        'discount', 'deposit_waiver', 'workmanship', 'prepare_quotations', 'release_with_balance'];

    public function up(): void
    {
        Schema::create('branch_permission_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_branch_id')->constrained('store_branches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 40);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'store_branch_id', 'permission']);
        });

        $managers = DB::table('staff_profiles')
            ->join('stores', 'stores.id', '=', 'staff_profiles.store_id')
            ->where('staff_profiles.is_branch_manager', true)
            ->whereNotNull('staff_profiles.store_branch_id')
            ->get(['staff_profiles.user_id', 'staff_profiles.store_id', 'staff_profiles.store_branch_id', 'stores.owner_id']);
        $now = now();
        foreach ($managers as $m) {
            foreach (self::ROLE_ERA as $permission) {
                DB::table('branch_permission_grants')->insert([
                    'store_id' => $m->store_id, 'store_branch_id' => $m->store_branch_id, 'user_id' => $m->user_id,
                    'permission' => $permission, 'granted_by' => $m->owner_id, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_permission_grants');
    }
};
