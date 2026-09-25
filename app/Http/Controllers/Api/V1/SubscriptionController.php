<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreSubscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    /**
     * Get all available subscription plans
     */
    public function index()
    {
        $plans = SubscriptionPlan::where('is_active', true)->get();

        return response()->json([
            'success' => true,
            'data' => $plans,
        ]);
    }

    /**
     * Get the current active subscription for a store
     */
    public function current(Request $request, $storeId)
    {
        $id = $storeId instanceof Store ? $storeId->id : $storeId;
        // Allow if the user owns the store, is a staff member of the store, or is an admin
        $user = $request->user();
        $isOwner = $user->stores()->where('id', $id)->exists();
        $isStaff = $user->staffProfile && (int) $user->staffProfile->store_id === (int) $id;
        $isAdmin = $user->hasRole('admin');

        if (! $isOwner && ! $isStaff && ! $isAdmin) {
            return response()->json(['success' => false, 'message' => 'Unauthorized access to store subscriptions.'], 403);
        }

        $subscription = StoreSubscription::with('plan')
            ->where('store_id', $id)
            ->latest()
            ->first();

        return response()->json([
            'success' => true,
            'data' => $subscription,
        ]);
    }

    /**
     * Subscribe or upgrade to a plan
     */
    public function subscribe(Request $request, $storeId)
    {
        $request->validate([
            'plan_id' => 'required|exists:subscription_plans,id',
            'billing_cycle' => 'required|in:monthly,yearly',
        ]);

        $user = $request->user();
        if (! $user->stores()->where('id', $storeId)->exists() && ! $user->hasRole('admin')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $plan = SubscriptionPlan::findOrFail($request->plan_id);

        // A store can freely switch plans up OR down, but nothing ever
        // checked whether the store's *current* usage still fits the plan
        // being switched TO — a Premium store with 3 branches and 8 staff
        // could downgrade straight to Basic (1 branch, few staff) and be
        // left silently over-limit on both, with no warning at downgrade
        // time. The create-time gates (StoreBranchController@store,
        // StaffController@store) only ever stop *adding* more; they don't
        // protect against this. Blocking here, not just warning, since
        // there's no legitimate reason to let a downgrade succeed into an
        // already-invalid state — unlike the duplicate-payment-reference
        // warnings elsewhere in this app, there's no plausible "actually
        // fine" case for this one.
        $store = Store::findOrFail($storeId);

        $currentStaffCount = $store->staff()->count();
        if ($plan->max_staff !== -1 && $currentStaffCount > $plan->max_staff) {
            return response()->json([
                'success' => false,
                'message' => "This plan allows up to {$plan->max_staff} staff member".($plan->max_staff === 1 ? '' : 's').", but you currently have {$currentStaffCount}. Remove staff first, or choose a plan that fits your current team size.",
            ], 422);
        }

        $currentBranchCount = $store->branches()->count();
        if ($plan->slug !== 'premium' && $currentBranchCount > 1) {
            return response()->json([
                'success' => false,
                'message' => "This plan only supports a single branch, but you currently have {$currentBranchCount}. Remove the extra branches first, or stay on a plan that supports multiple branches.",
            ], 422);
        }

        // Simulated billing: Instantly create or update subscription
        // In a real app, this is where PayMongo/Stripe checkout session would be created

        // Read the previous subscription BEFORE cancelling it — needed to
        // classify the event below (created/renewed/upgraded/downgraded),
        // and the update() call two lines down overwrites its status to
        // 'cancelled' with no way to read the "was this the same plan or a
        // different one" fact afterward.
        $previousSubscription = StoreSubscription::where('store_id', $storeId)
            ->whereIn('status', ['active', 'trial'])
            ->latest()
            ->first();

        // Cancel previous active subscription if it exists
        StoreSubscription::where('store_id', $storeId)
            ->whereIn('status', ['active', 'trial'])
            ->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'ends_at' => now(),
            ]);

        $days = $request->billing_cycle === 'yearly' ? 365 : 30;

        $newSubscription = StoreSubscription::create([
            'store_id' => $storeId,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addDays($days),
        ]);

        // Mirrors app:expire-subscriptions' auto-hide-on-expiry — a store the
        // system hid for a lapsed subscription should come back the moment
        // the owner renews, not stay hidden until they separately notice and
        // flip the visibility toggle themselves.
        Store::where('id', $storeId)->update(['is_hidden' => false]);

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
            'store_id' => $storeId,
            'store_subscription_id' => $newSubscription->id,
            'event_type' => $eventType,
            'plan_id' => $plan->id,
            'previous_plan_id' => $previousSubscription?->plan_id,
            'billing_cycle' => $request->billing_cycle,
            'triggered_by' => $user->id,
            'occurred_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Successfully subscribed to '.$plan->name.'.',
            'data' => $newSubscription->load('plan'),
        ]);
    }
}
