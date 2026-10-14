<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ChecksOrderAccess;
use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\OrderRefund;
use App\Models\Store;
use App\Support\OrderRefundSuggestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cancellation refunds: a suggestion with its basis, and the owner's recorded decision.
 * Owner / branch manager only. This only records -- SUTURA never moves money.
 */
class OrderRefundController extends Controller
{
    use ChecksOrderAccess;

    public function suggestion(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder)) {
            return $denied;
        }
        $costs = (float) $request->query('non_refundable', 0);

        return response()->json(['success' => true, 'data' => OrderRefundSuggestion::forJob($jobOrder, max(0.0, $costs))]);
    }

    public function record(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder)) {
            return $denied;
        }
        if ($jobOrder->status !== 'cancelled') {
            return response()->json(['success' => false, 'message' => 'A refund can only be recorded for a cancelled order.'], 422);
        }
        $v = $request->validate([
            'approved_amount' => 'required|numeric|min:0|max:9999999',
            'non_refundable' => 'nullable|numeric|min:0|max:9999999',
            'non_refundable_note' => 'nullable|string|max:255',
            'adjustment_reason' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:1000',
            'mark_refunded' => 'sometimes|boolean',
        ]);

        $s = OrderRefundSuggestion::forJob($jobOrder, (float) ($v['non_refundable'] ?? 0));
        $approved = round((float) $v['approved_amount'], 2);

        if ($approved > $s['paid_amount'] + 0.005) {
            return response()->json(['success' => false, 'message' => 'The refund cannot be more than the ₱'.number_format($s['paid_amount'], 2).' paid.'], 422);
        }
        if (abs($approved - $s['suggested_amount']) > 0.005 && trim((string) ($v['adjustment_reason'] ?? '')) === '') {
            return response()->json(['success' => false, 'message' => 'Tell us why the amount differs from the suggested ₱'.number_format($s['suggested_amount'], 2).'.', 'errors' => ['adjustment_reason' => ['A reason is required when you change the suggested amount.']]], 422);
        }

        $refund = $jobOrder->refunds()->create([
            'stage' => $s['stage'],
            'paid_amount' => $s['paid_amount'],
            'non_refundable_amount' => $s['non_refundable_amount'],
            'non_refundable_note' => $v['non_refundable_note'] ?? null,
            'suggested_amount' => $s['suggested_amount'],
            'approved_amount' => $approved,
            'basis' => $s['basis'],
            'adjustment_reason' => $v['adjustment_reason'] ?? null,
            'notes' => $v['notes'] ?? null,
            'recorded_by' => $request->user()->id,
            'refunded_at' => ! empty($v['mark_refunded']) ? now() : null,
        ]);
        $this->logOrderAction($request, $store, $jobOrder, 'order_refund_recorded', ['approved' => $approved, 'suggested' => $s['suggested_amount'], 'stage' => $s['stage']]);

        return response()->json(['success' => true, 'message' => 'Refund recorded.', 'data' => $refund], 201);
    }

    /** Mark a recorded refund as handed back to the customer. */
    public function markRefunded(Request $request, Store $store, JobOrder $jobOrder, OrderRefund $refund): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder)) {
            return $denied;
        }
        if ($refund->job_order_id !== $jobOrder->id) {
            return response()->json(['success' => false, 'message' => 'Refund not found.'], 404);
        }
        $refund->update(['refunded_at' => $refund->refunded_at ?? now()]);

        return response()->json(['success' => true, 'message' => 'Marked as refunded.', 'data' => $refund]);
    }
}
