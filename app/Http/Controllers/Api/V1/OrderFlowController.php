<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ChecksOrderAccess;
use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\JobOrderAssignee;
use App\Models\Store;
use App\Models\User;
use App\Notifications\JobStatusUpdatedNotification;
use App\Notifications\OrderUpdateNotification;
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
        // "Verified" has to mean something: measurements (or a standard size, or every person's size) are on the order.
        if (OrderReadiness::measurementsNeeded($jobOrder) && ! OrderReadiness::measurementsRecorded($jobOrder)) {
            $everyone = ! empty($jobOrder->custom_order_data['team_roster']);

            return response()->json(['success' => false, 'message' => $everyone
                ? 'Give every person a size or their measurements before you verify them.'
                : 'Record the measurements first, or choose a standard size. They can be added under Details.'], 422);
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
            // The proposed payment terms the customer sees together with the price, before accepting.
            'payment_policy' => 'nullable|in:none,full,deposit,custom',
            'payment_policy_percent' => 'nullable|integer|min:1|max:100',
            'valid_until' => 'nullable|date|after_or_equal:today',
            'reason' => 'nullable|string|max:255',
        ]);

        $q = OrderQuotation::normalize($v);
        $dueDate = $v['due_date'] ?? null;

        if ($v['status'] === 'final' && ($blockers = OrderQuotation::finalizeBlockers($jobOrder, $q, $dueDate))) {
            return response()->json(['success' => false, 'message' => 'This stays an estimate: '.$blockers[0]['message'], 'blockers' => $blockers], 422);
        }

        // Once a price has been sent (or accepted), any change to the price, date or terms needs a reason,
        // so the customer and the shop can see why it moved. Pay-on-pickup also needs one.
        $policy = $v['payment_policy'] ?? $jobOrder->payment_policy;
        $percent = (int) ($v['payment_policy_percent'] ?? $jobOrder->payment_policy_percent ?? 50);
        $termsChanged = $policy !== $jobOrder->payment_policy || $percent !== (int) ($jobOrder->payment_policy_percent ?? 50);
        $changed = abs($q['total'] - (float) $jobOrder->total_amount) > 0.005
            || $dueDate !== $jobOrder->due_date?->toDateString() || $termsChanged;
        $wasSent = $jobOrder->quotation_sent_at !== null || $jobOrder->customer_approved_at !== null;
        $reason = trim((string) ($v['reason'] ?? ''));
        if ($wasSent && $changed && $reason === '') {
            return response()->json(['success' => false, 'message' => 'Say why the price, date or payment terms changed.', 'errors' => ['reason' => ['A reason is required.']]], 422);
        }
        if ($policy === 'none' && $jobOrder->payment_policy !== 'none' && $reason === '') {
            return response()->json(['success' => false, 'message' => 'Say why this customer can pay on pickup.', 'errors' => ['reason' => ['A reason is required.']]], 422);
        }

        try {
            $result = OrderQuotation::apply($jobOrder, $q, $v['status'], $dueDate, $request->user()->id, [
                'payment_policy' => $policy, 'payment_policy_percent' => $percent,
                'valid_until' => $v['valid_until'] ?? null, 'reason' => $reason !== '' ? $reason : null,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
        if (! $jobOrder->shop_accepted_at) {
            $jobOrder->forceFill(['shop_accepted_at' => now(), 'shop_accepted_by' => $request->user()->id])->save();
        }
        $this->logOrderAction($request, $store, $jobOrder, 'order_quotation_saved', [
            'status' => $v['status'], 'total' => $q['total'], 'version' => $result['version'], 'reason' => $reason !== '' ? $reason : null,
        ]);

        if ($result['sent']) {
            $fresh = $jobOrder->fresh();
            $revised = $result['version'] > 1 && $wasSent;
            SafeNotify::send($fresh->customer, new OrderUpdateNotification(
                $fresh,
                $revised ? 'quotation_revised' : 'quotation_ready',
                $revised ? 'Your price was updated' : 'Your price is ready',
                'Order '.$fresh->order_number.': the total is ₱'.number_format((float) $fresh->total_amount, 2).'. '
                    .OrderReadiness::termsText($fresh).' Please open your order to review and approve it.'
                    .($revised && $reason !== '' ? ' Reason: '.$reason : ''),
                $request->user(),
                'order_quotation_ready',
            ));
        }

        return $this->respond($request, $jobOrder, $result['approval_cleared']
            ? 'Quotation saved. The customer\'s earlier approval no longer applies, so it needs to be approved again.'
            : ($result['sent'] ? 'Quotation saved and sent to the customer.' : 'Quotation saved.'));
    }

    /** The shop says it will look at this request and prepare a price. Online requests wait here first. */
    public function acceptRequest(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder) ?? $this->flowOnly($jobOrder)) {
            return $denied;
        }
        if ($jobOrder->status !== 'pending' || $jobOrder->shop_accepted_at) {
            return response()->json(['success' => false, 'message' => 'This request was already accepted or is closed.'], 422);
        }
        $jobOrder->forceFill(['shop_accepted_at' => now(), 'shop_accepted_by' => $request->user()->id])->save();
        $this->logOrderAction($request, $store, $jobOrder, 'order_request_accepted');
        SafeNotify::send($jobOrder->customer, new OrderUpdateNotification(
            $jobOrder, 'request_accepted', 'The shop accepted your request',
            'Order '.$jobOrder->order_number.': '.($store->name ?? 'The shop').' will prepare your price. You will be told when it is ready.',
            $request->user(),
        ));

        return $this->respond($request, $jobOrder, 'Request accepted.');
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

    /** The customer approves from My Orders. Kept for older clients; the same as deciding "accept". */
    public function customerApprove(Request $request, JobOrder $jobOrder): JsonResponse
    {
        $request->merge(['decision' => 'accept']);

        return $this->customerDecision($request, $jobOrder);
    }

    /**
     * The customer's answer to the final quotation: accept it (with its payment terms), ask the shop for
     * changes, or decline. Not answering leaves it waiting; nothing starts until it is accepted.
     */
    public function customerDecision(Request $request, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->customer_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }
        if ($denied = $this->flowOnly($jobOrder)) {
            return $denied;
        }
        $v = $request->validate([
            'decision' => 'required|in:accept,changes,decline',
            'note' => 'nullable|string|max:500',
            'accepted_terms' => $request->input('decision') === 'accept' ? 'accepted' : 'nullable',
        ]);
        if ($jobOrder->status !== 'pending' || $jobOrder->quotation_status !== 'final') {
            return response()->json(['success' => false, 'message' => 'There is no final price waiting for your answer.'], 422);
        }
        $store = $jobOrder->store;
        $owner = $store?->owner;
        $name = $request->user()->name;

        if ($v['decision'] === 'accept') {
            $res = $this->recordApproval($request, $jobOrder, 'online', $store);
            if ($res->getStatusCode() === 200) {
                SafeNotify::send($owner, new StoreActivityNotification(
                    'quotation_approved', 'Customer approved the price',
                    "{$jobOrder->order_number} was approved by {$name} — waiting for the deposit to be verified.",
                    '/dashboard/jobs/'.$jobOrder->id, ['job_order_id' => $jobOrder->id],
                ));
            }

            return $res;
        }

        if ($v['decision'] === 'changes') {
            if (trim((string) ($v['note'] ?? '')) === '') {
                return response()->json(['success' => false, 'message' => 'Tell the shop what you would like to change.', 'errors' => ['note' => ['Required.']]], 422);
            }
            $jobOrder->forceFill([
                'customer_decision' => 'changes_requested', 'customer_decision_note' => trim($v['note']), 'customer_decided_at' => now(),
            ])->save();
            SafeNotify::send($owner, new StoreActivityNotification(
                'quotation_changes_requested', 'Customer asked for changes',
                "{$name} asked to change the price of {$jobOrder->order_number}: ".trim($v['note']),
                '/dashboard/jobs/'.$jobOrder->id, ['job_order_id' => $jobOrder->id],
            ));

            return response()->json(['success' => true, 'message' => 'The shop will send you a new price.', 'data' => ['customer_decision' => 'changes_requested']]);
        }

        // Decline: only while nothing was paid, so no refund is ever needed. The order is closed as cancelled.
        if (OrderReadiness::paidSoFar($jobOrder) > 0.005 || OrderReadiness::pendingVerification($jobOrder) > 0.005) {
            return response()->json(['success' => false, 'message' => 'A payment was already sent for this order. Please contact the shop to cancel it.'], 422);
        }
        $jobOrder->update(['status' => 'cancelled', 'cancellation_reason' => 'quotation_declined']);
        $jobOrder->forceFill([
            'customer_decision' => 'declined', 'customer_decision_note' => trim((string) ($v['note'] ?? '')) ?: null, 'customer_decided_at' => now(),
        ])->save();
        SafeNotify::send($owner, new StoreActivityNotification(
            'quotation_declined', 'Customer declined the price',
            "{$name} declined the price of {$jobOrder->order_number}.".(! empty($v['note']) ? ' '.trim($v['note']) : ''),
            '/dashboard/jobs/'.$jobOrder->id, ['job_order_id' => $jobOrder->id],
        ));

        return response()->json(['success' => true, 'message' => 'You declined this price. The order is closed.', 'data' => ['customer_decision' => 'declined']]);
    }

    private function recordApproval(Request $request, JobOrder $job, string $channel, Store $store): JsonResponse
    {
        if ($job->status !== 'pending' || $job->quotation_status !== 'final') {
            return response()->json(['success' => false, 'message' => 'Only a final quotation on a pending order can be approved.'], 422);
        }
        if ($job->quotation_valid_until && $job->quotation_valid_until->lt(today())) {
            return response()->json(['success' => false, 'message' => 'This price is no longer valid. Ask the shop for a new one.'], 422);
        }
        $job->forceFill([
            'customer_approved_at' => now(),
            'customer_approval_channel' => $channel,
            'customer_approval_snapshot' => [
                'total' => (float) $job->total_amount,
                'due_date' => $job->due_date?->toDateString(),
                'required_deposit' => OrderReadiness::requiredDeposit($job),
                'payment_policy' => $job->payment_policy,
                'payment_policy_percent' => (int) ($job->payment_policy_percent ?? 50),
                'policy' => $store->cancellationPolicyText(),
            ],
            // The agreed payment terms are locked from here on; changing them later needs a new acceptance.
            'customer_decision' => 'accepted',
            'customer_decision_note' => null,
            'customer_decided_at' => now(),
            'payment_terms_locked_at' => now(),
        ])->save();
        $this->logOrderAction($request, $store, $job, 'order_quotation_approved', ['channel' => $channel]);

        // The customer never receives the internal readiness payload (materials, tailors, costs).
        if ($channel === 'online') {
            return response()->json(['success' => true, 'message' => 'Price approved.', 'data' => ['customer_approved_at' => $job->fresh()->customer_approved_at]]);
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
        // Confirming is the owner's explicit authorization: nothing starts without it.
        $jobOrder->forceFill([
            'confirmed_at' => now(), 'production_authorized_at' => now(), 'production_authorized_by' => $request->user()->id,
        ])->save();
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
            'reason' => 'nullable|string|max:255',
        ]);

        $ids = collect($v['assignees'])->pluck('user_id')->map(fn ($i) => (int) $i);
        if ($ids->contains(fn ($id) => ! $this->isStoreMember($store, $id))) {
            return response()->json(['success' => false, 'message' => 'Only the owner or staff of this shop can be assigned.'], 422);
        }

        $before = $jobOrder->assignees()->pluck('user_id');
        $removed = $before->diff($ids)->values();
        $added = $ids->diff($before)->values();
        $reason = trim((string) ($v['reason'] ?? ''));
        // Taking someone off once work has started needs a reason, so a swap is explained later.
        if ($removed->isNotEmpty() && $jobOrder->production_started_at && $reason === '') {
            return response()->json(['success' => false, 'message' => 'Say why the tailor is being changed.', 'errors' => ['reason' => ['A reason is required.']]], 422);
        }
        DB::transaction(function () use ($jobOrder, $v, $request, $removed, $added, $reason) {
            $jobOrder->assignees()->delete();
            foreach ($v['assignees'] as $a) {
                $jobOrder->assignees()->create([
                    'user_id' => $a['user_id'], 'role' => $a['role'] ?? 'tailor',
                    'note' => $a['note'] ?? null, 'assigned_by' => $request->user()->id,
                ]);
            }
            $log = fn (string $action, ?int $from, ?int $to) => $jobOrder->assigneeEvents()->create([
                'action' => $action, 'from_user_id' => $from, 'to_user_id' => $to,
                'reason' => $reason !== '' ? $reason : null, 'by_user_id' => $request->user()->id,
            ]);
            if ($removed->count() === 1 && $added->count() === 1) {
                $log('replaced', (int) $removed->first(), (int) $added->first());
            } else {
                $removed->each(fn ($id) => $log('removed', (int) $id, null));
                $added->each(fn ($id) => $log('added', null, (int) $id));
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
