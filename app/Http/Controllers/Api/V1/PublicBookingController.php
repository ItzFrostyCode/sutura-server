<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Notifications\AppointmentBookedNotification;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PublicBookingController extends Controller
{
    /**
     * Get booking settings for a store (branches, services, policy, questions).
     */
    public function getSettings(Store $store): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'name' => $store->name,
                'description' => $store->description,
                'business_type' => $store->business_type,
                'specializations' => $store->specializations ?? [],
                // The booking form's payment step showed a hardcoded
                // placeholder ("Printify Store") regardless of which store was
                // being booked — this store's own real payment details, set
                // via SettingsBasicInfo.tsx, were never exposed here at all.
                'gcash_number' => $store->gcash_number,
                'gcash_account_name' => $store->gcash_account_name,
                'gcash_qr_path' => $store->gcash_qr_path,
                'bank_name' => $store->bank_name,
                'bank_account_number' => $store->bank_account_number,
                'bank_account_name' => $store->bank_account_name,
                'bank_qr_path' => $store->bank_qr_path,
                // Drives whether the booking form even shows a payment step
                // at all — a plain consultation/fitting request shouldn't
                // ask for a deposit unless this store actually charges one to
                // reserve the slot (the Tailor-Gated Handshake rule: booking
                // is a request, not a paid commitment — that's the JobOrder
                // downpayment's job, once staff formalizes the request).
                'fitting_fee' => $store->fitting_fee,
                'booking_policy' => $store->booking_policy,
                'booking_questions' => $store->booking_questions ?? [],
                'max_appointments_per_day' => $store->max_appointments_per_day,
                'operating_hours' => $store->operating_hours,
                'active_special_hours' => $store->active_special_hours,
                'special_hours' => $store->specialHours()->get(),
                'branches' => $store->branches()->get(['id', 'slug', 'name', 'address', 'city', 'latitude', 'longitude']),
                'services' => $store->services()
                    ->where('is_active', true)
                    ->get(['id', 'name', 'base_price', 'estimated_days']),
                'appointment_types' => Appointment::TYPES,
                'gcash_number' => $store->gcash_number,
                'gcash_account_name' => $store->gcash_account_name,
                'gcash_qr_path' => $store->gcash_qr_path,
                'bank_name' => $store->bank_name,
                'bank_account_number' => $store->bank_account_number,
                'bank_account_name' => $store->bank_account_name,
                'bank_qr_path' => $store->bank_qr_path,
            ],
        ]);
    }

    /**
     * Get public appointments for a store (anonymous time slots).
     */
    public function getAppointments(Store $store): JsonResponse
    {
        // Only return confirmed appointments (or pending if you want to block pending too)
        // Returning only scheduled_at and duration_minutes to keep customer details anonymous
        $appointments = $store->appointments()
            ->whereIn('status', ['pending', 'confirmed', 'in_progress'])
            ->where('scheduled_at', '>=', now()->subDay())
            ->get(['scheduled_at', 'duration_minutes', 'store_branch_id']);

        return response()->json([
            'success' => true,
            'data' => $appointments,
        ]);
    }

    /**
     * Submit a public appointment booking (unauthenticated customer).
     */
    public function submit(Request $request, Store $store): JsonResponse
    {
        $branchCount = $store->branches()->count();

        $validated = $request->validate([
            // Customer info
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],

            // Booking details
            'appointment_type' => ['required', 'in:'.implode(',', Appointment::TYPES)],
            'store_branch_id' => $branchCount > 1
                ? ['required', Rule::exists('store_branches', 'id')->where('store_id', $store->id)]
                : ['nullable', Rule::exists('store_branches', 'id')->where('store_id', $store->id)],
            'service_id' => ['nullable', Rule::exists('services', 'id')->where('store_id', $store->id)],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'reference_images' => ['nullable', 'array', 'max:10'],
            'reference_images.*' => ['string', 'max:1000'],
            'reference_link' => ['nullable', 'url', 'max:500'],
            'answers' => ['nullable', 'array'],
            'payment_method' => ['nullable', 'string', 'in:cash,gcash,paymaya'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'payment_receipt_path' => ['nullable', 'string', 'max:1000'],
        ]);

        $type = $validated['appointment_type'];

        // ── Conditional service_id required ───────────────────────────────────
        if (
            in_array($type, Appointment::TYPES_REQUIRING_SERVICE)
            && empty($validated['service_id'])
        ) {
            return response()->json([
                'success' => false,
                'message' => "A service must be selected for appointment type: {$type}.",
                'errors' => ['service_id' => ["Service is required for {$type} appointments."]],
            ], 422);
        }

        // ── Conditional receipt required for non-cash payments ────────────────
        // The frontend's file-input `required` attribute is satisfied the
        // instant a file is *selected*, not once the async upload actually
        // finishes — this backstop makes sure a booking can't be submitted
        // with an empty receipt path if the upload was still in flight, failed,
        // or was simply skipped for a non-cash method.
        if (
            in_array($validated['payment_method'] ?? 'cash', ['gcash', 'paymaya'], true)
            && empty($validated['payment_receipt_path'])
        ) {
            return response()->json([
                'success' => false,
                'message' => 'A payment receipt is required for GCash/PayMaya bookings.',
                'errors' => ['payment_receipt_path' => ['Please upload your payment receipt before confirming.']],
            ], 422);
        }

        // ── Resolve branch ─────────────────────────────────────────────────────
        $branchId = $validated['store_branch_id'] ?? null;
        if ($branchCount === 1) {
            $branchId = $store->branches()->first()->id;
        }

        // ── Double-booking check: checks both pending and confirmed appointments ───
        $scheduledAt = Carbon::parse($validated['scheduled_at']);
        $durationMinutes = $validated['duration_minutes'] ?? 60;

        // Same "we are not open" backstop AppointmentController enforces for
        // owner-created bookings/reschedules
        if ($closureTitle = $store->closureTitleOn($scheduledAt, $branchId)) {
            return response()->json([
                'success' => false,
                'message' => "The store is closed on this date ({$closureTitle}). Please choose a different day.",
            ], 409);
        }

        // For public storefront bookings, any slot held by a confirmed or pending
        // appointment is locked to eliminate online-vs-online collisions.
        if (Appointment::hasSchedulingConflict($store, $branchId, $scheduledAt, $durationMinutes, null, true)) {
            return response()->json([
                'success' => false,
                'message' => 'This time slot is already reserved or currently requested. Please choose a different time.',
            ], 409);
        }

        // ── Peak-season capacity blocker ────────────────────────────────────────
        // Once a day hits the store's declared max, stop taking new bookings for it
        // rather than letting quality slip from overcommitting production.
        if ($store->max_appointments_per_day) {
            $sameDayCount = $store->appointments()
                ->whereNotIn('status', ['cancelled'])
                ->whereDate('scheduled_at', $scheduledAt->toDateString())
                ->count();

            if ($sameDayCount >= $store->max_appointments_per_day) {
                return response()->json([
                    'success' => false,
                    'message' => 'This date is fully booked. Please choose another date.',
                ], 409);
            }
        }

        // ── Find or create customer ────────────────────────────────────────────
        $customer = User::where('email', $validated['email'])->first();

        if (! $customer) {
            $customer = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make(Str::random(16)),
            ]);

            $customerRole = Role::where('name', 'customer')->first();
            if ($customerRole) {
                $customer->roles()->attach($customerRole);
            }
        }

        // ── Anti-spam / rebooking-block guards ─────────────────────────────────
        // Only meaningful for a customer with prior history at this store — a
        // brand-new account (just created above) can't trip either check.
        if (Appointment::isBlockedFromRebooking($store, $customer->id)) {
            return response()->json([
                'success' => false,
                'message' => 'This store is not accepting new bookings from this account. Please contact the store directly.',
            ], 403);
        }

        if (Appointment::hasActiveAppointment($store, $customer->id)) {
            return response()->json([
                'success' => false,
                'message' => 'You already have an active appointment at this store. Cancel it first if you want to book a different date.',
            ], 409);
        }

        // ── Associate customer with this store ─────────────────────────────────
        // Ensures public-booked customers appear in the store's customer
        // list / CRM (CustomerController::index reads store_customers).
        // Using attach() with skipIfAttached avoids duplicating the pivot
        // row if this customer already booked or was added manually.
        if (! $store->customers()->where('user_id', $customer->id)->exists()) {
            $store->customers()->attach($customer->id);
        }

        // ── Create appointment (with transactional concurrency guard) ──────────
        $appointment = DB::transaction(function () use ($store, $customer, $branchId, $type, $validated, $scheduledAt, $durationMinutes) {
            if (Appointment::hasSchedulingConflict($store, $branchId, $scheduledAt, $durationMinutes, null, true)) {
                return null;
            }

            return $store->appointments()->create([
                'customer_id' => $customer->id,
                'store_branch_id' => $branchId,
                'service_id' => $validated['service_id'] ?? null,
                'appointment_type' => $type,
                'intake_channel' => 'online',
                'scheduled_at' => $validated['scheduled_at'],
                'duration_minutes' => $durationMinutes,
                'notes' => $validated['notes'] ?? null,
                'reference_images' => $validated['reference_images'] ?? null,
                'reference_link' => $validated['reference_link'] ?? null,
                'answers' => $validated['answers'] ?? null,
                'status' => 'pending',
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'payment_reference' => $validated['payment_reference'] ?? null,
                'payment_receipt_path' => $validated['payment_receipt_path'] ?? null,
                'payment_status' => 'pending',
            ]);
        });

        if (! $appointment) {
            return response()->json([
                'success' => false,
                'message' => 'This time slot is already reserved or currently requested. Please choose a different time.',
            ], 409);
        }

        // Notify store owner
        $storeOwner = $store->owner;
        if ($storeOwner) {
            $storeOwner->notify(new AppointmentBookedNotification($appointment));
        }

        return response()->json([
            'success' => true,
            'message' => 'Appointment booked successfully. The store will confirm your booking shortly.',
            'data' => $appointment,
        ], 201);
    }
}
