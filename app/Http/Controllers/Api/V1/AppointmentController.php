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
    /**
     * Branch managers/staff pinned to a branch may only act on appointments
     * that belong to that same branch (shop_owner is unrestricted). Mirrors
     * JobOrderController::branchAccessDenied() exactly, for the same reason:
     * index() already filters the list by branch for these roles, so nothing
     * should be reachable here that wouldn't have shown up there.
     */
    private function branchAccessDenied(Request $request, Appointment $appointment): ?JsonResponse
    {
        $user = $request->user();
        if ($user->hasRole('shop_owner')) {
            return null;
        }

        $userBranchId = $user->staffProfile->shop_branch_id ?? null;

        if ($userBranchId && $appointment->shop_branch_id && (int) $userBranchId !== (int) $appointment->shop_branch_id) {
            return response()->json(['success' => false, 'message' => 'This appointment belongs to a different branch.'], 403);
        }

        return null;
    }

    // ─── Index ────────────────────────────────────────────────────────────────

    public function index(Request $request, Shop $shop): JsonResponse
    {
        $user  = $request->user();
        $roles = $user->roles->pluck('name');

        $query = $shop->appointments()->with([
            'customer:id,name,email',
            'service:id,name,base_price',
            'branch:id,name',
            'assignedStaff:id,name',
            'jobOrder:id,order_number',
        ]);

        $branchId = null;
        if ($roles->contains('branch_manager') || ($roles->contains('staff') && !$roles->contains('shop_owner'))) {
            $branchId = $user->staffProfile->shop_branch_id ?? null;
        } elseif ($request->filled('branch_id')) {
            $branchId = $request->branch_id;
        }

        if ($branchId) {
            $query->where('shop_branch_id', $branchId);
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

        // Auto-calculated duration based on Appointment Type — the owner's
        // form no longer offers a manual Duration selector at all.
        $data['duration_minutes'] = $data['duration_minutes']
            ?? Appointment::TYPE_DEFAULT_DURATIONS[$data['appointment_type']]
            ?? 60;
        $data['status']           = 'pending';
        // Owner-created entries are walk-ins (online ones come via the public booking form).
        // Matches JobOrder/CatalogOrder's own 'walk_in' convention — this used to be the
        // inconsistent 'walkin' (no underscore), which drifted from the seeder and other models.
        $data['intake_channel']   = 'walk_in';

        // Same conflict guard the public booking form already enforces —
        // an owner/staff manually logging a walk-in shouldn't be able to
        // double-book a slot a customer already has confirmed.
        if (Appointment::hasSchedulingConflict(
            $shop,
            $data['shop_branch_id'] ?? null,
            \Carbon\Carbon::parse($data['scheduled_at']),
            $data['duration_minutes']
        )) {
            return response()->json([
                'success' => false,
                'message' => 'This time slot is already booked. Please choose a different time.',
            ], 409);
        }

        $appointment = $shop->appointments()->create($data);
        $appointment->load(['customer:id,name,email', 'service:id,name,base_price', 'branch:id,name', 'assignedStaff:id,name', 'jobOrder:id,order_number']);

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
        $error = null;
        $status = 200;

        if ($appointment->shop_id !== $shop->id) {
            $error = 'Unauthorized.';
            $status = 403;
        } elseif ($denied = $this->branchAccessDenied($request, $appointment)) {
            return $denied;
        } elseif ($appointment->isTerminal()) {
            $error = "A {$appointment->status} appointment cannot be modified.";
            $status = 422;
        } else {
            $user    = $request->user();
            $roles   = $user->roles->pluck('name');
            $isStaff = $roles->contains('staff') && !$roles->contains('shop_owner') && !$roles->contains('branch_manager');

            $data      = $request->validated();
            $newStatus = $data['status'] ?? null;

            // ── Role enforcement & State transition check ────────────────────────
            $error = $this->validateAndEnforceRole($appointment, $isStaff, $newStatus);
            if ($error) {
                $status = ($error === 'Staff are not authorized to perform this status change.') ? 403 : 422;
            }

            if ($isStaff) {
                // Staff cannot modify schedule or notes
                unset($data['scheduled_at'], $data['notes'], $data['assigned_staff_id']);
            }

            // ── Reschedule logic ──────────────────────────────────────────────────
            $isRescheduled = false;
            if (!$error && !empty($data['scheduled_at'])) {
                $oldAt = $appointment->scheduled_at?->format('Y-m-d H:i:s');
                $newAt = date('Y-m-d H:i:s', strtotime($data['scheduled_at']));

                if ($oldAt !== $newAt) {
                    // Only pending or confirmed appointments can be rescheduled
                    if (!in_array($appointment->status, ['pending', 'confirmed'])) {
                        $error = 'Only pending or confirmed appointments can be rescheduled.';
                        $status = 422;
                    } elseif (Appointment::hasSchedulingConflict(
                        $shop,
                        $appointment->shop_branch_id,
                        \Carbon\Carbon::parse($data['scheduled_at']),
                        $data['duration_minutes'] ?? $appointment->duration_minutes ?? 60,
                        $appointment->id
                    )) {
                        $error = 'This time slot is already booked. Please choose a different time.';
                        $status = 409;
                    } else {
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
            }

            // ── Confirm-time conflict re-check ────────────────────────────────────
            // hasSchedulingConflict() only blocks against already-CONFIRMED
            // appointments, so two customers can each hold a *pending* booking
            // for the same slot with no warning at creation time — the moment
            // either one gets confirmed, it has to be re-checked here, or the
            // owner can silently confirm both and end up double-booked with
            // nothing catching it.
            if (!$error && $newStatus === 'confirmed' && $appointment->status !== 'confirmed') {
                $checkAt = $isRescheduled ? \Carbon\Carbon::parse($data['scheduled_at']) : $appointment->scheduled_at;
                $checkDuration = $data['duration_minutes'] ?? $appointment->duration_minutes ?? 60;

                if (Appointment::hasSchedulingConflict($shop, $appointment->shop_branch_id, $checkAt, $checkDuration, $appointment->id)) {
                    $error = 'This time slot is already booked by another confirmed appointment.';
                    $status = 409;
                }
            }

            if (!$error) {
                // Perform update
                $appointment->update($data);
                $appointment->load(['customer:id,name,email', 'service:id,name,base_price', 'branch:id,name', 'assignedStaff:id,name', 'jobOrder:id,order_number']);

                // ── Notifications ─────────────────────────────────────────────────────
                $customer = $appointment->customer;
                if ($customer) {
                    if ($isRescheduled) {
                        $customer->notify(new \App\Notifications\AppointmentStatusNotification($appointment, 'rescheduled'));
                    } elseif ($newStatus && $newStatus !== $appointment->getOriginal('status')) {
                        $customer->notify(new \App\Notifications\AppointmentStatusNotification($appointment, $newStatus));
                    }
                }
            }
        }

        if ($error) {
            return response()->json(['success' => false, 'message' => $error], $status);
        }

        return response()->json([
            'success' => true,
            'data'    => $appointment,
        ]);
    }

    // ─── Complete (dedicated action with type-specific logic) ─────────────────

    public function complete(Request $request, Shop $shop, Appointment $appointment): JsonResponse
    {
        $error = null;
        $status = 200;

        if ($appointment->shop_id !== $shop->id) {
            $error = 'Unauthorized.';
            $status = 403;
        } elseif ($denied = $this->branchAccessDenied($request, $appointment)) {
            return $denied;
        } elseif ($appointment->status !== 'in_progress') {
            $error = 'Only in-progress appointments can be marked as completed.';
            $status = 422;
        } else {
            $request->validate([
                'notes'          => ['nullable', 'string', 'max:2000'],
                'job_order_id'   => ['nullable', 'exists:job_orders,id'],
                'measurement_id' => ['nullable', 'exists:measurements,id'],
                'outcome'        => ['nullable', 'string', 'in:completed,rescheduled,no_show,converted_to_job,cancelled'],
                'fitting_notes'  => ['nullable', 'string', 'max:2000'],
            ]);

            $type = $appointment->appointment_type;
            $jobOrderId = $request->job_order_id ? (int)$request->job_order_id : null;

            // Type-specific rules
            $error = $this->validateCompleteTypeRules($type, $jobOrderId);
            if ($error) {
                $status = 422;
            } else {
                // Update appointment
                $updateData = ['status' => 'completed'];
                if ($request->filled('notes')) {
                    $updateData['notes'] = $appointment->notes
                        ? $appointment->notes . "\n\n[Completion Note] " . $request->notes
                        : $request->notes;
                }
                if ($request->filled('outcome')) {
                    $updateData['outcome'] = $request->outcome;
                }
                if ($request->filled('fitting_notes')) {
                    $updateData['fitting_notes'] = $request->fitting_notes;
                }

                $appointment->update($updateData);
                $appointment->load(['customer:id,name,email', 'service:id,name,base_price', 'branch:id,name', 'assignedStaff:id,name', 'jobOrder:id,order_number']);

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
            }
        }

        if ($error) {
            return response()->json(['success' => false, 'message' => $error], $status);
        }

        return response()->json([
            'success' => true,
            'data'    => $appointment,
        ]);
    }

    private function validateAndEnforceRole(Appointment $appointment, bool $isStaff, ?string $newStatus): ?string
    {
        if ($isStaff) {
            $staffAllowed = ['in_progress', 'completed'];
            if ($newStatus && !in_array($newStatus, $staffAllowed)) {
                return 'Staff are not authorized to perform this status change.';
            }
        }

        if ($newStatus && $newStatus !== $appointment->status && !$appointment->canTransitionTo($newStatus)) {
            return "Invalid status transition: '{$appointment->status}' → '{$newStatus}'.";
        }

        return null;
    }

    private function validateCompleteTypeRules(string $type, ?int $jobOrderId): ?string
    {
        if ($type === 'fitting' && empty($jobOrderId)) {
            return 'A fitting appointment must be linked to an existing job order when completing.';
        }
        if ($type === 'pickup' && empty($jobOrderId)) {
            return 'A pickup appointment must reference the completed job order.';
        }
        return null;
    }

    // ─── Destroy (cancel — owner/manager only) ────────────────────────────────

    public function destroy(Request $request, Shop $shop, Appointment $appointment): JsonResponse
    {
        $error = null;
        $status = 200;

        if ($appointment->shop_id !== $shop->id) {
            $error = 'Unauthorized.';
            $status = 403;
        } elseif ($denied = $this->branchAccessDenied($request, $appointment)) {
            return $denied;
        } elseif ($appointment->isTerminal()) {
            $error = "A {$appointment->status} appointment cannot be cancelled.";
            $status = 422;
        } elseif (!$appointment->canTransitionTo('cancelled')) {
            $error = "Cannot cancel an appointment with status '{$appointment->status}'.";
            $status = 422;
        } else {
            $appointment->update(['status' => 'cancelled']);
            $appointment->load(['customer:id,name,email', 'service:id,name,base_price', 'branch:id,name', 'assignedStaff:id,name', 'jobOrder:id,order_number']);

            $customer = $appointment->customer;
            if ($customer) {
                $customer->notify(new \App\Notifications\AppointmentStatusNotification($appointment, 'cancelled'));
            }
        }

        if ($error) {
            return response()->json(['success' => false, 'message' => $error], $status);
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

        if ($denied = $this->branchAccessDenied($request, $appointment)) {
            return $denied;
        }

        $validated = $request->validate([
            'payment_status' => 'required|in:pending,paid,rejected',
        ]);

        $appointment->update([
            'payment_status' => $validated['payment_status']
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment status updated.',
            'data'    => $appointment->load(['customer:id,name,email', 'service:id,name,base_price', 'branch:id,name']),
        ]);
    }
}
