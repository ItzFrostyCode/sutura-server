<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\HandlesOrderClaims;
use App\Http\Controllers\Controller;
use App\Models\OrderClaim;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The owner / branch manager's decisions on an after-sale claim:
 *  - repair  -> a linked P0 free-fix order goes to production (App\Support\FreeFixOrder); when it is
 *               completed the claim is resolved (JobOrder::booted) and a new claim window opens.
 *  - refund  -> the garment must come back, then the refund is recorded (up to what was paid, cash or
 *               GCash). The money itself is paid outside SUTURA.
 *  - on a refund claim the shop may offer a repair instead, once; the customer chooses.
 */
class OrderClaimDecisionController extends Controller
{
    use HandlesOrderClaims;

    public function accept(Request $request, Store $store, OrderClaim $claim): JsonResponse
    {
        if ($denied = $this->claimDenied($request, $store, $claim)) {
            return $denied;
        }
        if ($claim->status !== 'submitted') {
            return $this->alreadyDecided($claim);
        }
        $order = $claim->jobOrder;

        if ($claim->type === 'refund') {
            $claim->update(['status' => 'awaiting_return', 'decided_by_id' => $request->user()->id, 'decided_at' => now()]);
            $this->logOrderAction($request, $store, $order, 'claim_accepted', ['claim_id' => $claim->id, 'type' => 'refund']);
            $this->notifyCustomer($claim, 'claim_accepted', 'Refund Accepted',
                "{$store->name} accepted your refund request for {$order->order_number}. Please return the garment to the shop to receive your refund.");

            return response()->json(['success' => true, 'message' => 'Refund accepted. Record it once the garment is returned.', 'data' => $this->clientClaim($request, $claim->fresh(), true)]);
        }

        // Repair: due date required, tailor optional.
        [$dueDate, $staffId] = $this->repairDetails($request, $store, $order);
        $repair = $this->startRepair($request, $store, $order, $claim, $dueDate, $staffId);
        $this->logOrderAction($request, $store, $order, 'claim_accepted', ['claim_id' => $claim->id, 'type' => 'repair', 'repair_order_id' => $repair->id]);
        $this->notifyCustomer($claim, 'claim_accepted', 'Repair Accepted',
            "{$store->name} will repair your order {$order->order_number} for free. Track it as {$repair->order_number}.");

        return response()->json([
            'success' => true,
            'message' => "Repair accepted — {$repair->order_number} was added to production.",
            'data' => $this->clientClaim($request, $claim->fresh('repairJobOrder'), true),
        ]);
    }

    /** On a refund claim: offer a free repair instead (once). Nothing is created until the customer chooses. */
    public function offerRepair(Request $request, Store $store, OrderClaim $claim): JsonResponse
    {
        if ($denied = $this->claimDenied($request, $store, $claim)) {
            return $denied;
        }
        if ($claim->status !== 'submitted' || $claim->type !== 'refund') {
            return response()->json(['success' => false, 'message' => 'A repair can only be offered on a refund claim that is waiting for a decision.'], 422);
        }
        if ($claim->repair_offered_at) {
            return response()->json(['success' => false, 'message' => 'A repair was already offered on this claim.'], 422);
        }
        $order = $claim->jobOrder;
        [$dueDate, $staffId] = $this->repairDetails($request, $store, $order);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;

        $claim->update([
            'status' => 'repair_offered',
            'offered_due_date' => $dueDate,
            'offered_staff_id' => $staffId,
            'offer_note' => $note,
            'repair_offered_at' => now(),
        ]);
        $this->logOrderAction($request, $store, $order, 'claim_repair_offered', ['claim_id' => $claim->id, 'due_date' => $dueDate]);
        $this->notifyCustomer($claim, 'claim_repair_offered', 'Free Repair Offered',
            "{$store->name} offered to repair {$order->order_number} for free instead of a refund. Open your order to accept the repair or keep your refund request.");

        return response()->json(['success' => true, 'message' => 'Repair offered. The customer will choose.', 'data' => $this->clientClaim($request, $claim->fresh(), true)]);
    }

