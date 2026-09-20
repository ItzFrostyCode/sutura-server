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

    /**
     * A shop_special_hours row with is_closed=true covering the requested
     * date is an explicit, unambiguous "we are not open" announcement —
     * unlike regular weekly operating_hours (where an early/late exception
     * might be legitimate), there's no reason to let a booking land on a
     * day the shop deliberately announced as closed. Previously this data
     * only ever reached the frontend for display (customer booking page,
     * owner's special-hours card) — nothing on the backend ever checked it,
     * so a request bypassing that UI, or a walk-in the owner books
     * themselves without noticing their own closure notice, went through
     * anyway.
     */
    private function fallsOnAnnouncedClosure(Shop $shop, \Carbon\Carbon $scheduledAt, ?int $branchId = null): ?string
    {
        return $shop->closureTitleOn($scheduledAt, $branchId);
    }

    /**
     * Notifies the shop owner's own in-app bell whenever staff or a branch
     * manager makes a change on their behalf — mirrors
     * JobOrderController::notifyOwnerOfActivity() exactly, for the same
     * reason: the owner performing the change themselves already knows what
     * they just did, so this only fires for other actors.
     */
    private function notifyOwnerOfActivity(Request $request, Shop $shop, array $payload): void
    {
        if ($request->user()->hasRole('shop_owner')) {
            return;
        }

        $owner = $shop->owner;
        if (!$owner) {
            return;
        }

        $owner->notify(new \App\Notifications\ShopActivityNotification(
            $payload['type'],
            $payload['title'],
            $payload['message'],
            $payload['url'],
            $payload['extra'] ?? []
        ));
    }

    /**
     * Cross-shop "My Appointments" for whoever is logged in — the customer
     * counterpart to index() above, which is shop-scoped and staff/owner
     * only. Mirrors JobOrderTrackingController::myOrders()'s same pattern
     * and safe field subset (no internal notes, no staff assignment). No
     * role gate: filters by the caller's own id as customer_id.
     */
    public function myAppointments(Request $request): JsonResponse
    {
        $appointments = Appointment::where('customer_id', $request->user()->id)
            ->with(['shop:id,name,slug,logo_path', 'branch:id,name,address,city', 'service:id,name'])
            ->orderByDesc('scheduled_at')
            ->paginate($request->input('per_page', 20));

        $appointments->getCollection()->transform(fn (Appointment $appointment) => [
            'id'                => $appointment->id,
            'appointment_type'  => $appointment->appointment_type,
            'intake_channel'    => $appointment->intake_channel,
            'status'            => $appointment->status,
            'scheduled_at'      => $appointment->scheduled_at,
            'duration_minutes'  => $appointment->duration_minutes,
            'service_name'      => $appointment->service?->name,
            'payment_status'    => $appointment->payment_status,
            'cancellation_reason' => $appointment->cancellation_reason,
            'rebooking_blocked' => (bool) $appointment->rebooking_blocked,
            'shop'              => $appointment->shop ? [
                'name'      => $appointment->shop->name,
                'slug'      => $appointment->shop->slug,
                'logo_path' => $appointment->shop->logo_path,
            ] : null,
            'branch' => $appointment->branch ? [
                'name'    => $appointment->branch->name,
                'address' => $appointment->branch->address,
                'city'    => $appointment->branch->city,
            ] : null,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $appointments->items(),
            'meta'    => [
                'current_page' => $appointments->currentPage(),
                'last_page'    => $appointments->lastPage(),
                'total'        => $appointments->total(),
            ],
        ]);
    }

    /**
     * Customer cancels their own appointment — distinct from destroy() below
     * (owner/manager only). No reason required (that's only meaningful when
     * the *shop* cancels on a customer), and never touches
     * rebooking_blocked — cancelling your own booking never blocks you from
     * booking again.
     */
    public function cancelMine(Request $request, Appointment $appointment): JsonResponse
    {
        if ($appointment->customer_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        if (!$appointment->canTransitionTo('cancelled')) {
            return response()->json([
                'success' => false,
                'message' => "A {$appointment->status} appointment cannot be cancelled.",
            ], 422);
        }

        $appointment->update(['status' => 'cancelled']);

        $shop = $appointment->shop;
        if ($shop) {
            $this->notifyOwnerOfActivity($request, $shop, [
                'type'    => 'appointment_cancelled',
                'title'   => 'Appointment Cancelled',
                'message' => "{$request->user()->name} cancelled their own appointment.",
                'url'     => '/dashboard/appointments',
                'extra'   => ['appointment_id' => $appointment->id],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Appointment cancelled.',
            'data'    => $appointment,
        ]);
    }

    /**
     * Single-appointment detail for the customer's own "view full detail"
     * screen — same customer-scoped ownership check and safe field subset
     * as myAppointments(), just one row with a bit more (duration, payment
     * status, full branch address, and the customer's own notes/reference
     * images from booking — their own submitted data, safe to show back).
     */
    public function myAppointmentDetail(Request $request, Appointment $appointment): JsonResponse
    {
        if ($appointment->customer_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Appointment not found.'], 404);
        }

        $appointment->load(['shop:id,name,slug,logo_path', 'branch:id,name,address,city', 'service:id,name']);

        return response()->json([
            'success' => true,
            'data' => [
                'id'                  => $appointment->id,
                'appointment_type'    => $appointment->appointment_type,
                'intake_channel'      => $appointment->intake_channel,
                'status'              => $appointment->status,
                'scheduled_at'        => $appointment->scheduled_at,
                'duration_minutes'    => $appointment->duration_minutes,
                'service_name'        => $appointment->service?->name,
                'payment_status'      => $appointment->payment_status,
                'payment_method'      => $appointment->payment_method,
                'notes'               => $appointment->notes,
                'reference_link'      => $appointment->reference_link,
                'cancellation_reason' => $appointment->cancellation_reason,
                'rebooking_blocked'   => (bool) $appointment->rebooking_blocked,
                'shop'                => $appointment->shop ? [
                    'name'      => $appointment->shop->name,
                    'slug'      => $appointment->shop->slug,
                    'logo_path' => $appointment->shop->logo_path,
                ] : null,
                'branch' => $appointment->branch ? [
                    'name'    => $appointment->branch->name,
                    'address' => $appointment->branch->address,
                    'city'    => $appointment->branch->city,
                ] : null,
            ],
        ]);
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
            $query->where(function ($q) use ($branchId) {
                $q->where('shop_branch_id', $branchId)
                  ->orWhereNull('shop_branch_id');
            });
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
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
        // In-store entries created by owner/manager/staff are walk-ins and auto-confirmed
        // so the slot is reserved and locked immediately (online bookings arrive as 'pending').
        $data['status']           = 'confirmed';
        $data['intake_channel']   = 'walk_in';

        $scheduledAt = \Carbon\Carbon::parse($data['scheduled_at']);

        if ($closureTitle = $this->fallsOnAnnouncedClosure($shop, $scheduledAt, $data['shop_branch_id'] ?? null)) {
            return response()->json([
                'success' => false,
                'message' => "The shop is closed on this date ({$closureTitle}). Please choose a different day.",
            ], 409);
        }

        // Walk-in priority: Walk-ins are checked against confirmed/in_progress appointments only.
        // A physical walk-in customer is the source of truth ("kung sino ang makauna").
        if (Appointment::hasSchedulingConflict(
            $shop,
            $data['shop_branch_id'] ?? null,
            $scheduledAt,
            $data['duration_minutes'],
            null,
            false // only confirmed/in_progress block a walk-in
        )) {
            return response()->json([
                'success' => false,
                'message' => 'This time slot is already booked by another confirmed appointment. Please choose a different time.',
            ], 409);
        }

        // Query any overlapping pending online bookings before creating the walk-in
        $overlappingPending = Appointment::getOverlappingPendingAppointments(
            $shop,
            $data['shop_branch_id'] ?? null,
            $scheduledAt,
            $data['duration_minutes']
        );

        $appointment = $shop->appointments()->create($data);
        $appointment->load(['customer:id,name,email', 'service:id,name,base_price', 'branch:id,name', 'assignedStaff:id,name', 'jobOrder:id,order_number']);

        // Walk-in claims the slot: preempt all overlapping pending online bookings
        $preemptedCount = 0;
        foreach ($overlappingPending as $pendingAppt) {
            $pendingAppt->preemptByWalkIn($appointment, $request->user());
            $preemptedCount++;
        }

        // Fitting session limit — same enforcement as the auto-generated
        // "Ready for Fitting" appointment in JobOrderController@update. A
        // manually-booked extra fitting on an already-existing job counts
        // against the shop's fitting_limit too, not just the automatic one.
        if ($data['appointment_type'] === 'fitting' && !empty($data['job_order_id'])) {
            $jobOrder = \App\Models\JobOrder::find($data['job_order_id']);
            if ($jobOrder) {
                $priorFittingCount = $jobOrder->appointments()
                    ->where('appointment_type', 'fitting')
                    ->where('id', '!=', $appointment->id)
                    ->count();
                if ($shop->fitting_limit && $priorFittingCount >= $shop->fitting_limit && $shop->fitting_fee > 0) {
                    $jobOrder->increment('total_amount', $shop->fitting_fee);
                    $jobOrder->increment('balance', $shop->fitting_fee);
                    if ($jobOrder->payment_status === 'paid') {
                        $jobOrder->update(['payment_status' => 'partial']);
                    }
                }
            }
        }

        // Notify shop owner of new booking
        $shopOwner = $shop->owner;
        if ($shopOwner) {
            $shopOwner->notify(new \App\Notifications\AppointmentBookedNotification($appointment));
        }

        return response()->json([
            'success'         => true,
            'message'         => $preemptedCount > 0
                ? "Walk-in appointment confirmed. {$preemptedCount} pending online booking(s) for this slot were notified to reschedule."
                : 'Appointment created successfully.',
            'preempted_count' => $preemptedCount,
            'data'            => $appointment->load(['customer:id,name,email', 'service:id,name,base_price', 'branch:id,name', 'assignedStaff:id,name', 'jobOrder:id,order_number']),
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
                    } elseif ($closureTitle = $this->fallsOnAnnouncedClosure($shop, \Carbon\Carbon::parse($data['scheduled_at']), $appointment->shop_branch_id)) {
                        $error = "The shop is closed on this date ({$closureTitle}). Please choose a different day.";
                        $status = 409;
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

                        // Visible reschedule note so UI/UX and owner always have concrete proof
                        $rescheduleTag = '[Rescheduled from ' . date('M j, Y g:i A', strtotime($oldAt)) . ']';
                        if (empty($data['notes'])) {
                            $data['notes'] = $rescheduleTag . ($appointment->notes ? "\n" . $appointment->notes : '');
                        } elseif (!str_contains($data['notes'], $rescheduleTag)) {
                            $data['notes'] = $rescheduleTag . "\n" . $data['notes'];
                        }
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

                if (Appointment::hasSchedulingConflict($shop, $appointment->shop_branch_id, $checkAt, $checkDuration, $appointment->id, false)) {
                    $error = 'This time slot is already booked by another confirmed appointment. Please reschedule before confirming.';
                    $status = 409;
                }
            }

            if (!$error) {
                // Perform update
                $appointment->update($data);

                // If confirmed, preempt any other overlapping pending requests on this slot
                if ($newStatus === 'confirmed') {
                    $otherPending = Appointment::getOverlappingPendingAppointments(
                        $shop,
                        $appointment->shop_branch_id,
                        $appointment->scheduled_at,
                        $appointment->duration_minutes ?? 60,
                        $appointment->id
                    );
                    foreach ($otherPending as $other) {
                        $other->preemptByWalkIn($appointment, $user);
                    }
                }

                // reminder_sent_at (server-derived, not fillable — see
                // RemindUpcomingAppointments) tracks whether the ~24h-ahead
                // reminder already fired for this appointment's *time slot*.
                // A reschedule doesn't change that flag on its own, so a
                // customer reminded once, then rescheduled, would silently
                // never get reminded again for the new time — the reminder
                // command's whereNull('reminder_sent_at') check would just
                // keep skipping it. Reset it so the new time gets its own
                // reminder pass.
                if ($isRescheduled && $appointment->reminder_sent_at) {
                    $appointment->forceFill(['reminder_sent_at' => null])->save();
                }

                $appointment->load(['customer:id,name,email', 'service:id,name,base_price', 'branch:id,name', 'assignedStaff:id,name', 'jobOrder:id,order_number']);

                // ── Notifications ─────────────────────────────────────────────────────
                $customer = $appointment->customer;
                if ($customer) {
                    if ($isRescheduled) {
                        $customer->notify(new \App\Notifications\AppointmentStatusNotification($appointment, 'rescheduled'));
                        $this->notifyOwnerOfActivity($request, $shop, [
                            'type'    => 'appointment_rescheduled',
                            'title'   => 'Appointment Rescheduled',
                            'message' => "Appointment for {$customer->name} was rescheduled.",
                            'url'     => '/dashboard/appointments',
                            'extra'   => ['appointment_id' => $appointment->id],
                        ]);
                    } elseif ($newStatus && $newStatus !== $appointment->getOriginal('status')) {
                        $customer->notify(new \App\Notifications\AppointmentStatusNotification($appointment, $newStatus));
                        $this->notifyOwnerOfActivity($request, $shop, [
                            'type'    => 'appointment_' . $newStatus,
                            'title'   => 'Appointment Updated',
                            'message' => "Appointment for {$customer->name} is now {$newStatus}.",
                            'url'     => '/dashboard/appointments',
                            'extra'   => ['appointment_id' => $appointment->id],
                        ]);
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
                'job_order_id'   => ['nullable', \Illuminate\Validation\Rule::exists('job_orders', 'id')->where('shop_id', $shop->id)],
                'measurement_id' => ['nullable', \Illuminate\Validation\Rule::exists('measurements', 'id')->where('shop_id', $shop->id)],
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

                // fitting_notes was previously captured (if at all) only on
                // the appointment itself — invisible to whoever actually does
                // Final Adjustments on the linked job, since they work from
                // the Job Detail page, not the appointment record. Attaching
                // it directly to the job's own notes is what actually gets it
                // in front of the tailor doing the work.
                if ($request->filled('fitting_notes') && $jobOrderId) {
                    $jobOrder = \App\Models\JobOrder::find($jobOrderId);
                    if ($jobOrder) {
                        $jobOrder->update([
                            'notes' => trim(($jobOrder->notes ? $jobOrder->notes . "\n\n" : '')
                                . '[Fitting Notes — ' . now()->format('M j, Y') . '] ' . $request->fitting_notes),
                        ]);
                    }
                }
                $appointment->load(['customer:id,name,email', 'service:id,name,base_price', 'branch:id,name', 'assignedStaff:id,name', 'jobOrder:id,order_number']);

                // Notify customer
                $customer = $appointment->customer;
                if ($customer) {
                    $customer->notify(new \App\Notifications\AppointmentStatusNotification($appointment, 'completed'));
                    $this->notifyOwnerOfActivity($request, $shop, [
                        'type'    => 'appointment_completed',
                        'title'   => 'Appointment Completed',
                        'message' => "Appointment for {$customer->name} was marked completed.",
                        'url'     => '/dashboard/appointments',
                        'extra'   => ['appointment_id' => $appointment->id],
                    ]);
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
            // The shop is cancelling on the customer — unlike cancelMine()
            // above, the customer deserves to know why, so a reason is
            // required here. block_rebooking defaults to false (the
            // customer may still book again); the shop opts in to blocking.
            $validated = $request->validate([
                'reason'          => ['required', 'string', 'max:2000'],
                'block_rebooking' => ['nullable', 'boolean'],
            ]);

            $appointment->update([
                'status'              => 'cancelled',
                'cancellation_reason' => $validated['reason'],
                'rebooking_blocked'   => $validated['block_rebooking'] ?? false,
            ]);
            $appointment->load(['customer:id,name,email', 'service:id,name,base_price', 'branch:id,name', 'assignedStaff:id,name', 'jobOrder:id,order_number']);

            $customer = $appointment->customer;
            if ($customer) {
                $customer->notify(new \App\Notifications\AppointmentStatusNotification($appointment, 'cancelled'));
                $this->notifyOwnerOfActivity($request, $shop, [
                    'type'    => 'appointment_cancelled',
                    'title'   => 'Appointment Cancelled',
                    'message' => "Appointment for {$customer->name} was cancelled.",
                    'url'     => '/dashboard/appointments',
                    'extra'   => ['appointment_id' => $appointment->id],
                ]);
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

        $oldPaymentStatus = $appointment->payment_status;

        $appointment->update([
            'payment_status' => $validated['payment_status']
        ]);

        // Previously silent either way — the customer had no signal their
        // deposit/receipt was even reviewed, paid or rejected.
        if (in_array($validated['payment_status'], ['paid', 'rejected'], true) && $validated['payment_status'] !== $oldPaymentStatus) {
            $appointment->customer?->notify(new \App\Notifications\AppointmentPaymentStatusNotification($appointment, $validated['payment_status']));
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment status updated.',
            'data'    => $appointment->load(['customer:id,name,email', 'service:id,name,base_price', 'branch:id,name']),
        ]);
    }
}
