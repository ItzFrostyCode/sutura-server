<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\Store;
use App\Notifications\AppointmentBookedNotification;
use App\Notifications\AppointmentStatusNotification;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                'booking_policy' => $store->booking_policy,
                'booking_questions' => $store->booking_questions ?? [],
                'max_appointments_per_day' => $store->max_appointments_per_day,
                'operating_hours' => $store->operating_hours,
                'active_special_hours' => $store->active_special_hours,
                'special_hours' => $store->specialHours()->get(),
                'branches' => $store->branches()->live()->get(['id', 'slug', 'name', 'address', 'barangay', 'city', 'latitude', 'longitude']),
                // service_types/min_order_qty/custom_fields let the booking
                // wizard pick purposes and roster columns from the real
                // functional taxonomy instead of guessing from the name.
                'services' => $store->services()
                    ->where('is_active', true)
                    ->get(['id', 'name', 'base_price', 'estimated_days', 'service_types', 'min_order_qty', 'custom_fields']),
                // Fitting and pickup are made by the system for a production order, never booked.
                'appointment_types' => Appointment::BOOKABLE_TYPES,
                'appointment_kinds' => Appointment::BOOKABLE_KINDS,
                // One duration table for online and walk-in; the form only shows the length.
                'durations' => Appointment::TYPE_DEFAULT_DURATIONS,
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
            ->where(fn ($q) => $q->where('kind', '!=', 'production')->orWhere('status', '!=', 'pending'))
            ->where('scheduled_at', '>=', now()->subDay())
            ->get(['scheduled_at', 'duration_minutes', 'store_branch_id']);

        return response()->json([
            'success' => true,
            'data' => $appointments,
        ]);
    }

    /**
     * Book an appointment online. The customer must be signed in: the booking goes on their own account
     * (no typed name or email). It waits as `pending` until the shop accepts it. No payment is taken here;
     * the first payment is the production order's deposit.
     */
    public function submit(Request $request, Store $store): JsonResponse
    {
        $customer = $request->user();

        $branchCount = $store->branches()->live()->count();
        if ($branchCount === 0) {
            return response()->json(['success' => false, 'message' => 'This shop has no verified branch to book at yet.'], 422);
        }

        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'max:20'],

            // Booking details
            'kind' => ['required', Rule::in(Appointment::BOOKABLE_KINDS)],
            // Fitting and pickup are not bookable: the system makes them for a production order.
            'appointment_type' => ['nullable', Rule::in(Appointment::BOOKABLE_TYPES)],
            'purpose_label' => ['nullable', 'string', 'max:60', 'required_if:appointment_type,other'],
            'store_branch_id' => $branchCount > 1
                ? ['required', Rule::exists('store_branches', 'id')->where('store_id', $store->id)->where('status', 'active')->where('verification_status', 'verified')]
                : ['nullable', Rule::exists('store_branches', 'id')->where('store_id', $store->id)->where('status', 'active')->where('verification_status', 'verified')],
            'service_id' => ['nullable', Rule::exists('services', 'id')->where('store_id', $store->id)],
            // The catalog design this booking came from (the "Book an
            // Appointment" button on a design's page), scoped to this store.
            'catalog_item_id' => ['nullable', Rule::exists('catalog_items', 'id')->where('store_id', $store->id)],
            'selected_size' => ['nullable', 'string', 'max:50'],
            'selected_color' => ['nullable', 'string', 'max:100'],
            'scheduled_at' => ['required', 'date', 'after_or_equal:'.now()->addMinutes(Appointment::MIN_BOOKING_LEAD_MINUTES)->toDateTimeString()],
            'notes' => ['nullable', 'string', 'max:2000'],
            'reference_images' => ['nullable', 'array', 'max:10'],
            'reference_images.*' => ['string', 'max:1000'],
            'reference_link' => ['nullable', 'url', 'max:500'],
            'answers' => ['nullable', 'array'],
        ], [
            'scheduled_at.after_or_equal' => 'Please choose a time at least 2 hours from now.',
        ]);

        $kind = $validated['kind'];

        // A package stands in for its services: when none was picked, the package's first service is used.
        // A service visit needs the service or design it is for; a shop visit is none of those.
        $hasServiceLink = ! empty($validated['service_id']) || ! empty($validated['catalog_item_id']);
        if ($kind === 'service' && ! $hasServiceLink) {
            return response()->json([
                'success' => false,
                'message' => 'Please choose the service this visit is for.',
                'errors' => ['service_id' => ['Choose a service or design for a service visit.']],
            ], 422);
        }
        if ($kind === 'shop') {
            $validated['service_id'] = $validated['catalog_item_id'] = null;
        }

        // The purpose follows the service for a service visit; a shop visit is what the customer picked.
        $serviceCategory = $validated['service_id'] ? Service::find($validated['service_id'])?->service_category : null;
        $type = Appointment::purposeFor($kind, $validated['appointment_type'] ?? null, $serviceCategory);
        if ($type === 'other' && trim((string) ($validated['purpose_label'] ?? '')) === '') {
            return response()->json([
                'success' => false,
                'message' => 'Please say what the visit is for.',
                'errors' => ['purpose_label' => ['Tell the shop what the visit is for.']],
            ], 422);
        }

        // ── Resolve branch ─────────────────────────────────────────────────────
        $branchId = $validated['store_branch_id'] ?? null;
        if ($branchCount === 1) {
            $branchId = $store->branches()->live()->first()->id;
        }

        // ── Double-booking check: checks both pending and confirmed appointments ───
        $scheduledAt = Carbon::parse($validated['scheduled_at']);
        // One duration table for online and walk-in (the form no longer sends its own).
        $durationMinutes = Appointment::TYPE_DEFAULT_DURATIONS[$type] ?? 60;

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
                ->where('kind', '!=', 'production')
                ->whereDate('scheduled_at', $scheduledAt->toDateString())
                ->count();

            if ($sameDayCount >= $store->max_appointments_per_day) {
                return response()->json([
                    'success' => false,
                    'message' => 'This date is fully booked. Please choose another date.',
                ], 409);
            }
        }

        // A signed-in customer with no number yet can add one here, so the shop can text them.
        if (! empty($validated['phone']) && ! $customer->phone) {
            $customer->forceFill(['phone' => $validated['phone']])->save();
        }

        // ── Anti-spam / rebooking-block guards ─────────────────────────────────
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

        // A customer can legitimately have appointments at several different
        // stores/services, but not too close together in time — they can't
        // physically be in two shops at once, and can't teleport between
        // them either. Checked platform-wide (not just against $store) and
        // buffered by Appointment::CUSTOMER_TRAVEL_BUFFER_MINUTES on both
        // sides, not just a bare time-overlap check — see
        // hasCustomerScheduleConflict()'s own docblock.
        if (Appointment::hasCustomerScheduleConflict($customer->id, $scheduledAt, $durationMinutes)) {
            return response()->json([
                'success' => false,
                'message' => 'This time is too close to another appointment you already have. Please leave at least '.Appointment::CUSTOMER_TRAVEL_BUFFER_MINUTES.' minutes between appointments so you have time to travel.',
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
        $appointment = DB::transaction(function () use ($store, $customer, $branchId, $type, $kind, $validated, $scheduledAt, $durationMinutes) {
            if (Appointment::hasSchedulingConflict($store, $branchId, $scheduledAt, $durationMinutes, null, true)) {
                return null;
            }

            return $store->appointments()->create([
                'customer_id' => $customer->id,
                'store_branch_id' => $branchId,
                'service_id' => $validated['service_id'] ?? null,
                'catalog_item_id' => $validated['catalog_item_id'] ?? null,
                'selected_size' => $validated['selected_size'] ?? null,
                'selected_color' => $validated['selected_color'] ?? null,
                'appointment_type' => $type,
                'kind' => $kind,
                'purpose_label' => $type === 'other' ? trim((string) ($validated['purpose_label'] ?? '')) : null,
                'intake_channel' => 'online',
                'scheduled_at' => $validated['scheduled_at'],
                'duration_minutes' => $durationMinutes,
                'notes' => $validated['notes'] ?? null,
                'reference_images' => $validated['reference_images'] ?? null,
                'reference_link' => $validated['reference_link'] ?? null,
                'answers' => $validated['answers'] ?? null,
                'status' => 'pending',
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

        // Also notify the customer themselves — a record of what they just
        // booked (when + why), in-app and by email, so if they forget the
        // details later they have something to check back against instead
        // of only the store owner knowing.
        $customer->notify(new AppointmentStatusNotification($appointment, 'submitted'));

        return response()->json([
            'success' => true,
            'message' => 'Appointment booked successfully. The store will confirm your booking shortly.',
            'data' => $appointment,
        ], 201);
    }
}
