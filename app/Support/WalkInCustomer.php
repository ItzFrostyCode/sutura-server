<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A customer at the counter. A consultation starts only once the shop knows who it is talking to (name and mobile
 * number), so this makes the same synthetic walkin_ account the Customers tab makes (it has no real email, and the
 * screens never show it). A number the shop already knows gives back that customer instead of a second one.
 * An older placeholder with no name can still be swapped for the real customer ({@see self::replace()}).
 */
class WalkInCustomer
{
    public const PLACEHOLDER_NAME = 'Walk-in customer';

    public static function make(Store $store, ?string $name = null, ?string $phone = null, ?string $gender = null): User
    {
        $normalized = $phone ? PhoneNumber::normalize($phone) : null;
        if ($normalized) {
            $known = self::knownByPhone($store, $normalized, $phone);
            if ($known) {
                $store->customers()->syncWithoutDetaching([$known->id => []]);

                return $known;
            }
        }

        $user = User::create([
            'email' => 'walkin_'.time().'_'.Str::random(4).'@sutura.com',
            'name' => trim((string) $name) !== '' ? trim((string) $name) : self::PLACEHOLDER_NAME,
            'phone' => $normalized,
            'gender' => in_array($gender, ['male', 'female'], true) ? $gender : null,
            'password' => Hash::make(Str::random(12)),
        ]);
        $store->customers()->syncWithoutDetaching([$user->id => ['notes' => null]]);

        return $user;
    }

    /** The shop's own customer with this number: a customer of the shop, or someone with an order or visit here. */
    private static function knownByPhone(Store $store, string $normalized, string $raw): ?User
    {
        $ids = collect()
            ->merge($store->customers()->pluck('users.id'))
            ->merge($store->jobOrders()->pluck('customer_id'))
            ->merge($store->appointments()->pluck('customer_id'))
            ->filter()->unique();

        return User::whereIn('id', $ids)->whereIn('phone', [$normalized, $raw])->first();
    }

    public static function isPlaceholder(User $user): bool
    {
        return str_starts_with((string) $user->email, 'walkin_');
    }

    /** Remove a nameless placeholder customer once nothing at the shop uses it any more. */
    public static function dropIfUnused(Store $store, User $user): void
    {
        if (self::isPlaceholder($user) && $user->name === self::PLACEHOLDER_NAME
            && ! $store->appointments()->where('customer_id', $user->id)->exists()
            && ! $store->jobOrders()->where('customer_id', $user->id)->exists()
            && ! $store->measurements()->where('customer_id', $user->id)->exists()) {
            $store->customers()->detach($user->id);
            $user->forceDelete();
        }
    }

    /** Move a consultation to the real customer; drop the empty placeholder if nothing else uses it. */
    public static function replace(Store $store, Appointment $appointment, User $real): void
    {
        $old = $appointment->customer;
        $appointment->update(['customer_id' => $real->id]);
        $store->customers()->syncWithoutDetaching([$real->id => []]);

        if ($old && $old->id !== $real->id) {
            self::dropIfUnused($store, $old);
        }
    }
}
