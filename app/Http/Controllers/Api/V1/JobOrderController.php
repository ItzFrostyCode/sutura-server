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
    public function index(Shop $shop, Request $request): JsonResponse
    {
        $query = $shop->jobOrders()->with(['customer:id,name', 'service', 'assignedStaff:id,name']);

        if ($request->user()->hasRole('branch_manager')) {
            $branchId = $request->user()->staffProfile->shop_branch_id ?? null;
            if ($branchId) {
                $query->where('shop_branch_id', $branchId);
            }
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->has('assigned_staff_id')) {
            $query->where('assigned_staff_id', $request->assigned_staff_id);
        }

        return response()->json([
            'success' => true,
            'data' => $query->latest()->paginate($request->input('per_page', 15))
        ]);
    }

    public function store(StoreJobOrderRequest $request, Shop $shop): JsonResponse
    {
        $validated = $request->validated();
        $validated['order_number'] = strtoupper(Str::random(8)) . '-' . time();

        $jobOrder = $shop->jobOrders()->create($validated);

        return response()->json([
            'success' => true,
            'data' => $jobOrder->load(['customer:id,name', 'service'])
        ], 201);
    }

    public function show(Shop $shop, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $jobOrder->load(['customer', 'service', 'assignedStaff', 'measurement', 'staffStages', 'payments'])
        ]);
    }

    public function update(UpdateJobOrderRequest $request, Shop $shop, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $oldStatus = $jobOrder->status;
        $jobOrder->update($request->validated());

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
        if ($request->user()->cannot('view', $shop) && !$request->user()->hasRole('shop_owner')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($jobOrder->shop_id !== $shop->id) {
            return response()->json(['message' => 'Job order not found'], 404);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'sometimes|string',
            'notes' => 'nullable|string'
        ]);

        $paymentAmount = (float) $validated['amount'];
        $currentBalance = (float) $jobOrder->balance;

        if ($paymentAmount > $currentBalance) {
            return response()->json(['success' => false, 'message' => 'Payment exceeds remaining balance'], 400);
        }

        $newBalance = $currentBalance - $paymentAmount;
        
        $paymentStatus = 'pending';
        if ($newBalance <= 0) {
            $paymentStatus = 'paid';
        } elseif ($newBalance < $jobOrder->total_amount) {
            $paymentStatus = 'partial';
        }

        $jobOrder->update([
            'balance' => $newBalance,
            'payment_status' => $paymentStatus
        ]);

        // Record the payment in the ledger
        $jobOrder->payments()->create([
            'amount' => $paymentAmount,
            'payment_method' => $validated['payment_method'] ?? 'cash',
            'recorded_by' => $request->user()->id,
            'notes' => $validated['notes'] ?? null
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment logged successfully',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff', 'payments', 'staffStages'])
        ]);
    }

    public function assignStaff(Request $request, Shop $shop, JobOrder $jobOrder): JsonResponse
    {
        if ($request->user()->cannot('update', $shop) && !$request->user()->hasRole('shop_owner')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($jobOrder->shop_id !== $shop->id) {
            return response()->json(['message' => 'Job order not found'], 404);
        }

        $validated = $request->validate([
            'assignments' => 'required|array',
            'assignments.*.user_id' => 'required|exists:users,id',
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
        if ($request->user()->cannot('delete', $shop) && !$request->user()->hasRole('shop_owner')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($jobOrder->shop_id !== $shop->id) {
            return response()->json(['message' => 'Job order not found'], 404);
        }

        $jobOrder->delete();

        return response()->json([
            'success' => true,
            'message' => 'Job order deleted successfully'
        ]);
    }
}
