<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\JobOrder;
use App\Models\OrderClaim;
use App\Models\Store;
use App\Models\User;
use App\Notifications\StoreActivityNotification;
use App\Support\FreeFixOrder;
use App\Support\SafeNotify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * What the claim controllers share: the payload, money hiding for plain staff, the claim-denied guard,
 * starting the linked repair order, answering a repair offer, and the notifications.
 */
trait HandlesOrderClaims
{
    use ChecksOrderAccess;

    /** The claim must belong to the {store} in the URL, and its order to a branch the user may touch. */
    protected function claimDenied(Request $request, Store $store, OrderClaim $claim): ?JsonResponse
    {
        if ($claim->store_id !== $store->id || ! $claim->jobOrder) {
            return response()->json(['success' => false, 'message' => 'Claim not found.'], 404);
        }

        return $this->orderAccessDenied($request, $store, $claim->jobOrder);
    }

    /** One claim as the caller may see it: the refund amount is money, so plain staff get null. */
    protected function clientClaim(Request $request, OrderClaim $claim, bool $forShop): array
    {
        $data = $claim->toClientArray($forShop);
        if ($forShop && ! $this->isMoneyRole($request)) {
            $data['refund_amount'] = null;
        }

        return $data;
    }

    protected function claimPayload(Request $request, JobOrder $order, bool $forShop): array
    {
        $claims = OrderClaim::where('job_order_id', $order->id)->with(['repairJobOrder', 'filedBy:id,name', 'decidedBy:id,name'])->latest()->get();
        $hideMoney = $forShop && ! $this->isMoneyRole($request);

        return array_merge(OrderClaim::eligibility($order), [
            'window_days' => OrderClaim::WINDOW_DAYS,
            'paid_amount' => $hideMoney ? null : OrderClaim::paidAmount($order),
            'claims' => $claims->map(fn (OrderClaim $c) => $this->clientClaim($request, $c, $forShop))->values(),
        ]);
    }

    /**
     * Due date (required, not in the past) and optional staff from this shop and the order's branch.
     *
     * @return array{0: string, 1: ?int}
     */
    protected function repairDetails(Request $request, Store $store, JobOrder $order): array
    {
        $validated = $request->validate([
            'due_date' => ['required', 'date', 'after_or_equal:today'],
            'assigned_staff_id' => ['nullable', 'integer'],
        ], [
            'due_date.required' => 'Set a due date for the repair.',
            'due_date.after_or_equal' => 'The due date can\'t be in the past.',
        ]);
        $staffId = $validated['assigned_staff_id'] ?? null;
        if ($staffId) {
            $profile = $store->staff()->where('user_id', $staffId)->where('is_active', true)->first();
            if (! $profile) {
                abort(422, 'Pick a staff member from this shop.');
            }
            if ($profile->store_branch_id && $order->store_branch_id && (int) $profile->store_branch_id !== (int) $order->store_branch_id) {
                abort(422, 'That staff member works at another branch.');
            }
        }

        return [\Illuminate\Support\Carbon::parse($validated['due_date'])->toDateString(), $staffId ? (int) $staffId : null];
    }

    /** Creates the linked P0 free-fix order (Confirmed, ready for production) and moves the claim to "repairing". */
    protected function startRepair(Request $request, Store $store, JobOrder $order, OrderClaim $claim, string $dueDate, ?int $staffId): JobOrder
    {
        $actor = $request->user();
        $label = OrderClaim::REASON_LABELS[$claim->reason] ?? $claim->reason;

        return DB::transaction(function () use ($store, $order, $claim, $dueDate, $staffId, $actor, $label) {
            $repair = FreeFixOrder::create(
                $store, $order, $actor,
                "Warranty repair for {$order->order_number} — {$label}: {$claim->description}",
                1,
                ['warranty_claim_id' => $claim->id, 'warranty_for_order' => $order->order_number],
                ['due_date' => $dueDate, 'reference_images' => $claim->photos]
            );
            // The tailor the shop picked is the order's planned tailor (what "Start production" checks for).
            if ($staffId) {
                $repair->assignees()->create(['user_id' => $staffId, 'role' => 'lead', 'assigned_by' => $actor->id]);
            }
            $claim->update([
                'status' => 'repairing',
                'decided_by_id' => $claim->decided_by_id ?? $actor->id,
                'decided_at' => $claim->decided_at ?? now(),
                'repair_job_order_id' => $repair->id,
            ]);

            return $repair;
        });
    }

