<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\SubscriptionUpgradeRequest;
use App\Notifications\StoreActivityNotification;
use App\Support\AdminAudit;
use App\Support\SubscriptionSwitcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Admin review queue for paid plan upgrade / renewal payments. */
class SubscriptionUpgradeRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->input('status', 'pending');

        $page = SubscriptionUpgradeRequest::query()
            ->with(['store:id,name,slug', 'plan:id,name', 'requester:id,name,email,contact_email', 'reviewer:id,name'])
            ->where('status', $status)
            ->orderBy('created_at', $status === 'pending' ? 'asc' : 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'counts' => SubscriptionUpgradeRequest::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function receipt(SubscriptionUpgradeRequest $upgradeRequest): Response
    {
        $path = $upgradeRequest->payment_receipt_path;

        if (! $path || ! Storage::disk('local')->exists($path)) {
            return response()->json(['success' => false, 'message' => 'Receipt not found.'], 404);
        }

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store']);
    }

    public function approve(Request $request, SubscriptionUpgradeRequest $upgradeRequest): JsonResponse
    {
        if ($upgradeRequest->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'This payment has already been reviewed.'], 422);
        }

        $store = $upgradeRequest->store;
        $plan = $upgradeRequest->plan;

        // Usage may have changed since the request was made.
        if ($error = SubscriptionSwitcher::misfit($store, $plan)) {
            return response()->json(['success' => false, 'message' => $error], 422);
        }

        SubscriptionSwitcher::apply($store, $plan, $upgradeRequest->billing_cycle, $request->user()->id);

        $upgradeRequest->update([
            'status' => 'approved',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ]);

        AdminAudit::record($request, 'plan_upgrade_approved', Store::class, $store->id, $store->name.' → '.$plan->name, $store->id);

        $upgradeRequest->requester?->notify(new StoreActivityNotification(
            'plan_upgrade_approved',
            'Plan Upgrade Approved',
            "Your payment was confirmed. {$store->name} is now on the {$plan->name} plan.",
            '/dashboard/billing'
        ));

        return response()->json(['success' => true, 'message' => "{$store->name} is now on {$plan->name}."]);
    }

    public function reject(Request $request, SubscriptionUpgradeRequest $upgradeRequest): JsonResponse
    {
        if ($upgradeRequest->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'This payment has already been reviewed.'], 422);
        }

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $upgradeRequest->update([
            'status' => 'rejected',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'rejection_reason' => $validated['reason'],
        ]);

        $store = $upgradeRequest->store;
        AdminAudit::record($request, 'plan_upgrade_rejected', Store::class, $store->id, $store->name, $store->id, $validated['reason']);

        $upgradeRequest->requester?->notify(new StoreActivityNotification(
            'plan_upgrade_rejected',
            'Plan Payment Not Accepted',
            "We couldn't confirm your payment for the {$upgradeRequest->plan->name} plan: {$validated['reason']} You can send a new receipt from Billing.",
            '/dashboard/billing'
        ));

        return response()->json(['success' => true, 'message' => 'Payment rejected. The owner was notified.']);
    }
}
