<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\HandlesOrderClaims;
use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\OrderClaim;
use App\Models\Store;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * After-sale claims ("Report a problem"): the customer's side, plus filing/reading for the shop floor.
 * Within OrderClaim::WINDOW_DAYS of an order being completed the customer (or the shop, for a walk-in at the
 * counter) reports a problem with 1-5 photos and asks for a free repair or a refund. The owner / branch manager
 * decides in OrderClaimDecisionController. SUTURA never moves money.
 */
class OrderClaimController extends Controller
{
    use HandlesOrderClaims;

    // ── Customer ────────────────────────────────────────────────────────────

    public function customerIndex(Request $request, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->customer_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->claimPayload($request, $jobOrder, false)]);
    }

    public function customerStore(Request $request, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->customer_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }

        return $this->file($request, $jobOrder, false);
    }

    /** The customer answers a repair offer. */
    public function customerRespond(Request $request, JobOrder $jobOrder, OrderClaim $claim): JsonResponse
    {
        if ($jobOrder->customer_id !== $request->user()->id || $claim->job_order_id !== $jobOrder->id) {
            return response()->json(['success' => false, 'message' => 'Claim not found.'], 404);
        }

        return $this->answerOffer($request, $claim, false);
    }

    /** A declined customer asks SUTURA support to review the shop's decision (once per claim). */
    public function escalate(Request $request, JobOrder $jobOrder, OrderClaim $claim): JsonResponse
    {
        if ($jobOrder->customer_id !== $request->user()->id || $claim->job_order_id !== $jobOrder->id) {
            return response()->json(['success' => false, 'message' => 'Claim not found.'], 404);
        }
        if ($claim->status !== 'declined') {
            return response()->json(['success' => false, 'message' => 'Only a declined claim can be sent to SUTURA support.'], 422);
        }
        if ($claim->escalated_ticket_id) {
            return response()->json(['success' => false, 'message' => 'You already asked SUTURA support to review this claim.'], 422);
        }
        $validated = $request->validate(['message' => ['required', 'string', 'min:10', 'max:2000']], [
            'message.min' => 'Tell SUTURA support why you disagree (at least 10 characters).',
        ]);

        $what = $claim->type === 'refund' ? 'refund' : 'repair';
        $ticket = SupportTicket::create([
            'store_id' => $jobOrder->store_id,
            'user_id' => $request->user()->id,
            'subject' => "Declined {$what} claim: {$jobOrder->order_number}",
            'message' => "The shop declined my {$what} claim on {$jobOrder->order_number} (".(OrderClaim::REASON_LABELS[$claim->reason] ?? $claim->reason).").\n\n"
                ."My report: {$claim->description}\n\nShop's reason: {$claim->decline_reason}\n\nWhy I disagree: {$validated['message']}",
            'attachments' => $claim->photos,
            'type' => 'problem',
            'priority' => 'medium',
            'status' => 'open',
        ]);
        $claim->update(['escalated_ticket_id' => $ticket->id]);
        $this->logOrderAction($request, $jobOrder->store, $jobOrder, 'claim_escalated', ['claim_id' => $claim->id, 'ticket_id' => $ticket->id]);

        return response()->json([
            'success' => true,
            'message' => 'Sent to SUTURA support. You can follow it under Support Tickets.',
            'data' => $claim->fresh()->toClientArray(false),
        ]);
    }

    // ── Shop floor (any role): read, and file for a walk-in at the counter ───

    public function shopIndex(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder)) {
            return $denied;
        }

        return response()->json(['success' => true, 'data' => $this->claimPayload($request, $jobOrder, true)]);
    }

    public function shopStore(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder)) {
            return $denied;
        }

        return $this->file($request, $jobOrder, true);
    }

    private function file(Request $request, JobOrder $order, bool $byShop): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(OrderClaim::TYPES)],
            'reason' => ['required', Rule::in(OrderClaim::REASONS)],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'photos' => ['required', 'array', 'min:1', 'max:5'],
            'photos.*' => ['required', 'string', 'max:2048'],
        ], [
            'photos.required' => 'Add at least one photo of the problem.',
            'photos.min' => 'Add at least one photo of the problem.',
            'photos.max' => 'Up to 5 photos.',
            'description.min' => 'Describe the problem in a few words (at least 10 characters).',
        ]);

        $claim = DB::transaction(function () use ($order, $validated, $request) {
            // Row lock so two quick submits can't both pass the one-claim rule.
            $locked = JobOrder::where('id', $order->id)->lockForUpdate()->firstOrFail();
            $check = OrderClaim::eligibility($locked);
            if (! $check['can_file']) {
                abort(422, $check['reason']);
            }

            return OrderClaim::create([
                'store_id' => $locked->store_id,
                'job_order_id' => $locked->id,
                'customer_id' => $locked->customer_id,
                'filed_by_id' => $request->user()->id,
                'type' => $validated['type'],
                'reason' => $validated['reason'],
                'description' => $validated['description'],
                'photos' => array_values($validated['photos']),
                'status' => 'submitted',
            ]);
        });

        $this->logOrderAction($request, $order->store, $order, 'claim_filed', [
            'claim_id' => $claim->id, 'type' => $claim->type, 'reason' => $claim->reason, 'by_shop' => $byShop,
        ]);
        $this->notifyShop($request, $order, $claim, $byShop);

        return response()->json([
            'success' => true,
            'message' => $byShop ? 'Claim filed.' : 'Problem reported. The shop will review it and get back to you.',
            'data' => $this->clientClaim($request, $claim, $byShop),
        ], 201);
    }
}
