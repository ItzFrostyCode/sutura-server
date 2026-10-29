<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ChecksOrderAccess;
use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\OrderCorrection;
use App\Models\Store;
use App\Support\OrderFlowPresenter;
use App\Support\OrderReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Flow 3 -- from "in production" to "completed": the quality-check result, corrections (a defect, a
 * fitting issue, or a change the customer asked for), and the handover of garments. Moving the order
 * between stages still goes through the normal status update, whose gates live in OrderReadiness.
 */
class OrderDeliveryController extends Controller
{
    use ChecksOrderAccess;

    private const POST_PRODUCTION = ['qc_ironing', 'ready_for_pickup', 'ready_for_fitting', 'final_adjustments', 'balance_handover'];

    private function guard(Request $request, Store $store, JobOrder $job): ?JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $job)) {
            return $denied;
        }

        return OrderReadiness::gated($job)
            ? null
            : response()->json(['success' => false, 'message' => 'This order does not use the confirmation flow.'], 422);
    }

    private function respond(Request $request, JobOrder $job, string $message, int $code = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => OrderFlowPresenter::payload($job, $request->user())], $code);
    }

    private function fail(string $message, int $code = 422): JsonResponse
    {
        return response()->json(['success' => false, 'message' => \App\Models\JobOrder::withoutAmounts($message, request()->user())], $code);
    }

    /** Flow 3 step 2: the shop checks the garments against the approved design, measurements and specs. */
    public function qualityCheck(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->guard($request, $store, $jobOrder)) {
            return $denied;
        }
        if ($jobOrder->status !== 'qc_ironing') {
            return $this->fail('Move the order to Quality Check first.');
        }
        $v = $request->validate([
            'result' => 'required|in:pass,fail',
            'note' => 'nullable|string|max:1000',
            'affected_quantity' => 'nullable|integer|min:1|max:100000',
            'assigned_user_id' => 'nullable|integer',
            'target_date' => 'nullable|date',
            'shop_fault' => 'sometimes|boolean',
        ]);

        if ($v['result'] === 'pass') {
            if (OrderReadiness::blockingCorrections($jobOrder) > 0) {
                return $this->fail('Finish the open corrections before passing the quality check.');
            }
            $jobOrder->forceFill(['qc_passed_at' => now(), 'qc_passed_by' => $request->user()->id])->save();
            $this->logOrderAction($request, $store, $jobOrder, 'order_qc_passed');

            return $this->respond($request, $jobOrder, 'Quality check passed.');
        }

        if (trim((string) ($v['note'] ?? '')) === '') {
            return $this->fail('Say what is wrong so it can be corrected.');
        }
        if (! empty($v['assigned_user_id']) && ! $this->isStoreMember($store, (int) $v['assigned_user_id'])) {
            return $this->fail('Only the owner or staff of this shop can be assigned.');
        }
        $jobOrder->forceFill(['qc_passed_at' => null, 'qc_passed_by' => null])->save();
        $jobOrder->corrections()->create([
            'kind' => 'defect', 'description' => trim($v['note']), 'affected_quantity' => $v['affected_quantity'] ?? null,
            'assigned_user_id' => $v['assigned_user_id'] ?? null, 'target_date' => $v['target_date'] ?? null,
            'shop_fault' => $v['shop_fault'] ?? true, 'status' => 'open', 'created_by' => $request->user()->id,
        ]);
        $this->logOrderAction($request, $store, $jobOrder, 'order_qc_failed', ['note' => $v['note']]);

        return $this->respond($request, $jobOrder, 'Correction recorded. Send the order to Adjustment / Correction.', 201);
    }

    /** A fitting issue, a defect found later, or a customer change request (which is quoted and approved first). */
    public function storeCorrection(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->guard($request, $store, $jobOrder)) {
            return $denied;
        }
        $v = $request->validate([
            'kind' => 'required|in:'.implode(',', OrderCorrection::KINDS),
            'description' => 'required|string|min:3|max:1000',
            'affected_quantity' => 'nullable|integer|min:1|max:100000',
            'assigned_user_id' => 'nullable|integer',
            'target_date' => 'nullable|date',
            'shop_fault' => 'sometimes|boolean',
            'additional_amount' => 'nullable|numeric|min:0|max:9999999',
        ]);

        $isChange = $v['kind'] === 'change_request';
        if ($isChange && ! $this->isMoneyRole($request)) {
            return $this->fail('The owner or branch manager quotes changes.', 403);
        }
        if ($isChange && ! JobOrder::startsProduction($jobOrder->status) && ! in_array($jobOrder->status, ['confirmed', 'awaiting_materials'], true)) {
            return $this->fail('Changes are quoted after the order is confirmed.');
        }
        if (! $isChange && ! in_array($jobOrder->status, self::POST_PRODUCTION, true)) {
            return $this->fail('Corrections are recorded once the order reaches Quality Check.');
        }
        if (in_array($jobOrder->status, ['completed', 'cancelled', 'rejected'], true)) {
            return $this->fail('This order is closed.');
        }
        if ($isChange && ! isset($v['additional_amount'])) {
            return $this->fail('Enter the additional quotation for this change.');
        }
        if (! empty($v['assigned_user_id']) && ! $this->isStoreMember($store, (int) $v['assigned_user_id'])) {
            return $this->fail('Only the owner or staff of this shop can be assigned.');
        }

        $jobOrder->corrections()->create([
            'kind' => $v['kind'], 'description' => trim($v['description']), 'affected_quantity' => $v['affected_quantity'] ?? null,
            'assigned_user_id' => $v['assigned_user_id'] ?? null, 'target_date' => $v['target_date'] ?? null,
            // A customer change is never "the shop's fault", so it is never corrected for free.
            'shop_fault' => $isChange ? false : ($v['shop_fault'] ?? true),
            'status' => $isChange ? 'awaiting_approval' : 'open',
            'additional_amount' => $isChange ? $v['additional_amount'] : null,
            'created_by' => $request->user()->id,
        ]);
        if ($v['kind'] === 'fitting_issue') {
            $jobOrder->forceFill(['fitting_passed_at' => null])->save();
        }
        $this->logOrderAction($request, $store, $jobOrder, 'order_correction_added', ['kind' => $v['kind']]);

        return $this->respond($request, $jobOrder, $isChange ? 'Change quoted. It waits for the customer\'s approval.' : 'Correction recorded.', 201);
    }

    public function updateCorrection(Request $request, Store $store, JobOrder $jobOrder, OrderCorrection $correction): JsonResponse
    {
        if ($denied = $this->guard($request, $store, $jobOrder)) {
            return $denied;
        }
        if ($correction->job_order_id !== $jobOrder->id) {
            return $this->fail('Correction not found.', 404);
        }
        $v = $request->validate([
            'description' => 'sometimes|string|min:3|max:1000',
            'affected_quantity' => 'nullable|integer|min:1|max:100000',
            'assigned_user_id' => 'nullable|integer',
            'target_date' => 'nullable|date',
        ]);
        if (! empty($v['assigned_user_id']) && ! $this->isStoreMember($store, (int) $v['assigned_user_id'])) {
            return $this->fail('Only the owner or staff of this shop can be assigned.');
        }
        $correction->update($v);

        return $this->respond($request, $jobOrder, 'Correction updated.');
    }

    public function resolveCorrection(Request $request, Store $store, JobOrder $jobOrder, OrderCorrection $correction): JsonResponse
    {
        if ($denied = $this->guard($request, $store, $jobOrder)) {
            return $denied;
        }
        if ($correction->job_order_id !== $jobOrder->id) {
            return $this->fail('Correction not found.', 404);
        }
        if ($correction->status !== 'open') {
            return $this->fail($correction->status === 'awaiting_approval' ? 'This change has not been approved by the customer yet.' : 'This correction is already closed.');
        }
        $correction->update(['status' => 'done', 'resolved_at' => now()]);
        $this->logOrderAction($request, $store, $jobOrder, 'order_correction_done', ['correction_id' => $correction->id]);

        return $this->respond($request, $jobOrder, 'Correction marked done.');
    }

    public function cancelCorrection(Request $request, Store $store, JobOrder $jobOrder, OrderCorrection $correction): JsonResponse
    {
        if ($denied = $this->guard($request, $store, $jobOrder)) {
            return $denied;
        }
        if ($correction->job_order_id !== $jobOrder->id || ! $correction->isBlocking()) {
            return $this->fail('Only an open correction or an unapproved change can be cancelled.');
        }
        $correction->update(['status' => 'cancelled']);
        $this->logOrderAction($request, $store, $jobOrder, 'order_correction_cancelled', ['correction_id' => $correction->id]);

        return $this->respond($request, $jobOrder, 'Cancelled.');
    }

    /**
     * The customer approved a change request: its extra price joins the order total (and the balance, by
     * the same amount), and a new completion date, if there is one, is recorded in the audit log -- a
     * confirmed date is never changed silently.
     */
    public function approveChange(Request $request, Store $store, JobOrder $jobOrder, OrderCorrection $correction): JsonResponse
    {
        if ($denied = $this->guard($request, $store, $jobOrder)) {
            return $denied;
        }
        if ($correction->job_order_id !== $jobOrder->id || $correction->kind !== 'change_request' || $correction->status !== 'awaiting_approval') {
            return $this->fail('Only a quoted change that is waiting for approval can be approved.');
        }
        $v = $request->validate(['accepted' => 'accepted', 'due_date' => 'nullable|date', 'reason' => 'nullable|string|max:500']);

        DB::transaction(function () use ($jobOrder, $correction, $v, $request, $store) {
            $locked = JobOrder::where('id', $jobOrder->id)->lockForUpdate()->firstOrFail();
            $extra = (float) $correction->additional_amount;
            $total = round((float) $locked->total_amount + $extra, 2);
            $balance = round((float) $locked->balance + $extra, 2);
            $paid = OrderReadiness::paidSoFar($locked);
            $oldDue = $locked->due_date?->toDateString();

            $locked->forceFill([
                'total_amount' => $total, 'balance' => $balance,
                'payment_status' => $balance <= 0 && $total > 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
                'due_date' => $v['due_date'] ?? $locked->due_date,
            ])->save();
            $correction->update(['status' => 'open', 'approved_at' => now()]);

            $this->logOrderAction($request, $store, $locked, 'order_change_approved', ['correction_id' => $correction->id, 'additional_amount' => $extra]);
            if (! empty($v['due_date']) && $v['due_date'] !== $oldDue) {
                $this->logOrderAction($request, $store, $locked, 'order_due_date_changed', ['from' => $oldDue, 'to' => $v['due_date'], 'reason' => $v['reason'] ?? 'Approved change request']);
            }
        });

        return $this->respond($request, $jobOrder, 'Change approved and added to the order.');
    }

    /** Flow 3 step 6: record the garments the customer took. A partial release keeps the order open. */
    public function recordHandover(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->guard($request, $store, $jobOrder)) {
            return $denied;
        }
        if ($jobOrder->status !== 'balance_handover') {
            return $this->fail('Move the order to Balance Payment & Handover first.');
        }
        $release = $request->boolean('release_with_balance');
        $balance = (float) $jobOrder->balance;
        if ($balance > 0.005) {
            if (! $release) {
                return $this->fail('Collect the remaining balance of ₱'.number_format($balance, 2).' first. The order stays Ready for Collection until it is paid.');
            }
            // The garments leave before they are paid: only the owner or a branch manager decides that.
            if (! $request->user()->hasRole('store_owner') && ! $request->user()->hasRole('branch_manager')) {
                return $this->fail('Only the owner or a branch manager can release garments with a balance due.', 403);
            }
        }
        $v = $request->validate([
            'quantity' => 'required|integer|min:1',
            'received_by' => [$release && $balance > 0.005 ? 'required' : 'nullable', 'string', 'max:120'],
            'note' => 'nullable|string|max:500',
            // Garments that leave before they are paid need a date by which the balance will be paid.
            'pay_by' => [$release && $balance > 0.005 ? 'required' : 'nullable', 'date', 'after_or_equal:today'],
        ], ['pay_by.required' => 'Choose the date the customer will pay the balance by.']);

        $remaining = $jobOrder->garmentCount() - OrderReadiness::handedOver($jobOrder);
        if ($v['quantity'] > $remaining) {
            return $this->fail($jobOrder->garmentCount() > 1 && $jobOrder->garmentCount() === $jobOrder->peopleCount()
                ? "Only {$remaining} of the {$jobOrder->garmentCount()} people's garments ".($remaining === 1 ? 'is' : 'are').' left to hand over.'
                : "Only {$remaining} garment".($remaining === 1 ? ' is' : 's are').' left to hand over.');
        }
        $jobOrder->handovers()->create([
            'quantity' => $v['quantity'], 'received_by' => $v['received_by'] ?? null, 'note' => $v['note'] ?? null,
            'recorded_by' => $request->user()->id, 'handed_over_at' => now(),
        ]);
        $this->logOrderAction($request, $store, $jobOrder, 'order_handover_recorded', ['quantity' => $v['quantity']]);
        if ($release && $balance > 0.005) {
            $jobOrder->forceFill([
                'released_with_balance_at' => $jobOrder->released_with_balance_at ?? now(),
                'released_with_balance_by' => $request->user()->id,
                'balance_due_by' => $v['pay_by'] ?? $jobOrder->balance_due_by,
            ])->save();
            $this->logOrderAction($request, $store, $jobOrder, 'handover_released_with_balance', [
                'quantity' => $v['quantity'], 'balance' => $balance, 'pay_by' => $v['pay_by'] ?? null,
                'received_by' => $v['received_by'] ?? null, 'note' => $v['note'] ?? null,
            ]);
        }

        return $this->respond($request, $jobOrder, 'Handover recorded.', 201);
    }
}