    /** The shop records the customer's answer to a repair offer at the counter (walk-ins without an account). */
    public function shopRespond(Request $request, Store $store, OrderClaim $claim): JsonResponse
    {
        if ($denied = $this->claimDenied($request, $store, $claim)) {
            return $denied;
        }

        return $this->answerOffer($request, $claim, true);
    }

    public function decline(Request $request, Store $store, OrderClaim $claim): JsonResponse
    {
        if ($denied = $this->claimDenied($request, $store, $claim)) {
            return $denied;
        }
        if ($claim->status !== 'submitted') {
            return $this->alreadyDecided($claim);
        }
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']], [
            'reason.required' => 'Tell the customer why the claim is declined.',
        ]);

        $claim->update([
            'status' => 'declined',
            'decline_reason' => $validated['reason'],
            'decided_by_id' => $request->user()->id,
            'decided_at' => now(),
            'resolved_at' => now(),
        ]);
        $this->logOrderAction($request, $store, $claim->jobOrder, 'claim_declined', ['claim_id' => $claim->id, 'reason' => $validated['reason']]);
        $this->notifyCustomer($claim, 'claim_declined', 'Claim Declined',
            "{$store->name} declined your claim on {$claim->jobOrder->order_number}: {$validated['reason']}");

        return response()->json(['success' => true, 'message' => 'Claim declined. The customer was notified.', 'data' => $this->clientClaim($request, $claim->fresh(), true)]);
    }

    /** Records the refund once the garment is back. The money itself is paid outside SUTURA. */
    public function refund(Request $request, Store $store, OrderClaim $claim): JsonResponse
    {
        if ($denied = $this->claimDenied($request, $store, $claim)) {
            return $denied;
        }
        if ($claim->status !== 'awaiting_return') {
            return response()->json(['success' => false, 'message' => 'Only an accepted refund can be recorded.'], 422);
        }
        $order = $claim->jobOrder;
        $paid = OrderClaim::paidAmount($order);

        $validated = $request->validate([
            'garment_returned' => ['accepted'],
            'amount' => ['required', 'numeric', 'min:1', 'max:'.max(1, $paid)],
            'method' => ['required', Rule::in(OrderClaim::REFUND_METHODS)],
            'reference' => ['nullable', 'string', 'max:255'],
            'proof_path' => ['nullable', 'string', 'max:2048'],
        ], [
            'garment_returned.accepted' => 'The garment must be returned before refunding.',
            'amount.max' => 'The refund can\'t be more than what the customer paid (₱'.number_format($paid, 2).').',
        ]);

        $claim->update([
            'status' => 'refunded',
            'garment_returned_at' => now(),
            'refund_amount' => round((float) $validated['amount'], 2),
            'refund_method' => $validated['method'],
            'refund_reference' => $validated['reference'] ?? null,
            'refund_proof_path' => $validated['proof_path'] ?? null,
            'refunded_at' => now(),
            'resolved_at' => now(),
        ]);
        $this->logOrderAction($request, $store, $order, 'claim_refunded', [
            'claim_id' => $claim->id, 'amount' => (float) $validated['amount'], 'method' => $validated['method'], 'reference' => $validated['reference'] ?? null,
        ]);
        $this->notifyCustomer($claim, 'claim_refunded', 'Refund Sent',
            "{$store->name} refunded ₱".number_format((float) $validated['amount'], 2)." for {$order->order_number} via ".($validated['method'] === 'gcash' ? 'GCash' : 'cash').'.');

        return response()->json(['success' => true, 'message' => 'Refund recorded.', 'data' => $this->clientClaim($request, $claim->fresh(), true)]);
    }

    private function alreadyDecided(OrderClaim $claim): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $claim->status === 'repair_offered' ? 'Waiting for the customer to answer the repair offer.' : 'This claim has already been decided.'], 422);
    }
}
