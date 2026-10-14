<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ChecksOrderAccess;
use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\JobOrderAssignee;
use App\Models\Store;
use App\Models\User;
use App\Notifications\JobStatusUpdatedNotification;
use App\Notifications\StoreActivityNotification;
use App\Support\OrderFlowPresenter;
use App\Support\OrderQuotation;
use App\Support\OrderReadiness;
use App\Support\SafeNotify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Flow 2 -- from "customer wants to proceed" to "production started": approve the specs, verify
 * measurements, quote, get the customer's approval, confirm once the deposit is verified, and
 * plan tailors. The gates themselves live in App\Support\OrderReadiness.
 */
class OrderFlowController extends Controller
{
    use ChecksOrderAccess;

    private function flowOnly(JobOrder $job): ?JsonResponse
    {
        return OrderReadiness::gated($job)
            ? null
            : response()->json(['success' => false, 'message' => 'This order does not use the confirmation flow.'], 422);
    }

    private function respond(Request $request, JobOrder $job, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => OrderFlowPresenter::payload($job, $request->user())], $status);
    }

    public function show(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder)) {
            return $denied;
        }

        return response()->json(['success' => true, 'data' => OrderFlowPresenter::payload($jobOrder, $request->user())]);
    }

    public function approveSpecs(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder) ?? $this->flowOnly($jobOrder)) {
            return $denied;
        }
        if (! in_array($jobOrder->status, ['pending'], true)) {
            return response()->json(['success' => false, 'message' => 'Specifications can only be approved before the order is confirmed.'], 422);
        }
        $jobOrder->forceFill(['specs_approved_at' => now(), 'specs_approved_by' => $request->user()->id])->save();
        $this->logOrderAction($request, $store, $jobOrder, 'order_specs_approved');

        return $this->respond($request, $jobOrder, 'Design and specifications approved.');
    }

    public function verifyMeasurements(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder) ?? $this->flowOnly($jobOrder)) {
            return $denied;
        }
        $jobOrder->forceFill(['measurements_verified_at' => now(), 'measurements_verified_by' => $request->user()->id])->save();
        $this->logOrderAction($request, $store, $jobOrder, 'order_measurements_verified');

        return $this->respond($request, $jobOrder, 'Measurements verified.');
    }

    public function saveQuotation(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder) ?? $this->flowOnly($jobOrder)) {
            return $denied;
        }
        if ($jobOrder->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'The quotation is locked once the order is confirmed.'], 422);
        }

        $v = $request->validate([
            'base_price' => 'required|numeric|min:0|max:9999999',
            'quantity_adjustment' => 'nullable|numeric|min:-9999999|max:9999999',
            'additional_charges' => 'nullable|array|max:20',
            'additional_charges.*.label' => 'required|string|max:80',
            'additional_charges.*.amount' => 'required|numeric|min:0|max:9999999',
            'customer_provided_deduction' => 'nullable|array|max:20',
            'customer_provided_deduction.*.label' => 'required|string|max:80',
            'customer_provided_deduction.*.amount' => 'required|numeric|min:0|max:9999999',
            'notes' => 'nullable|string|max:500',
            'due_date' => 'nullable|date',
            'status' => 'required|in:estimate,final',
        ]);

        $q = OrderQuotation::normalize($v);
        $dueDate = $v['due_date'] ?? null;

        if ($v['status'] === 'final' && ($blockers = OrderQuotation::finalizeBlockers($jobOrder, $q, $dueDate))) {
            return response()->json(['success' => false, 'message' => 'This stays an estimate: '.$blockers[0]['message'], 'blockers' => $blockers], 422);
        }

        try {
            $approvalCleared = OrderQuotation::apply($jobOrder, $q, $v['status'], $dueDate, $request->user()->id);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
        $this->logOrderAction($request, $store, $jobOrder, 'order_quotation_saved', ['status' => $v['status'], 'total' => $q['total']]);

        $res = $this->respond($request, $jobOrder, $approvalCleared
            ? 'Quotation saved. The customer\'s earlier approval no longer applies, so it needs to be approved again.'
            : 'Quotation saved.');

        return $res;
    }

    /** The customer approves at the counter -- staff or the owner records it. */
    public function approveInPerson(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder) ?? $this->flowOnly($jobOrder)) {
            return $denied;
        }
        $request->validate(['accepted_terms' => 'accepted']);

        return $this->recordApproval($request, $jobOrder, 'in_person', $store);
    }

    /** The customer approves from My Orders. */
    public function customerApprove(Request $request, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->customer_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }
        if ($denied = $this->flowOnly($jobOrder)) {
            return $denied;
        }
        $request->validate(['accepted_terms' => 'accepted']);

        $res = $this->recordApproval($request, $jobOrder, 'online', $jobOrder->store);
        if ($res->getStatusCode() === 200) {
            SafeNotify::send($jobOrder->store?->owner, new StoreActivityNotification(
                'quotation_approved', 'Customer approved the quotation',
                "{$jobOrder->order_number} was approved by the customer — waiting for the deposit to be verified.",
                '/dashboard/jobs/'.$jobOrder->id, ['job_order_id' => $jobOrder->id],
            ));
        }

        return $res;
    }

    private function recordApproval(Request $request, JobOrder $job, string $channel, Store $store): JsonResponse
    {
        if ($job->status !== 'pending' || $job->quotation_status !== 'final') {
            return response()->json(['success' => false, 'message' => 'Only a final quotation on a pending order can be approved.'], 422);
        }
        $job->forceFill([
            'customer_approved_at' => now(),
            'customer_approval_channel' => $channel,
            'customer_approval_snapshot' => [
                'total' => (float) $job->total_amount,
                'due_date' => $job->due_date?->toDateString(),
                'required_deposit' => OrderReadiness::requiredDeposit($job),
                'policy' => $store->cancellationPolicyText(),
            ],
        ])->save();
        $this->logOrderAction($request, $store, $job, 'order_quotation_approved', ['channel' => $channel]);

        // The customer never receives the internal readiness payload (materials, tailors, costs).
        if ($channel === 'online') {
            return response()->json(['success' => true, 'message' => 'Quotation approved.', 'data' => ['customer_approved_at' => $job->fresh()->customer_approved_at]]);
        }

        return $this->respond($request, $job, 'Quotation approved.');
    }

    public function confirm(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder) ?? $this->flowOnly($jobOrder)) {
            return $denied;
        }
        if ($error = OrderReadiness::transitionError($jobOrder, $jobOrder->status, 'confirmed')) {
            return response()->json(['success' => false] + $error, 422);
        }
        if ($jobOrder->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Only a pending order can be confirmed.'], 422);
        }

        $jobOrder->update(['status' => 'confirmed']);
        $jobOrder->forceFill(['confirmed_at' => now()])->save();
        $this->logOrderAction($request, $store, $jobOrder, 'order_confirmed');

        $fresh = $jobOrder->fresh();
        SafeNotify::send($fresh->customer, new JobStatusUpdatedNotification($fresh, $fresh->status, $request->user()));

        return $this->respond($request, $fresh, 'Order confirmed.');
    }

    /**
     * Flow 2 step 7: the shop says the materials are ready, or still on the way. A yes/no sign-off with a
     * note -- not a stock or supplier record. Flips confirmed <-> awaiting_materials.
     */
    public function setMaterials(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder) ?? $this->flowOnly($jobOrder)) {
            return $denied;
        }
        if (in_array($jobOrder->status, ['completed', 'cancelled', 'rejected'], true) || JobOrder::startsProduction($jobOrder->status)) {
            return response()->json(['success' => false, 'message' => 'Materials are checked before production starts.'], 422);
        }
        $v = $request->validate(['state' => 'required|in:ready,waiting', 'note' => 'nullable|string|max:500']);

        $changedTo = OrderReadiness::setMaterials($jobOrder, $v['state'], isset($v['note']) ? trim($v['note']) ?: null : null, $request->user()->id);
        $this->logOrderAction($request, $store, $jobOrder, 'order_materials_'.$v['state']);

        if ($changedTo === 'awaiting_materials') {
            $fresh = $jobOrder->fresh();
            SafeNotify::send($fresh->customer, new JobStatusUpdatedNotification($fresh, 'awaiting_materials', $request->user()));
        }

        return $this->respond($request, $jobOrder, $v['state'] === 'ready' ? 'Materials marked ready.' : 'Marked as waiting for materials.');
    }

    /** Flow 2 step 3: when the customer brings their own fabric, the shop judges whether it is suitable. */
    public function assessCustomerItems(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder) ?? $this->flowOnly($jobOrder)) {
            return $denied;
        }
        if (! OrderReadiness::customerItemsApplicable($jobOrder)) {
            return response()->json(['success' => false, 'message' => 'This order does not use the customer\'s own materials.'], 422);
        }
        $v = $request->validate(['assessment' => 'required|in:suitable,not_suitable', 'note' => 'nullable|string|max:500']);

        $jobOrder->forceFill(['customer_items_assessment' => $v['assessment']])->save();
        if (! empty($v['note'])) {
            $jobOrder->forceFill(['materials_note' => trim($v['note'])])->save();
        }
        OrderReadiness::syncAwaitingMaterials($jobOrder->fresh());
        $this->logOrderAction($request, $store, $jobOrder, 'order_customer_items_assessed', ['assessment' => $v['assessment']]);

        return $this->respond($request, $jobOrder, $v['assessment'] === 'suitable' ? 'Marked as suitable.' : 'Marked as not suitable.');
    }

    /** Replace the planned tailors. Owner / branch manager only; the automatic stage attribution is untouched. */
    public function syncAssignees(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder) ?? $this->flowOnly($jobOrder)) {
            return $denied;
        }
        $v = $request->validate([
            'assignees' => 'present|array|max:10',
            'assignees.*.user_id' => 'required|integer|distinct',
            'assignees.*.role' => 'nullable|in:'.implode(',', JobOrderAssignee::ROLES),
            'assignees.*.note' => 'nullable|string|max:255',
        ]);

        $ids = collect($v['assignees'])->pluck('user_id')->map(fn ($i) => (int) $i);
        if ($ids->contains(fn ($id) => ! $this->isStoreMember($store, $id))) {
            return response()->json(['success' => false, 'message' => 'Only the owner or staff of this shop can be assigned.'], 422);
        }

        $before = $jobOrder->assignees()->pluck('user_id');
        DB::transaction(function () use ($jobOrder, $v, $request) {
            $jobOrder->assignees()->delete();
            foreach ($v['assignees'] as $a) {
                $jobOrder->assignees()->create([
                    'user_id' => $a['user_id'], 'role' => $a['role'] ?? 'tailor',
                    'note' => $a['note'] ?? null, 'assigned_by' => $request->user()->id,
                ]);
            }
        });

        // Tell only the people who were newly planned, and never the person who did the planning.
        User::whereIn('id', $ids->diff($before)->reject(fn ($i) => $i === $request->user()->id))->get()->each(
            fn (User $u) => SafeNotify::send($u, new StoreActivityNotification(
                'job_assigned', 'Order planned for you',
                "{$jobOrder->order_number} was planned for you".($jobOrder->due_date ? ' — due '.$jobOrder->due_date->format('M j') : '').'.',
                '/dashboard/jobs/'.$jobOrder->id, ['job_order_id' => $jobOrder->id],
            ))
        );
        $this->logOrderAction($request, $store, $jobOrder, 'order_assignees_set', ['user_ids' => $ids->values()]);

        return $this->respond($request, $jobOrder, 'Tailors updated.');
    }
}