    /** The customer (or the shop at the counter) answers a repair offer: take the repair, or keep the refund request. */
    protected function answerOffer(Request $request, OrderClaim $claim, bool $byShop): JsonResponse
    {
        if ($claim->status !== 'repair_offered') {
            return response()->json(['success' => false, 'message' => 'There is no repair offer waiting for an answer.'], 422);
        }
        $choice = $request->validate(['choice' => ['required', Rule::in(['repair', 'refund'])]])['choice'];
        $order = $claim->jobOrder;
        $store = $order->store;
        $audit = ['claim_id' => $claim->id, 'choice' => $choice, 'by_shop' => $byShop];

        if ($choice === 'refund') {
            // Back to the shop: it now accepts or declines the refund (no second offer).
            $claim->update(['status' => 'submitted', 'offer_declined_at' => now()]);
            $this->logOrderAction($request, $store, $order, 'claim_offer_answered', $audit);
            if (! $byShop) {
                $this->notifyShop($request, $order, $claim, false, 'claim_offer_declined', 'Repair Offer Declined',
                    ($order->customer?->name ?? 'The customer')." wants a refund instead of the repair on {$order->order_number}.");
            }

            return response()->json(['success' => true, 'message' => $byShop ? 'Saved — the customer keeps the refund request.' : 'You kept your refund request. The shop will decide on it.', 'data' => $this->clientClaim($request, $claim->fresh(), $byShop)]);
        }

        // A due date already in the past by now moves to today.
        $dueDate = $claim->offered_due_date && $claim->offered_due_date->gte(today()) ? $claim->offered_due_date->toDateString() : today()->toDateString();
        $claim->update(['type' => 'repair']);
        $repair = $this->startRepair($request, $store, $order, $claim, $dueDate, $claim->offered_staff_id);
        $this->logOrderAction($request, $store, $order, 'claim_offer_answered', $audit + ['repair_order_id' => $repair->id]);

        if ($byShop) {
            $this->notifyCustomer($claim, 'claim_accepted', 'Repair Accepted',
                "Your free repair for {$order->order_number} has started. Track it as {$repair->order_number}.");
        } else {
            $this->notifyShop($request, $order, $claim, false, 'claim_offer_accepted', 'Repair Offer Accepted',
                ($order->customer?->name ?? 'The customer')." accepted the free repair on {$order->order_number} — {$repair->order_number} was added to production.");
        }

        return response()->json([
            'success' => true,
            'message' => $byShop ? "Repair started — {$repair->order_number} was added to production." : "Repair accepted. Track it as {$repair->order_number}.",
            'data' => $this->clientClaim($request, $claim->fresh('repairJobOrder'), $byShop),
        ]);
    }

    /** Owner + that branch's managers (never the person who acted). */
    protected function notifyShop(Request $request, JobOrder $order, OrderClaim $claim, bool $byShop, string $type = 'claim_filed', ?string $title = null, ?string $message = null): void
    {
        $recipients = collect([$order->store?->owner]);
        if ($order->store_branch_id) {
            $recipients = $recipients->merge(User::whereHas('staffProfile', fn ($q) => $q->where('store_id', $order->store_id)
                ->where('store_branch_id', $order->store_branch_id)->where('is_branch_manager', true))->get());
        }
        $what = $claim->type === 'refund' ? 'a refund' : 'a repair';
        $who = $byShop ? 'Filed at the counter' : ($order->customer?->name ?? 'A customer');

        foreach ($recipients->filter()->unique('id')->where('id', '!=', $request->user()->id) as $user) {
            SafeNotify::send($user, new StoreActivityNotification(
                $type,
                $title ?? 'Problem Reported',
                $message ?? "{$who} asked for {$what} on {$order->order_number}: ".(OrderClaim::REASON_LABELS[$claim->reason] ?? $claim->reason).'.',
                '/dashboard/jobs/'.$order->id,
                ['job_order_id' => $order->id, 'order_number' => $order->order_number, 'claim_id' => $claim->id, 'store_branch_id' => $order->store_branch_id]
            ));
        }
    }

    protected function notifyCustomer(OrderClaim $claim, string $type, string $title, string $message): void
    {
        $order = $claim->jobOrder;
        SafeNotify::send($order?->customer, new StoreActivityNotification(
            $type, $title, $message, '/account/orders/'.$order->id,
            ['job_order_id' => $order->id, 'order_number' => $order->order_number, 'claim_id' => $claim->id]
        ));
    }
}
