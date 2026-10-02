<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionUpgradeRequest;
use App\Support\SubscriptionSwitcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shop owner's side of a paid plan change: send the fee by GCash to the
 * platform, then submit the receipt. The store stays on its current plan
 * until an admin approves (Admin\SubscriptionUpgradeRequestController).
 */
class SubscriptionUpgradeRequestController extends Controller
{
    private static function privateDisk(): string
    {
        return config('filesystems.private_disk', 'local');
    }

    private function ownsStore(Request $request, Store $store): bool
    {
        return $request->user()->stores()->where('id', $store->id)->exists();
    }

    public function index(Request $request, Store $store): JsonResponse
    {
        if (! $this->ownsStore($request, $store)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => SubscriptionUpgradeRequest::with('plan:id,name')
                ->where('store_id', $store->id)
                ->latest()
                ->limit(10)
                ->get(),
        ]);
    }

    public function store(Request $request, Store $store): JsonResponse
    {
        if (! $this->ownsStore($request, $store)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'payment_receipt' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $plan = SubscriptionPlan::where('is_active', true)->findOrFail($validated['plan_id']);

        if (SubscriptionUpgradeRequest::where('store_id', $store->id)->where('status', 'pending')->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a payment waiting for review. Wait for the SUTURA team to respond before sending another.',
            ], 422);
        }

        if (! SubscriptionSwitcher::needsPayment($store, $plan, $validated['billing_cycle'])) {
            return response()->json([
                'success' => false,
                'message' => 'This change does not need a payment — switch to it directly from Billing.',
            ], 422);
        }

        if ($error = SubscriptionSwitcher::misfit($store, $plan)) {
            return response()->json(['success' => false, 'message' => $error], 422);
        }

        $price = $validated['billing_cycle'] === 'yearly' ? $plan->price_yearly : $plan->price_monthly;

        $upgrade = SubscriptionUpgradeRequest::create([
            'store_id' => $store->id,
            'requested_by' => $request->user()->id,
            'plan_id' => $plan->id,
            'billing_cycle' => $validated['billing_cycle'],
            'quoted_price' => $price,
            'payment_method' => 'gcash',
            'payment_reference' => $validated['payment_reference'] ?? null,
            'payment_receipt_path' => $request->file('payment_receipt')->store('subscription-upgrades/receipts', self::privateDisk()),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment sent for review. Your plan changes once the SUTURA team confirms it.',
            'data' => $upgrade->refresh()->load('plan:id,name'),
        ], 201);
    }

    /** The owner viewing back the receipt they attached. */
    public function receipt(Request $request, Store $store, SubscriptionUpgradeRequest $upgradeRequest): Response
    {
        if (! $this->ownsStore($request, $store) || $upgradeRequest->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        if (! Storage::disk(self::privateDisk())->exists($upgradeRequest->payment_receipt_path)) {
            return response()->json(['success' => false, 'message' => 'Receipt not found.'], 404);
        }

        return Storage::disk(self::privateDisk())->response($upgradeRequest->payment_receipt_path, null, ['Cache-Control' => 'private, no-store']);
    }
}
