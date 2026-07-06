<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\StoreJobOrderRequest;
use App\Http\Requests\Shop\UpdateJobOrderRequest;
use App\Models\Shop;
use App\Models\JobOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
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
                $service->service_type === \App\Models\Service::TYPE_BULK_SUBLIMATION
                && $service->min_order_qty > 1
                && (!$roster || count($roster) < $service->min_order_qty)
            ) {
                return response()->json([
                    'success' => false,
                    'message' => "This service requires a minimum of {$service->min_order_qty} pieces.",
                ], 422);
            }

            if (
                $service->service_type === \App\Models\Service::TYPE_ALTERATION_REPAIR
                && trim($validated['custom_order_data']['pre_existing_damage_notes'] ?? '') === ''
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pre-existing damage/condition notes are required for alteration and repair jobs.',
                ], 422);
            }
        }

        $validated['order_number'] = strtoupper(Str::random(8)) . '-' . time();
        $validated['order_type'] = $validated['order_type'] ?? 'walk_in';

        // Auto-assign branch if creator is staff or branch manager and not explicitly set
        $staffProfile = $request->user()->staffProfile;
        if ($staffProfile && empty($validated['shop_branch_id'])) {
            $validated['shop_branch_id'] = $staffProfile->shop_branch_id;
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

        $jobOrder = $shop->jobOrders()->create($validated);

        // Link to appointment if appointment_id is present
        if ($request->filled('appointment_id')) {
            $appointment = \App\Models\Appointment::find($request->appointment_id);
            if ($appointment && $appointment->shop_id === $shop->id) {
                $appointment->update([
                    'job_order_id' => $jobOrder->id,
                ]);
                if ($appointment->status === 'pending') {
                    $appointment->update(['status' => 'confirmed']);
                }
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


        $jobOrder->load(['customer:id,name', 'service']);

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
            if (in_array($newStatus, ['cutting', 'sewing', 'fitting'], true) && (float) $jobOrder->balance >= (float) $jobOrder->total_amount && (float) $jobOrder->total_amount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'A downpayment must be collected before production can start on this job.',
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
            'notes' => 'nullable|string'
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
                    'recorded_by' => $request->user()->id,
                    'notes' => $validated['notes'] ?? null,
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

    public function assignStaff(Request $request, Shop $shop, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id) {
            return response()->json(['message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $validated = $request->validate([
            'assignments' => 'required|array',
            'assignments.*.user_id' => [
                'required',
                \Illuminate\Validation\Rule::exists('staff_profiles', 'user_id')->where('shop_id', $shop->id),
            ],
            'assignments.*.stage' => 'required|string',
        ]);

        // Sync without detaching existing ones, or just completely replace them
        // To handle updates and deletes neatly, we delete existing and recreate, or we use a sync mechanism.
        // For simplicity, we can delete all stages and re-insert the provided ones.
        $jobOrder->staffStages()->detach();

        foreach ($validated['assignments'] as $assignment) {
            $jobOrder->staffStages()->attach($assignment['user_id'], [
                'stage' => $assignment['stage'],
                'assigned_at' => now(),
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
