<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\StoreJobOrderRequest;
use App\Http\Requests\Shop\UpdateJobOrderRequest;
use App\Models\Shop;
use App\Models\JobOrder;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobOrderController extends Controller
{
    /**
     * Branch managers/staff pinned to a branch may only act on job orders
     * that belong to that same branch (shop_owner is unrestricted). Jobs or
     * users with no branch assigned (single-branch shops) are never blocked.
     */
    private function branchAccessDenied(Request $request, JobOrder $jobOrder): ?JsonResponse
    {
        $user = $request->user();
        if ($user->hasRole('shop_owner')) {
            return null;
        }

        $userBranchId = $user->staffProfile->shop_branch_id ?? null;

        if ($userBranchId && $jobOrder->shop_branch_id && (int) $userBranchId !== (int) $jobOrder->shop_branch_id) {
            return response()->json(['success' => false, 'message' => 'This job order belongs to a different branch.'], 403);
        }

        return null;
    }

    /**
     * "JO-{year}-{sequential}" (e.g. JO-2026-0503) instead of an opaque
     * random string — matches the format already shown throughout the
     * dashboard/print ticket/receipts, and lets an owner recognize order
     * numbers as sequential the way real invoice books work. Scoped per
     * shop and per year; includes soft-deleted orders so a number is never
     * reused once issued.
     */
    private function generateOrderNumber(Shop $shop): string
    {
        $year = now()->year;
        $prefix = "JO-{$year}-";

        $lastNumber = $shop->jobOrders()
            ->withTrashed()
            ->where('order_number', 'like', $prefix . '%')
            ->get()
            ->map(function ($job) use ($prefix) {
                return (int) str_replace($prefix, '', $job->order_number);
            })
            ->max() ?? 0;

        return $prefix . str_pad((string) ($lastNumber + 1), 4, '0', STR_PAD_LEFT);
    }

    public function index(Shop $shop, Request $request): JsonResponse
    {
        $query = $shop->jobOrders()->with(['customer:id,name,suki_tag', 'service', 'assignedStaff:id,name']);

        $branchId = null;
        if (!$request->user()->hasRole('shop_owner') && $request->user()->staffProfile?->shop_branch_id) {
            // Staff/branch managers pinned to a branch only ever see that branch's
            // jobs — matches the per-action branch check enforced elsewhere below,
            // so nothing shows up in the list that they'd then be blocked from opening.
            $branchId = $request->user()->staffProfile->shop_branch_id;
        } elseif ($request->filled('branch_id')) {
            $branchId = $request->branch_id;
        }

        if ($branchId) {
            $query->where('shop_branch_id', $branchId);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->has('assigned_staff_id')) {
            $query->where('assigned_staff_id', $request->assigned_staff_id);
        }

        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        return response()->json([
            'success' => true,
            'data' => $query->latest()->paginate($request->input('per_page', 15))
        ]);
    }

    public function store(StoreJobOrderRequest $request, Shop $shop): JsonResponse
    {
        $validated = $request->validated();

        // Server-side enforcement of service-type rules — the frontend already
        // checks these, but a client can't be trusted for money/liability-sensitive
        // rules like minimum order quantity or damage-waiver logging.
        $service = \App\Models\Service::find($validated['service_id']);
        if ($service) {
            $roster = $validated['custom_order_data']['team_roster'] ?? null;
            if (
                $service->hasType(\App\Models\Service::TYPE_BULK_SUBLIMATION)
                && $service->min_order_qty > 1
                && (!$roster || count($roster) < $service->min_order_qty)
            ) {
                return response()->json([
                    'success' => false,
                    'message' => "This service requires a minimum of {$service->min_order_qty} pieces.",
                ], 422);
            }

            if (
                $service->hasType(\App\Models\Service::TYPE_ALTERATION_REPAIR)
                && trim($validated['custom_order_data']['pre_existing_damage_notes'] ?? '') === ''
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pre-existing damage/condition notes are required for alteration and repair jobs.',
                ], 422);
            }
        }

        $validated['order_number'] = $this->generateOrderNumber($shop);
        $validated['order_type'] = $validated['order_type'] ?? 'walk_in';

        // staff_stages isn't a job_orders column — pull it out before create(),
        // then derive assigned_staff_id from it (first assigned stage, in
        // production order) so the staff portal / analytics reports — which
        // still filter by assigned_staff_id — keep working without the owner
        // having to separately pick a redundant "overall" staff member.
        $staffStages = $validated['staff_stages'] ?? [];
        unset($validated['staff_stages']);
        if (empty($validated['assigned_staff_id']) && !empty($staffStages)) {
            $stageOrder = JobOrder::STAFF_STAGES;
            $byStage = collect($staffStages)->keyBy('stage');
            foreach ($stageOrder as $stage) {
                if ($byStage->has($stage)) {
                    $validated['assigned_staff_id'] = $byStage[$stage]['user_id'];
                    break;
                }
            }
        }

        // Auto-assign branch if creator is staff or branch manager and not explicitly set
        $staffProfile = $request->user()->staffProfile;
        if ($staffProfile && empty($validated['shop_branch_id'])) {
            $validated['shop_branch_id'] = $staffProfile->shop_branch_id;
        }

        // A coupon here is already applied to total_amount/balance by the
        // owner's own form (so their downpayment math stays internally
        // consistent) — unlike the public catalog checkout, this caller is
        // the authenticated shop owner/manager who already fully controls
        // total_amount directly, so there's no separate trust boundary to
        // enforce. The backend's job is just to confirm the code is still
        // legitimate and record/track its use, not recompute the math.
        $couponCode = $validated['coupon_code'] ?? null;
        $discountAmount = $validated['discount_amount'] ?? null;
        unset($validated['coupon_code']);
        if ($couponCode) {
            $candidateCoupon = $shop->coupons()->where('code', strtoupper($couponCode))->first();
            // Re-validates and increments atomically under a row lock so a
            // usage-limited code can't be redeemed past its limit by two
            // near-simultaneous job orders both passing isValidFor() above.
            $coupon = $candidateCoupon && $candidateCoupon->isValidFor('services')
                ? \App\Models\Coupon::redeem($candidateCoupon->id, 'services')
                : null;
            if ($coupon) {
                $validated['coupon_id'] = $coupon->id;
                $validated['discount_amount'] = $discountAmount;
            } else {
                unset($validated['discount_amount']);
            }
        }

        // Determine payment status based on total amount and balance
        $totalAmount = (float)$validated['total_amount'];
        $balance = (float)$validated['balance'];
        $initialPayment = $totalAmount - $balance;

        if ($balance <= 0) {
            $validated['payment_status'] = 'paid';
        } elseif ($initialPayment > 0) {
            $validated['payment_status'] = 'partial';
        } else {
            $validated['payment_status'] = 'unpaid';
        }

        // Resolve the linked appointment (if any) before creating the job so its
        // design-reference photos/link — attached by the customer at booking time —
        // carry over onto the job the assigned staff actually work from, instead of
        // being stranded on an appointment record nobody looks at again.
        $appointment = null;
        if ($request->filled('appointment_id')) {
            $candidate = \App\Models\Appointment::find($request->appointment_id);
            if ($candidate && $candidate->shop_id === $shop->id) {
                $appointment = $candidate;
                if (empty($validated['reference_images']) && !empty($appointment->reference_images)) {
                    $validated['reference_images'] = $appointment->reference_images;
                }
                if (empty($validated['reference_link']) && !empty($appointment->reference_link)) {
                    $validated['reference_link'] = $appointment->reference_link;
                }
            }
        }

        $jobOrder = $shop->jobOrders()->create($validated);

        foreach ($staffStages as $assignment) {
            $jobOrder->staffStages()->attach($assignment['user_id'], [
                'stage' => $assignment['stage'],
                'assigned_at' => now(),
                'completed_at' => null,
            ]);
        }

        // Link back to the appointment now that the job exists
        if ($appointment) {
            $appointment->update([
                'job_order_id' => $jobOrder->id,
            ]);
            if ($appointment->status === 'pending') {
                $appointment->update(['status' => 'confirmed']);
            }
        }

        // Record initial payment history if downpayment occurred
        if ($initialPayment > 0) {
            $jobOrder->payments()->create([
                'amount' => $initialPayment,
                'payment_method' => $request->input('payment_method') ?? 'cash',
                'recorded_by' => $request->user()->id,
                'notes' => 'Initial downpayment recorded during order creation.'
            ]);
        }


        $jobOrder->load(['customer:id,name', 'service', 'assignedStaff:id,name', 'staffStages']);

        // Notify shop owner of the new job order
        $shopOwner = $shop->owner;
        if ($shopOwner) {
            $shopOwner->notify(new \App\Notifications\NewJobOrderNotification($jobOrder));
        }

        return response()->json([
            'success' => true,
            'data' => $jobOrder
        ], 201);
    }

    public function show(Request $request, Shop $shop, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        return response()->json([
            'success' => true,
            'data' => $jobOrder->load(['customer', 'service', 'assignedStaff', 'measurement', 'staffStages', 'payments.recordedBy:id,name'])
        ]);
    }

    public function update(UpdateJobOrderRequest $request, Shop $shop, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $oldStatus = $jobOrder->status;
        $validated = $request->validated();
        $newStatus = $validated['status'] ?? null;

        // Server-side backstop for the same "No DP, No Cut" / "No Balance, No Claim"
        // rules the Kanban UI enforces — this endpoint is also reachable from the
        // Job Detail page's own status dropdown, so the rule has to live here too,
        // not just in one frontend component.
        if ($newStatus && $newStatus !== $oldStatus) {
            $totalAmount = (float) $jobOrder->total_amount;
            $paidSoFar   = $totalAmount - (float) $jobOrder->balance;

            // Matches the "50% downpayment required" policy shown on the Job
            // Detail page and Kanban board — previously this only checked that
            // *something* had been paid (balance < total), which let a ₱1
            // payment on a ₱10,000 job through despite the UI advertising 50%.
            // Design/pattern_making are deliberately excluded — no fabric or
            // material is committed yet at those stages, only once cutting
            // actually starts.
            if (in_array($newStatus, ['cutting', 'sewing', 'fitting', 'finishing'], true) && $totalAmount > 0 && $paidSoFar < ($totalAmount * 0.5)) {
                return response()->json([
                    'success' => false,
                    'message' => 'A 50% downpayment must be collected before production can start on this job.',
                ], 422);
            }

            if ($newStatus === 'completed' && (float) $jobOrder->balance > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'The remaining balance must be paid before this job can be marked completed.',
                ], 422);
            }
        }

        $jobOrder->update($validated);

        // When the OWNER marks a job completed, stamp completion on its staff
        // assignments so "jobs completed" / productivity is derivable without a staff portal.
        if ($jobOrder->status === 'completed' && $oldStatus !== 'completed') {
            \Illuminate\Support\Facades\DB::table('job_order_staff')
                ->where('job_order_id', $jobOrder->id)
                ->whereNull('completed_at')
                ->update(['completed_at' => now()]);
        }

        if ($jobOrder->status === 'ready_for_pickup' && $oldStatus !== 'ready_for_pickup') {
            $jobOrder->customer->notify(new \App\Notifications\OrderReadyNotification($jobOrder));
        }

        // Matches the thesis's own scope objective — customers "receive automated
        // SMS or email notifications at each stage transition, from placement to
        // final pickup." ready_for_pickup keeps its dedicated notification above;
        // every other production/fulfillment stage is covered here.
        $otherNotifiableStatuses = ['design', 'pattern_making', 'cutting', 'sewing', 'fitting', 'finishing', 'packed', 'handed_to_courier', 'completed', 'cancelled'];
        if (in_array($jobOrder->status, $otherNotifiableStatuses, true) && $jobOrder->status !== $oldStatus) {
            $jobOrder->customer->notify(new \App\Notifications\JobStatusUpdatedNotification($jobOrder, $jobOrder->status));
        }

        return response()->json([
            'success' => true,
            'data' => $jobOrder
        ]);
    }

    public function pay(Request $request, Shop $shop, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id) {
            return response()->json(['message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'sometimes|string',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'receipt_path' => 'nullable|string|max:2048',
        ]);

        $paymentAmount = (float) $validated['amount'];

        try {
            // Lock the row for the duration of the transaction so two payments
            // submitted at nearly the same time (e.g. two staff on the same job)
            // can't both read the same starting balance and overpay/double-count.
            \Illuminate\Support\Facades\DB::transaction(function () use ($jobOrder, $paymentAmount, $validated, $request) {
                $locked = JobOrder::where('id', $jobOrder->id)->lockForUpdate()->firstOrFail();

                $currentBalance = (float) $locked->balance;
                if ($paymentAmount > $currentBalance) {
                    throw new \RuntimeException('Payment exceeds remaining balance');
                }

                $newBalance = round($currentBalance - $paymentAmount, 2);
                $paymentStatus = $newBalance <= 0 ? 'paid' : 'partial';

                $locked->update([
                    'balance' => $newBalance,
                    'payment_status' => $paymentStatus,
                ]);

                $locked->payments()->create([
                    'amount' => $paymentAmount,
                    'payment_method' => $validated['payment_method'] ?? 'cash',
                    'reference' => $validated['reference'] ?? null,
                    'recorded_by' => $request->user()->id,
                    'notes' => $validated['notes'] ?? null,
                    'receipt_path' => $validated['receipt_path'] ?? null,
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        // Notify shop owner of the payment
        $shopOwner = $shop->owner;
        if ($shopOwner) {
            $shopOwner->notify(new \App\Notifications\PaymentReceivedNotification($jobOrder, $paymentAmount));
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment logged successfully',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff', 'payments.recordedBy:id,name', 'staffStages'])
        ]);
    }

    // Corrects a payment's metadata (method/reference/receipt/notes) without
    // touching `amount` — the amount is what the job's balance was already
    // calculated from, so it stays permanently immutable here. If a walk-in
    // payment was logged with the wrong reference number or receipt image,
    // this fixes the record instead of requiring a reversal/re-entry that
    // would distort the payment trail.
    public function updatePayment(Request $request, Shop $shop, JobOrder $jobOrder, Payment $payment): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id || $payment->job_order_id !== $jobOrder->id) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        // Once a job is completed (garment delivered/picked up), its payment
        // records are final — matching the same "never silently rewrite the
        // payment trail" principle already applied elsewhere (amount is
        // always locked; this closes the remaining loophole where method/
        // reference/notes could still be edited after the fact).
        if ($jobOrder->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'This job is already completed — its payment records can no longer be edited.',
            ], 422);
        }

        $validated = $request->validate([
            'payment_method' => 'sometimes|string',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'receipt_path' => 'nullable|string|max:2048',
        ]);

        $payment->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Payment updated successfully',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff', 'payments.recordedBy:id,name', 'staffStages'])
        ]);
    }

    public function assignStaff(Request $request, Shop $shop, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id) {
            return response()->json(['message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        // "present" (not "required") on purpose — an empty array is a valid,
        // deliberate request: every stage was left/set to Unassigned, which
        // should clear existing assignments rather than fail. "required"
        // treats an empty array as missing entirely, which was rejecting
        // exactly that case with a 422.
        $validated = $request->validate([
            'assignments' => 'present|array',
            'assignments.*.user_id' => [
                'required',
                \Illuminate\Validation\Rule::exists('staff_profiles', 'user_id')->where('shop_id', $shop->id),
            ],
            'assignments.*.stage' => ['required', \Illuminate\Validation\Rule::in(JobOrder::STAFF_STAGES)],
        ]);

        // Capture existing completion timestamps before replacing the pivot rows —
        // a blind detach+reattach previously wiped completed_at even for stages
        // whose assigned staff wasn't changing, which silently flipped their
        // "Completed" badge back to "In Progress" every time assignments were
        // re-saved (e.g. just to add one more stage).
        $existing = $jobOrder->staffStages()->get()->keyBy(fn ($staff) => $staff->pivot->stage);

        $jobOrder->staffStages()->detach();

        foreach ($validated['assignments'] as $assignment) {
            $previous = $existing->get($assignment['stage']);
            $samePersonAsBefore = $previous && (int) $previous->id === (int) $assignment['user_id'];

            $jobOrder->staffStages()->attach($assignment['user_id'], [
                'stage' => $assignment['stage'],
                'assigned_at' => $samePersonAsBefore ? $previous->pivot->assigned_at : now(),
                'completed_at' => $samePersonAsBefore ? $previous->pivot->completed_at : null,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Staff assigned to stages successfully',
            'data' => $jobOrder->fresh(['staffStages'])
        ]);
    }

    public function destroy(Request $request, Shop $shop, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id) {
            return response()->json(['message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        // Once money has changed hands, deleting the record would silently erase
        // the payment trail. Cancel it instead so the ledger stays intact.
        if ($jobOrder->payments()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This job order has recorded payments and cannot be deleted. Cancel it instead to keep the payment history intact.'
            ], 400);
        }

        $jobOrder->delete();

        return response()->json([
            'success' => true,
            'message' => 'Job order deleted successfully'
        ]);
    }

    public function restore(Request $request, Shop $shop, int $jobOrderId): JsonResponse
    {
        $jobOrder = JobOrder::onlyTrashed()->where('id', $jobOrderId)->first();

        if (!$jobOrder || $jobOrder->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Deleted job order not found.'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $jobOrder->restore();

        return response()->json([
            'success' => true,
            'data' => $jobOrder->load(['customer:id,name,suki_tag', 'service', 'assignedStaff:id,name']),
        ]);
    }
}
