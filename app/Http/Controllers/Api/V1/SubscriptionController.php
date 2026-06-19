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

        return response()->json([
            'success' => true,
            'message' => 'Successfully subscribed to ' . $plan->name . '.',
            'data' => $newSubscription->load('plan')
        ]);
    }
}
