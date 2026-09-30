<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreSubscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;
use App\Support\SubscriptionSwitcher;
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

        $store = Store::findOrFail($storeId);

        if ($error = SubscriptionSwitcher::misfit($store, $plan)) {
            return response()->json(['success' => false, 'message' => $error], 422);
        }

        // Moving up to (or renewing) a paid plan takes a real payment: the owner
        // sends GCash and submits the receipt as an upgrade request, and an admin
        // approves it (see SubscriptionUpgradeRequestController). Only an admin,
        // or a switch to a free / cheaper plan, applies right away.
        if (! $user->hasRole('admin') && SubscriptionSwitcher::needsPayment($store, $plan, $request->billing_cycle)) {
            return response()->json([
                'success' => false,
                'code' => 'payment_required',
                'message' => 'This plan needs a payment. Send it by GCash and upload your receipt to request the upgrade.',
            ], 402);
        }

        $newSubscription = SubscriptionSwitcher::apply($store, $plan, $request->billing_cycle, $user->id);

        return response()->json([
            'success' => true,
            'message' => 'Successfully subscribed to '.$plan->name.'.',
            'data' => $newSubscription->load('plan'),
        ]);
    }
}
