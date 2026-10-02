<?php

namespace App\Support;

use App\Models\Store;
use Illuminate\Http\JsonResponse;

/**
 * Server-side counterpart of the client's <SubscriptionGate>: a locked screen in the browser is only a
 * courtesy, so anything a higher plan unlocks is also refused here. Rank: basic < pro < premium; a shop
 * with no active or trial subscription counts as Basic (same as the client).
 */
class PlanGate
{
    private const RANK = ['basic' => 1, 'pro' => 2, 'premium' => 3];

    public static function tier(Store $store): string
    {
        $slug = $store->subscription()->whereIn('status', ['active', 'trial'])->with('plan')->first()?->plan?->slug ?? 'basic';
        $slug = strtolower((string) $slug);

        return str_contains($slug, 'premium') ? 'premium' : (str_contains($slug, 'pro') ? 'pro' : 'basic');
    }

    public static function allows(Store $store, string $minimum): bool
    {
        return self::RANK[self::tier($store)] >= self::RANK[$minimum];
    }

    /** Null when the shop's plan is enough, otherwise the 403 to return. */
    public static function require(Store $store, string $minimum, string $featureName): ?JsonResponse
    {
        if (self::allows($store, $minimum)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'code' => 'plan_required',
            'required_plan' => $minimum,
            'message' => "{$featureName} is part of the ".ucfirst($minimum).' plan. Upgrade your plan to use it.',
        ], 403);
    }
}
