<?php

namespace App\Support;

use App\Models\User;

/**
 * Whether an email already belongs to a real account. One rule for customer sign-up, shop-owner sign-up and the
 * "is this email free?" check, so they can never disagree.
 *
 * A passwordless "shadow" account (made by a guest booking or a walk-in) does NOT count as taken: signing up claims it.
 * A shop account (owner, manager, staff) or an admin always counts, even before a password is set.
 */
class EmailAvailability
{
    public static function taken(string $email): bool
    {
        $user = User::where('email', $email)->first();
        if (! $user) {
            return false;
        }

        return $user->password_set_at !== null
            || $user->roles()->whereIn('name', ['store_owner', 'branch_manager', 'staff', 'admin'])->exists();
    }
}
