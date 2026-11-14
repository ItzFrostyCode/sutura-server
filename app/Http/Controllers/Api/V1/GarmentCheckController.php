<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ChecksOrderAccess;
use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\Store;
use App\Notifications\OrderUpdateNotification;
use App\Notifications\StoreActivityNotification;
use App\Support\GarmentCheck;
use App\Support\OrderReadiness;
use App\Support\SafeNotify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The drop-off check of an online garment-work request (see App\Support\GarmentCheck): the shop records what it
 * received, and when it differs from the request the customer accepts the new plan and price, or declines.
 */
class GarmentCheckController extends Controller
{
    use ChecksOrderAccess;

    /** POST /stores/{store}/jobs/{jobOrder}/garment-check — owner, branch manager or staff at the counter. */
    public function record(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder)) {
            return $denied;
        }
        if (! GarmentCheck::applies($jobOrder)) {
            return response()->json(['success' => false, 'message' => 'Only an online repair, alteration or embroidery request is checked at drop-off.'], 422);
        }
        if (! in_array($jobOrder->status, ['pending', 'queued'], true) || GarmentCheck::settled($jobOrder)) {
            return response()->json(['success' => false, 'message' => 'This garment was already checked.'], 422);
        }

        $v = $request->validate([
            'result' => 'required|in:'.GarmentCheck::AS_DESCRIBED.','.GarmentCheck::DIFFERENT,
            'note' => 'required_if:result,'.GarmentCheck::DIFFERENT.'|nullable|string|max:1000',
            'photos' => 'nullable|array|max:6',
            'photos.*' => 'string|max:1000',
            'lines' => 'required_if:result,'.GarmentCheck::DIFFERENT.'|nullable|array|min:1|max:20',
            'lines.*.label' => 'required|string|max:80',
            'lines.*.amount' => 'required|numeric|min:0|max:999999',
        ], [
            'note.required_if' => 'Write what is different from the request.',
            'lines.required_if' => 'Add the work and price for the garment as it is.',
        ]);

        $different = $v['result'] === GarmentCheck::DIFFERENT;
        $lines = $different ? array_values(array_map(fn ($l) => ['label' => trim($l['label']), 'amount' => round((float) $l['amount'], 2)], $v['lines'])) : [];
        $check = [
            'result' => $v['result'],
            'note' => trim((string) ($v['note'] ?? '')) ?: null,
            'photos' => array_values($v['photos'] ?? []),
            'lines' => $lines,
            'total' => $different ? round(array_sum(array_column($lines, 'amount')), 2) : null,
            'previous_total' => (float) $jobOrder->total_amount,
            'checked_at' => now()->toIso8601String(),
            'checked_by' => $request->user()->id,
            'decision' => null,
        ];

        DB::transaction(function () use ($jobOrder, $check, $different) {
            $data = (array) $jobOrder->custom_order_data;
            $data['garment_check'] = $check;
            $jobOrder->custom_order_data = $data;
            // As described: it joins the work queue now. Different: it waits for the customer's answer first.
            if (! $different && $jobOrder->status === 'pending') {
                $jobOrder->status = 'queued';
            }
            $jobOrder->save();
        });

        $this->audit($request, $store, $jobOrder, 'garment_check_recorded', ['result' => $v['result']]);
        $this->tell($jobOrder, $different
            ? ['garment_check_needs_answer', 'Your garment is different from the request', "The shop checked your garment for {$jobOrder->order_number}. Please look at the new price and accept or decline."]
            : ['garment_check_ok', 'Your garment was received', "The shop received your garment for {$jobOrder->order_number}. It is now in line for work."]);

        return response()->json(['success' => true, 'message' => $different ? 'Sent to the customer. Work starts after they accept.' : 'Received. The order is in the work queue.', 'data' => $jobOrder->fresh()]);
    }

    /** POST /my-orders/{jobOrder}/garment-check-decision — the customer accepts the new price, or declines and takes the garment back. */
    public function decide(Request $request, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->customer_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }
        if (! GarmentCheck::awaitingCustomer($jobOrder)) {
            return response()->json(['success' => false, 'message' => 'There is nothing to answer on this order.'], 422);
        }
        $v = $request->validate(['decision' => 'required|in:accept,decline']);
        $accept = $v['decision'] === 'accept';

        DB::transaction(function () use ($jobOrder, $accept) {
            $data = (array) $jobOrder->custom_order_data;
            $check = $data['garment_check'];
            $check['decision'] = $accept ? 'accepted' : 'declined';
            $check['decided_at'] = now()->toIso8601String();
            $data['garment_check'] = $check;
            $jobOrder->custom_order_data = $data;

            if ($accept) {
                // The new total replaces the old; anything already paid still counts.
                $paid = OrderReadiness::paidSoFar($jobOrder);
                $jobOrder->total_amount = $check['total'];
                $jobOrder->balance = max(0, round($check['total'] - $paid - (float) $jobOrder->discount_amount, 2));
                $jobOrder->status = 'queued';
            } else {
                $jobOrder->status = 'cancelled';
                $jobOrder->cancellation_reason = 'garment_check_declined';
            }
            $jobOrder->save();
        });

        $name = $request->user()->name;
        $owner = $jobOrder->store?->owner;
        if ($owner) {
            SafeNotify::send($owner, new StoreActivityNotification(
                $accept ? 'garment_check_accepted' : 'garment_check_declined',
                $accept ? 'Customer accepted the new price' : 'Customer declined the new price',
                $accept ? "{$name} accepted the new price of {$jobOrder->order_number}. It is in the work queue."
                    : "{$name} declined the new price of {$jobOrder->order_number}. Return the garment; nothing is charged.",
                "/dashboard/jobs/{$jobOrder->id}",
            ));
        }

        return response()->json(['success' => true, 'message' => $accept
            ? 'Accepted. The shop will start on it.'
            : 'Declined. Pick up your garment at the shop; you pay nothing.']);
    }

    private function tell(JobOrder $job, array $msg): void
    {
        if ($job->customer) {
            SafeNotify::send($job->customer, new OrderUpdateNotification($job, $msg[0], $msg[1], $msg[2]));
        }
    }

    private function audit(Request $request, Store $store, JobOrder $job, string $action, array $payload): void
    {
        $store->auditLogs()->create([
            'user_id' => $request->user()->id, 'action' => $action,
            'model_type' => JobOrder::class, 'model_id' => $job->id, 'payload' => $payload,
        ]);
    }
}
