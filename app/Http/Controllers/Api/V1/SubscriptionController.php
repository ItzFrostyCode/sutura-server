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

        // scheduledPlan: a downgrade waiting for this period to end (Billing shows it).
        $subscription = StoreSubscription::with(['plan', 'scheduledPlan:id,name,slug,price_monthly'])
            ->where('store_id', $id)
            ->latest()
            ->first();

        // Staff seats, store-wide (Home → Quick Actions disables "Add Staff" at
        // the cap). Same rule StaffController::store enforces: the active/trial
        // plan's max_staff, else the cheapest plan's; -1 = unlimited.
        $store = Store::find($id);
        $active = $store?->subscription()->whereIn('status', ['active', 'trial'])->first();
        $maxStaff = $active?->plan?->max_staff ?? SubscriptionPlan::where('slug', 'basic')->value('max_staff') ?? 1;

        return response()->json([
            'success' => true,
            'data' => $subscription,
            'staff_usage' => ['used' => $store ? $store->staff()->count() : 0, 'max' => (int) $maxStaff],
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

        // Stepping down to a free plan from a running paid one keeps what was
        // paid for: the switch waits for the current period to end.
        $current = SubscriptionSwitcher::current($store);
        if (! $user->hasRole('admin') && $current && $current->plan_id !== $plan->id
            && (float) $current->plan->price_monthly > 0 && $current->ends_at?->isFuture()) {
            if ($current->scheduled_payment_id) {
                return response()->json(['success' => false, 'message' => 'You already paid for a plan change that starts when your current plan ends. Contact SUTURA support to change it.'], 422);
            }
            SubscriptionSwitcher::schedule($current, $plan, $request->billing_cycle);
            $on = $current->ends_at->timezone('Asia/Manila')->format('M j, Y');

            return response()->json([
                'success' => true,
                'message' => "You stay on {$current->plan->name} until {$on}, then switch to {$plan->name}. You can cancel this from Billing before then.",
                'data' => $current->fresh()->load('plan', 'scheduledPlan'),
            ]);
        }

        $newSubscription = SubscriptionSwitcher::apply($store, $plan, $request->billing_cycle, $user->id);

        return response()->json([
            'success' => true,
            'message' => 'Successfully subscribed to '.$plan->name.'.',
            'data' => $newSubscription->load('plan'),
        ]);
    }

    /**
     * Cancel an active subscription.
     * Retains access until ends_at, or ends immediately if no end date.
     */
    public function cancel(Request $request, $storeId)
    {
        $id = $storeId instanceof Store ? $storeId->id : $storeId;
        $user = $request->user();
        if (! $user->stores()->where('id', $id)->exists() && ! $user->hasRole('admin')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $subscription = StoreSubscription::where('store_id', $id)
            ->whereIn('status', ['active', 'trial'])
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->latest()
            ->first();

        if (! $subscription) {
            return response()->json(['success' => false, 'message' => 'No active subscription found to cancel.'], 422);
        }

        if ($subscription->cancelled_at !== null) {
            return response()->json(['success' => false, 'message' => 'Subscription is already cancelled.'], 422);
        }

        $subscription->update([
            'cancelled_at' => now(),
        ]);

        SubscriptionEvent::create([
            'store_id' => $id,
            'store_subscription_id' => $subscription->id,
            'event_type' => 'cancelled',
            'plan_id' => $subscription->plan_id,
            'billing_cycle' => $subscription->billing_cycle ?? 'monthly',
            'triggered_by' => $user->id,
            'occurred_at' => now(),
        ]);

        $until = $subscription->ends_at
            ? $subscription->ends_at->timezone('Asia/Manila')->format('M j, Y')
            : 'the end of your current period';

        return response()->json([
            'success' => true,
            'message' => "Your subscription has been cancelled. You will retain access until {$until}.",
            'data' => $subscription->fresh()->load('plan'),
        ]);
    }

    /**
     * Resubscribe or uncancel a cancelled subscription before it ends.
     */
    public function resubscribe(Request $request, $storeId)
    {
        $id = $storeId instanceof Store ? $storeId->id : $storeId;
        $user = $request->user();
        if (! $user->stores()->where('id', $id)->exists() && ! $user->hasRole('admin')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $store = Store::findOrFail($id);

        // Check if there is an active subscription with cancelled_at set
        $subscription = StoreSubscription::where('store_id', $id)
            ->whereIn('status', ['active', 'trial'])
            ->whereNotNull('cancelled_at')
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->latest()
            ->first();

        if ($subscription) {
            // Simply remove cancellation
            $subscription->update(['cancelled_at' => null]);

            SubscriptionEvent::create([
                'store_id' => $id,
                'store_subscription_id' => $subscription->id,
                'event_type' => 'resubscribed',
                'plan_id' => $subscription->plan_id,
                'billing_cycle' => $subscription->billing_cycle ?? 'monthly',
                'triggered_by' => $user->id,
                'occurred_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Subscription reactivation successful! Auto-renewal is restored.',
                'data' => $subscription->fresh()->load('plan'),
            ]);
        }

        // If completely expired/inactive, re-apply the basic plan or prompt renewal
        $defaultPlan = SubscriptionPlan::where('slug', 'basic')->first()
            ?? SubscriptionPlan::where('price_monthly', 0)->first();

        if (! $defaultPlan) {
            return response()->json(['success' => false, 'message' => 'Please select a plan to subscribe.'], 422);
        }

        $newSub = SubscriptionSwitcher::apply($store, $defaultPlan, 'monthly', $user->id);

        return response()->json([
            'success' => true,
            'message' => "Successfully resubscribed to {$defaultPlan->name}.",
            'data' => $newSub->load('plan'),
        ]);
    }
}
