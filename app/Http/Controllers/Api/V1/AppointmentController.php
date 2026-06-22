<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\StoreAppointmentRequest;
use App\Http\Requests\Shop\UpdateAppointmentRequest;
use App\Models\Shop;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    // ─── Index ────────────────────────────────────────────────────────────────

    public function index(Request $request, Shop $shop): JsonResponse
    {
        $user  = $request->user();
        $roles = $user->roles->pluck('name');

        $query = $shop->appointments()->with([
            'customer:id,name,email',
            'service:id,name',
            'branch:id,name',
            'assignedStaff:id,name',
            'jobOrder:id,order_number',
        ]);

        // Branch manager: filter to their branch only
        if ($roles->contains('branch_manager')) {
            $branchId = $user->staffProfile->shop_branch_id ?? null;
            if ($branchId) {
                $query->where('shop_branch_id', $branchId);
            }
        }

        // Staff: see only appointments in their branch (not just their assigned ones)
        if ($roles->contains('staff') && !$roles->contains('shop_owner') && !$roles->contains('branch_manager')) {
            $branchId = $user->staffProfile->shop_branch_id ?? null;
            if ($branchId) {
                $query->where('shop_branch_id', $branchId);
            }
        }

        // Optional filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type')) {
            $query->where('appointment_type', $request->type);
        }
        if ($request->filled('date')) {
            $query->whereDate('scheduled_at', $request->date);
        }

        return response()->json([
            'success' => true,
            'data'    => $query->orderBy('scheduled_at', 'asc')->get(),
        ]);
    }

    // ─── Store (Owner/Manager creates appointment on behalf of customer) ───────

    public function store(StoreAppointmentRequest $request, Shop $shop): JsonResponse
    {
        $data = $request->validated();

        // Auto-assign branch when shop has only one
        if ($shop->branches()->count() === 1) {
            $data['shop_branch_id'] = $shop->branches()->first()->id;
        }

        // Default duration
        $data['duration_minutes'] = $data['duration_minutes'] ?? 60;
        $data['status']           = 'pending';

        $appointment = $shop->appointments()->create($data);
        $appointment->load(['customer:id,name,email', 'service:id,name', 'branch:id,name', 'assignedStaff:id,name', 'jobOrder:id,order_number']);

        // Notify shop owner of new booking
        $shopOwner = $shop->owner;
        if ($shopOwner) {
            $shopOwner->notify(new \App\Notifications\AppointmentBookedNotification($appointment));
        }

        return response()->json([
            'success' => true,
            'data'    => $appointment,
        ], 201);
    }

    // ─── Update (status transitions + reschedule) ─────────────────────────────

    public function update(UpdateAppointmentRequest $request, Shop $shop, Appointment $appointment): JsonResponse
    {
        if ($appointment->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        // Terminal state lock
        if ($appointment->isTerminal()) {
            return response()->json([
                'success' => false,
                'message' => "A {$appointment->status} appointment cannot be modified.",
            ], 422);
        }

        $user    = $request->user();
        $roles   = $user->roles->pluck('name');
        $isStaff = $roles->contains('staff') && !$roles->contains('shop_owner') && !$roles->contains('branch_manager');

        $data      = $request->validated();
        $newStatus = $data['status'] ?? null;

        // ── Role enforcement ──────────────────────────────────────────────────
        if ($isStaff) {
            // Staff can ONLY move confirmed → in_progress, or in_progress → completed
            $staffAllowed = ['in_progress', 'completed'];
            if ($newStatus && !in_array($newStatus, $staffAllowed)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Staff are not authorized to perform this status change.',
                ], 403);
            }
            // Staff cannot modify schedule or notes
            unset($data['scheduled_at'], $data['notes'], $data['assigned_staff_id']);
        }

        // ── State machine ─────────────────────────────────────────────────────
        if ($newStatus && $newStatus !== $appointment->status) {
            if (!$appointment->canTransitionTo($newStatus)) {
                return response()->json([
                    'success' => false,
                    'message' => "Invalid status transition: '{$appointment->status}' → '{$newStatus}'.",
                ], 422);
            }
        }

        // ── Reschedule logic ──────────────────────────────────────────────────
        $isRescheduled = false;
        if (!empty($data['scheduled_at'])) {
            $oldAt = $appointment->scheduled_at?->format('Y-m-d H:i:s');
            $newAt = date('Y-m-d H:i:s', strtotime($data['scheduled_at']));

            if ($oldAt !== $newAt) {
                // Only pending or confirmed appointments can be rescheduled
                if (!in_array($appointment->status, ['pending', 'confirmed'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Only pending or confirmed appointments can be rescheduled.',
                    ], 422);
                }
                $isRescheduled = true;

                // Audit log
                $shop->auditLogs()->create([
                    'user_id'    => $user->id,
                    'action'     => 'appointment_rescheduled',
                    'model_type' => Appointment::class,
                    'model_id'   => $appointment->id,
                    'payload'    => [
                        'old_scheduled_at' => $oldAt,
                        'new_scheduled_at' => $newAt,
                        'reason'           => $data['notes'] ?? 'Rescheduled by owner/staff',
                    ],
                    'ip_address' => $request->ip(),
                ]);
            }
        }

        // Perform update
        $appointment->update($data);
        $appointment->load(['customer:id,name,email', 'service:id,name', 'branch:id,name', 'assignedStaff:id,name', 'jobOrder:id,order_number']);

        // ── Notifications ─────────────────────────────────────────────────────
        $customer = $appointment->customer;
        if ($customer) {
            if ($isRescheduled) {
                $customer->notify(new \App\Notifications\AppointmentStatusNotification($appointment, 'rescheduled'));
            } elseif ($newStatus && $newStatus !== $appointment->getOriginal('status')) {
                $customer->notify(new \App\Notifications\AppointmentStatusNotification($appointment, $newStatus));
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $appointment,
        ]);
    }

    // ─── Complete (dedicated action with type-specific logic) ─────────────────

    public function complete(Request $request, Shop $shop, Appointment $appointment): JsonResponse
    {
        if ($appointment->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        if ($appointment->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Only in-progress appointments can be marked as completed.',
            ], 422);
        }

        $request->validate([
            'notes'          => ['nullable', 'string', 'max:2000'],
            'job_order_id'   => ['nullable', 'exists:job_orders,id'],
            'measurement_id' => ['nullable', 'exists:measurements,id'],
        ]);

        $type = $appointment->appointment_type;

        // Type-specific rules
        if ($type === 'fitting' && empty($request->job_order_id)) {
            return response()->json([
                'success' => false,
                'message' => 'A fitting appointment must be linked to an existing job order when completing.',
            ], 422);
        }

        if ($type === 'pickup' && empty($request->job_order_id)) {
            return response()->json([
                'success' => false,
                'message' => 'A pickup appointment must reference the completed job order.',
            ], 422);
        }

        // Update appointment
        $updateData = ['status' => 'completed'];
        if ($request->filled('notes')) {
            $updateData['notes'] = $appointment->notes
                ? $appointment->notes . "\n\n[Completion Note] " . $request->notes
                : $request->notes;
        }

        $appointment->update($updateData);
        $appointment->load(['customer:id,name,email', 'service:id,name', 'branch:id,name', 'assignedStaff:id,name', 'jobOrder:id,order_number']);

        // Notify customer
        $customer = $appointment->customer;
        if ($customer) {
            $customer->notify(new \App\Notifications\AppointmentStatusNotification($appointment, 'completed'));
        }

        // Audit log
        $shop->auditLogs()->create([
            'user_id'    => $request->user()->id,
            'action'     => 'appointment_completed',
            'model_type' => Appointment::class,
            'model_id'   => $appointment->id,
            'payload'    => [
                'type'           => $type,
                'job_order_id'   => $request->job_order_id,
                'measurement_id' => $request->measurement_id,
            ],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Appointment marked as completed.',
            'data'    => $appointment,
        ]);
    }

    // ─── Destroy (cancel — owner/manager only) ────────────────────────────────

    public function destroy(Request $request, Shop $shop, Appointment $appointment): JsonResponse
    {
        if ($appointment->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        // Cannot cancel terminal appointments
        if ($appointment->isTerminal()) {
            return response()->json([
                'success' => false,
                'message' => "A {$appointment->status} appointment cannot be cancelled.",
            ], 422);
        }

        // State machine check
        if (!$appointment->canTransitionTo('cancelled')) {
            return response()->json([
                'success' => false,
                'message' => "Cannot cancel an appointment with status '{$appointment->status}'.",
            ], 422);
        }

        $appointment->update(['status' => 'cancelled']);
        $appointment->load(['customer:id,name,email', 'service:id,name', 'branch:id,name', 'assignedStaff:id,name', 'jobOrder:id,order_number']);

        $customer = $appointment->customer;
        if ($customer) {
            $customer->notify(new \App\Notifications\AppointmentStatusNotification($appointment, 'cancelled'));
        }

        return response()->json([
            'success' => true,
            'message' => 'Appointment cancelled.',
            'data'    => $appointment,
        ]);
    }

    public function verifyPayment(Request $request, Shop $shop, Appointment $appointment): JsonResponse
    {
        if ($appointment->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'payment_status' => 'required|in:pending,paid',
        ]);

        $appointment->update([
            'payment_status' => $validated['payment_status']
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment status updated.',
            'data'    => $appointment->load(['customer:id,name,email', 'service:id,name', 'branch:id,name']),
        ]);
    }
}
