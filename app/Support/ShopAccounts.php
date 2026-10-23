<?php

namespace App\Support;

use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\Store;
use App\Models\StoreBranch;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Accounts a shop owner or branch manager creates for the shop's people (staff, branch managers).
 * One place for the rules both Add Staff and Add Branch share: an email that already has an account is refused
 * (never converted), the plan's staff limit counts managers too, and the new login must change its password on
 * first sign-in (users.must_change_password, the same flag and gate admin-issued shop logins use).
 */
final class ShopAccounts
{
    public const EMAIL_TAKEN = 'This email already has an account.';

    /** Same rule as public Sign Up (AppServiceProvider Password::defaults). */
    public static function passwordRules(string $presence = 'required'): array
    {
        return [$presence, 'string', 'confirmed', Password::defaults()];
    }

    /** Any account at all (customers, owners, staff, soft-deleted ones included) owns the email. */
    public static function emailTaken(string $email): bool
    {
        return User::withTrashed()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->exists();
    }

    public static function emailTakenResponse(string $field = 'email'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => self::EMAIL_TAKEN,
            'errors' => [$field => [self::EMAIL_TAKEN]],
        ], 422);
    }

    /** The plan's staff limit counts every staff profile of the shop, branch managers included. */
    public static function staffLimitMessage(Store $store): ?string
    {
        $subscription = $store->subscription()->whereIn('status', ['active', 'trial'])->first();
        // No live subscription falls back to the cheapest plan's cap, never to "unlimited".
        $max = $subscription?->plan?->max_staff ?? SubscriptionPlan::where('slug', 'basic')->value('max_staff') ?? 1;
        // -1 is the plans table's "unlimited" sentinel.
        if ($max === -1 || $store->staff()->count() < $max) {
            return null;
        }

        return "Your plan allows up to {$max} staff member".($max === 1 ? '' : 's').'. Upgrade your plan to add more.';
    }

    /** A staff account is either a plain 'staff' or a 'branch_manager' at the platform-role level, never both. */
    public static function syncPlatformRole(User $user, bool $isBranchManager): void
    {
        $target = Role::where('name', $isBranchManager ? 'branch_manager' : 'staff')->first();
        $other = Role::where('name', $isBranchManager ? 'staff' : 'branch_manager')->first();

        if ($other) {
            $user->roles()->detach($other->id);
        }
        if ($target && ! $user->roles()->where('roles.id', $target->id)->exists()) {
            $user->roles()->attach($target->id);
        }
    }

    /**
     * New login + staff profile pinned to the branch. $data: name, email, password, gender, phone, role, additional_roles,
     * specialization, hired_at, bio (all but name/email/password optional). The caller checks the email first.
     */
    public static function create(Store $store, StoreBranch $branch, array $data, bool $branchManager): StaffProfile
    {
        $user = new User;
        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'password_set_at' => now(),
            'phone' => $data['phone'] ?? null,
            'gender' => $data['gender'] ?? null,
        ]);
        // Not fillable on purpose: the person types their own password on first sign-in.
        $user->must_change_password = true;
        $user->save();

        self::syncPlatformRole($user, $branchManager);

        $profile = $store->staff()->create([
            'user_id' => $user->id,
            'role' => $data['role'] ?? ($branchManager ? 'head_tailor' : 'tailor'),
            'additional_roles' => $data['additional_roles'] ?? null,
            'specialization' => $data['specialization'] ?? null,
            'hired_at' => $data['hired_at'] ?? ($branchManager ? now()->toDateString() : null),
            'bio' => $data['bio'] ?? null,
            'store_branch_id' => $branch->id,
            'is_branch_manager' => $branchManager,
            'is_active' => true,
        ]);

        self::forgetRoster($store);

        return $profile;
    }

    /** Makes an existing staff member of the shop the manager of this branch (moving them there). */
    public static function promote(Store $store, StoreBranch $branch, StaffProfile $profile): void
    {
        $profile->update(['store_branch_id' => $branch->id, 'is_branch_manager' => true]);
        if ($profile->user) {
            self::syncPlatformRole($profile->user, true);
        }
        self::forgetRoster($store);
    }

    /** Why this staff member cannot be made a manager, or null. */
    public static function promotionProblem(Store $store, ?StaffProfile $profile): ?string
    {
        if (! $profile || (int) $profile->store_id !== (int) $store->id || ! $profile->user) {
            return 'Pick a staff member of this shop.';
        }
        if ($profile->user->hasRole('store_owner')) {
            return 'The shop owner cannot be a branch manager.';
        }
        if ($profile->is_branch_manager) {
            return 'This person already manages a branch.';
        }
        if (! $profile->is_active) {
            return 'This staff member is not active.';
        }

        return null;
    }

    public static function forgetRoster(Store $store): void
    {
        Cache::driver('file')->forget("store_staff_{$store->id}");
    }
}
