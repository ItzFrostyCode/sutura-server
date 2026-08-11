<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SubscriptionPlan;
use App\Models\ShopSubscription;

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
            'data' => $plans
        ]);
    }

    /**
     * Get the current active subscription for a shop
     */
    public function current(Request $request, $shopId)
    {
        // Allow if the user owns the shop or is an admin
        $user = $request->user();
        if (!$user->shops()->where('id', $shopId)->exists() && !$user->hasRole('admin')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized access to shop subscriptions.'], 403);
        }

        $subscription = ShopSubscription::with('plan')
            ->where('shop_id', $shopId)
            ->latest()
            ->first();

        return response()->json([
            'success' => true,
            'data' => $subscription
        ]);
    }

    /**
     * Subscribe or upgrade to a plan
     */
    public function subscribe(Request $request, $shopId)
    {
        $request->validate([
            'plan_id' => 'required|exists:subscription_plans,id',
            'billing_cycle' => 'required|in:monthly,yearly'
        ]);

        $user = $request->user();
        if (!$user->shops()->where('id', $shopId)->exists() && !$user->hasRole('admin')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $plan = SubscriptionPlan::findOrFail($request->plan_id);

        // A shop can freely switch plans up OR down, but nothing ever
        // checked whether the shop's *current* usage still fits the plan
        // being switched TO — a Premium shop with 3 branches and 8 staff
        // could downgrade straight to Basic (1 branch, few staff) and be
        // left silently over-limit on both, with no warning at downgrade
        // time. The create-time gates (ShopBranchController@store,
        // StaffController@store) only ever stop *adding* more; they don't
        // protect against this. Blocking here, not just warning, since
        // there's no legitimate reason to let a downgrade succeed into an
        // already-invalid state — unlike the duplicate-payment-reference
        // warnings elsewhere in this app, there's no plausible "actually
        // fine" case for this one.
        $shop = \App\Models\Shop::findOrFail($shopId);

        $currentStaffCount = $shop->staff()->count();
        if ($plan->max_staff !== -1 && $currentStaffCount > $plan->max_staff) {
            return response()->json([
                'success' => false,
                'message' => "This plan allows up to {$plan->max_staff} staff member" . ($plan->max_staff === 1 ? '' : 's') . ", but you currently have {$currentStaffCount}. Remove staff first, or choose a plan that fits your current team size.",
            ], 422);
        }

        $currentBranchCount = $shop->branches()->count();
        if ($plan->slug !== 'premium' && $currentBranchCount > 1) {
            return response()->json([
                'success' => false,
                'message' => "This plan only supports a single branch, but you currently have {$currentBranchCount}. Remove the extra branches first, or stay on a plan that supports multiple branches.",
            ], 422);
        }

        // Simulated billing: Instantly create or update subscription
        // In a real app, this is where PayMongo/Stripe checkout session would be created

        // Cancel previous active subscription if it exists
        ShopSubscription::where('shop_id', $shopId)
            ->whereIn('status', ['active', 'trial'])
            ->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'ends_at' => now()
            ]);

        $days = $request->billing_cycle === 'yearly' ? 365 : 30;

        $newSubscription = ShopSubscription::create([
            'shop_id' => $shopId,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addDays($days),
        ]);

        // Mirrors app:expire-subscriptions' auto-hide-on-expiry — a shop the
        // system hid for a lapsed subscription should come back the moment
        // the owner renews, not stay hidden until they separately notice and
        // flip the visibility toggle themselves.
        \App\Models\Shop::where('id', $shopId)->update(['is_hidden' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Successfully subscribed to ' . $plan->name . '.',
            'data' => $newSubscription->load('plan')
        ]);
    }
}
