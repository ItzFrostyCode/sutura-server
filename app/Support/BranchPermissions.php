<?php

namespace App\Support;

use App\Models\BranchPermissionGrant;
use App\Models\Store;
use App\Models\User;

/**
 * The thirteen delegable Branch Manager permissions (B8-O01 1-11 plus the Owner's 12 and 13; DSD-02 Annex B).
 * Deny by default: the shop Owner always may; a Branch Manager only with an active grant for the branch they are
 * pinned to; plain Staff, customers and the System Admin never. Nobody grants to themselves; only the Owner grants.
 */
final class BranchPermissions
{
    /** key => [number in B8-O01 / Annex B, label] */
    public const ALL = [
        'production_authorization' => [1, 'Production assignment and authorization, including assignment override'],
        'refund_approval' => [2, 'Refund approval'],
        'payment_verification' => [3, 'Payment verification'],
        'payment_correction' => [4, 'Payment correction or reversal'],
        'discount' => [5, 'Discount authorization'],
        'capacity_scheduling' => [6, 'Branch capacity and scheduling changes'],
        'staff_management' => [7, 'Staff invitation and branch-level Staff management'],
        'deposit_waiver' => [8, 'Deposit waiver'],
        'workmanship' => [9, 'Workmanship responsibility and free-fix or correction determination'],
        'branch_audit_view' => [10, "Viewing the assigned branch's audit log"],
        'branch_support' => [11, 'Handle Branch Support Requests'],
        'prepare_quotations' => [12, 'Prepare and Issue Quotations'],
        'release_with_balance' => [13, 'Authorize Handover with Outstanding Balance'],
    ];

    public static function allows(User $user, Store $store, ?int $branchId, string $permission): bool
    {
        if ($store->owner_id === $user->id) {
            return true;
        }
        $profile = $user->staffProfile;
        if (! $user->hasRole('branch_manager') || ! $profile || (int) $profile->store_id !== (int) $store->id) {
            return false;
        }
        $pinned = BranchPin::of($user);
        // A manager acts only inside the branch they are pinned to; an order of another branch is never theirs.
        if ($pinned === null || ($branchId !== null && $branchId !== $pinned)) {
            return false;
        }

        return BranchPermissionGrant::active()
            ->where(['user_id' => $user->id, 'store_branch_id' => $pinned, 'permission' => $permission])
            ->exists();
    }

    /** @return list<array{key:string,number:int,label:string}> */
    public static function catalog(): array
    {
        return array_map(fn ($k) => ['key' => $k, 'number' => self::ALL[$k][0], 'label' => self::ALL[$k][1]], array_keys(self::ALL));
    }
}
