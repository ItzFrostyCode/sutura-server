<?php

namespace App\Support;

use App\Models\User;

/**
 * The one branch a branch manager or staff account is pinned to (their staff profile's branch), or null for the
 * shop owner and for accounts with no branch (a single-branch shop). Request ids never widen it: callers use this
 * value, not a branch_id from the request, to scope what such a user can read or write.
 */
final class BranchPin
{
    public static function of(User $user): ?int
    {
        if ($user->hasRole('store_owner')) {
            return null;
        }
        $branch = $user->staffProfile?->store_branch_id;

        return $branch ? (int) $branch : null;
    }
}
