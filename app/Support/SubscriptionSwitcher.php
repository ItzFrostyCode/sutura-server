<?php

namespace App\Support;

use App\Models\Store;
use App\Models\StoreSubscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;

/**
 * Moving a store onto a plan — shared by the direct switch (free / cheaper
 * plans, admins) and by an admin approving a paid upgrade request, so both go
 * through the same limit checks and leave the same subscription history.
 */
class SubscriptionSwitcher
{
    /** Why the store's current usage does not fit this plan, or null when it does. */
    public static function misfit(Store $store, SubscriptionPlan $plan): ?string
    {
        $currentStaffCount = $store->staff()->count();
        if ($plan->max_staff !== -1 && $currentStaffCount > $plan->max_staff) {
            return "This plan allows up to {$plan->max_staff} staff member".($plan->max_staff === 1 ? '' : 's').", but you currently have {$currentStaffCount}. Remove staff first, or choose a plan that fits your current team size.";
        }

        $currentBranchCount = $store->branches()->count();
        if ($plan->slug !== 'premium' && $currentBranchCount > 1) {
            return "This plan only supports a single branch, but you currently have {$currentBranchCount}. Remove the extra branches first, or stay on a plan that supports multiple branches.";
        }


        return null;
    }

    /** True when getting onto this plan means paying (a paid plan that is not a step down). */
    public static function needsPayment(Store $store, SubscriptionPlan $plan, string $cycle): bool
    {
        $price = (float) ($cycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly);
        if ($price <= 0) {
            return false;
        }

        $current = StoreSubscription::with('plan')
            ->where('store_id', $store->id)
            ->whereIn('status', ['active', 'trial'])
            ->latest()
            ->first();

        return ! $current || (float) $plan->price_monthly >= (float) $current->plan->price_monthly;
    }

    public static function apply(Store $store, SubscriptionPlan $plan, string $cycle, int $triggeredBy): StoreSubscription
    {
        // Read the previous subscription BEFORE cancelling it — needed to
        // classify the event below (created/renewed/upgraded/downgraded),
        // and the update() call two lines down overwrites its status to
        // 'cancelled' with no way to read the "was this the same plan or a
        // different one" fact afterward.
        $previousSubscription = StoreSubscription::where('store_id', $store->id)
            ->whereIn('status', ['active', 'trial'])
            ->latest()
            ->first();

        // Cancel previous active subscription if it exists
        StoreSubscription::where('store_id', $store->id)
            ->whereIn('status', ['active', 'trial'])
            ->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'ends_at' => now(),
            ]);

        $days = $cycle === 'yearly' ? 365 : 30;

        $newSubscription = StoreSubscription::create([
            'store_id' => $store->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addDays($days),
        ]);

        // Mirrors app:expire-subscriptions' auto-hide-on-expiry — a store the
        // system hid for a lapsed subscription should come back the moment
        // the owner renews, not stay hidden until they separately notice and
        // flip the visibility toggle themselves.
        // ...unless an admin took the store down — renewing a plan must not
        // lift a moderation takedown.
        Store::where('id', $store->id)->whereNull('admin_hidden_at')->update(['is_hidden' => false]);

        // "Featured Store Visibility (Top Placement)" is a Premium-plan perk
        // — kept in sync automatically with whatever plan the store is on
        // right now, not a toggle the owner controls themselves. Every
        // subscribe/renew/upgrade/downgrade recomputes it here, so a
        // downgrade away from Premium loses featured placement immediately
        // instead of the column silently staying stale.
        Store::where('id', $store->id)->update([
            'is_featured' => in_array('Featured Store Visibility (Top Placement)', $plan->features ?? [], true),
        ]);

        // Objective 7's "subscription activity" reporting needs a real
        // event log, not just the latest StoreSubscription row (which only
        // ever shows the current state, never the history of how a store
        // got there).
        if (! $previousSubscription) {
            $eventType = 'created';
        } elseif ($previousSubscription->plan_id === $plan->id) {
            $eventType = 'renewed';
        } else {
            $previousPlan = SubscriptionPlan::find($previousSubscription->plan_id);
            $eventType = ($previousPlan && $previousPlan->price_monthly < $plan->price_monthly) ? 'upgraded' : 'downgraded';
        }

        SubscriptionEvent::create([
            'store_id' => $store->id,
            'store_subscription_id' => $newSubscription->id,
            'event_type' => $eventType,
            'plan_id' => $plan->id,
            'previous_plan_id' => $previousSubscription?->plan_id,
            'billing_cycle' => $cycle,
            'triggered_by' => $triggeredBy,
            'occurred_at' => now(),
        ]);

        return $newSubscription;
    }
}
