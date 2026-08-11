<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Shop;
use App\Models\User;
use App\Models\Role;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PublicBookingController extends Controller
{
    /**
     * Get booking settings for a shop (branches, services, policy, questions).
     */
    public function getSettings(Shop $shop): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'name'              => $shop->name,
                'description'       => $shop->description,
                'booking_policy'    => $shop->booking_policy,
                'booking_questions' => $shop->booking_questions ?? [],
                'max_appointments_per_day' => $shop->max_appointments_per_day,
                'operating_hours'   => $shop->operating_hours,
                'active_special_hours' => $shop->active_special_hours,
                'special_hours'     => $shop->specialHours()->get(),
                'branches'          => $shop->branches()->get(['id', 'slug', 'name', 'address', 'city']),
                'services'          => $shop->services()
                    ->where('is_active', true)
                    ->get(['id', 'name', 'base_price', 'estimated_days']),
                'appointment_types' => Appointment::TYPES,
            ],
        ]);
    }

    /**
     * Get public appointments for a shop (anonymous time slots).
     */
    public function getAppointments(Shop $shop): JsonResponse
    {
        // Only return confirmed appointments (or pending if you want to block pending too)
        // Returning only scheduled_at and duration_minutes to keep customer details anonymous
        $appointments = $shop->appointments()
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('scheduled_at', '>=', now()->subDay())
            ->get(['scheduled_at', 'duration_minutes', 'shop_branch_id']);

        return response()->json([
            'success' => true,
            'data'    => $appointments,
        ]);
    }

    /**
     * Submit a public appointment booking (unauthenticated customer).
     */
    public function submit(Request $request, Shop $shop): JsonResponse
    {
        $branchCount = $shop->branches()->count();

        $validated = $request->validate([
            // Customer info
            'name'             => ['required', 'string', 'max:255'],
            'email'            => ['required', 'email', 'max:255'],
            'phone'            => ['nullable', 'string', 'max:20'],

            // Booking details
            'appointment_type' => ['required', 'in:' . implode(',', Appointment::TYPES)],
            'shop_branch_id'   => $branchCount > 1
                ? ['required', Rule::exists('shop_branches', 'id')->where('shop_id', $shop->id)]
                : ['nullable', Rule::exists('shop_branches', 'id')->where('shop_id', $shop->id)],
            'service_id'       => ['nullable', Rule::exists('services', 'id')->where('shop_id', $shop->id)],
            'scheduled_at'     => ['required', 'date', 'after:now'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'notes'            => ['nullable', 'string', 'max:2000'],
            'reference_images' => ['nullable', 'array', 'max:10'],
            'reference_images.*' => ['string', 'max:1000'],
            'reference_link'   => ['nullable', 'url', 'max:500'],
            'answers'          => ['nullable', 'array'],
            'payment_method'   => ['nullable', 'string', 'in:cash,gcash,bank_transfer'],
            'payment_reference'=> ['nullable', 'string', 'max:255'],
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
                'errors'  => ['service_id' => ["Service is required for {$type} appointments."]],
            ], 422);
        }

        // ── Conditional receipt required for non-cash payments ────────────────
        // The frontend's file-input `required` attribute is satisfied the
        // instant a file is *selected*, not once the async upload actually
        // finishes — this backstop makes sure a booking can't be submitted
        // with an empty receipt path if the upload was still in flight, failed,
        // or was simply skipped for a non-cash method.
        if (
            in_array($validated['payment_method'] ?? 'cash', ['gcash', 'bank_transfer'], true)
            && empty($validated['payment_receipt_path'])
        ) {
            return response()->json([
                'success' => false,
                'message' => 'A payment receipt is required for GCash/Bank Transfer bookings.',
                'errors'  => ['payment_receipt_path' => ['Please upload your payment receipt before confirming.']],
            ], 422);
        }

        // ── Resolve branch ─────────────────────────────────────────────────────
        $branchId = $validated['shop_branch_id'] ?? null;
        if ($branchCount === 1) {
            $branchId = $shop->branches()->first()->id;
        }

        // ── Double-booking check (only against confirmed appointments) ─────────
        $scheduledAt     = Carbon::parse($validated['scheduled_at']);
        $durationMinutes = $validated['duration_minutes'] ?? 60;

        // Same "we are not open" backstop AppointmentController enforces for
        // owner-created bookings/reschedules — this public form is actually
        // the highest-volume path a booking ever comes through, so it was
        // the biggest hole left before this closure check existed anywhere.
        if ($closureTitle = $shop->closureTitleOn($scheduledAt, $branchId)) {
            return response()->json([
                'success' => false,
                'message' => "The shop is closed on this date ({$closureTitle}). Please choose a different day.",
            ], 409);
        }

        if (Appointment::hasSchedulingConflict($shop, $branchId, $scheduledAt, $durationMinutes)) {
            return response()->json([
                'success' => false,
                'message' => 'This time slot is already booked. Please choose a different time.',
            ], 409);
        }

        // ── Peak-season capacity blocker ────────────────────────────────────────
        // Once a day hits the shop's declared max, stop taking new bookings for it
        // rather than letting quality slip from overcommitting production.
        if ($shop->max_appointments_per_day) {
            $sameDayCount = $shop->appointments()
                ->whereNotIn('status', ['cancelled'])
                ->whereDate('scheduled_at', $scheduledAt->toDateString())
                ->count();

            if ($sameDayCount >= $shop->max_appointments_per_day) {
                return response()->json([
                    'success' => false,
                    'message' => 'This date is fully booked. Please choose another date.',
                ], 409);
            }
        }

        // ── Find or create customer ────────────────────────────────────────────
        $customer = User::where('email', $validated['email'])->first();

        if (!$customer) {
            $customer = User::create([
                'name'     => $validated['name'],
                'email'    => $validated['email'],
                'phone'    => $validated['phone'] ?? null,
                'password' => Hash::make(Str::random(16)),
            ]);

            $customerRole = Role::where('name', 'customer')->first();
            if ($customerRole) {
                $customer->roles()->attach($customerRole);
            }
        }

        // ── Create appointment ─────────────────────────────────────────────────
        $appointment = $shop->appointments()->create([
            'customer_id'      => $customer->id,
            'shop_branch_id'   => $branchId,
            'service_id'       => $validated['service_id'] ?? null,
            'appointment_type' => $type,
            'intake_channel'   => 'online',
            'scheduled_at'     => $validated['scheduled_at'],
            'duration_minutes' => $durationMinutes,
            'notes'            => $validated['notes'] ?? null,
            'reference_images' => $validated['reference_images'] ?? null,
            'reference_link'   => $validated['reference_link'] ?? null,
            'answers'          => $validated['answers'] ?? null,
            'status'           => 'pending',
            'payment_method'   => $validated['payment_method'] ?? 'cash',
            'payment_reference'=> $validated['payment_reference'] ?? null,
            'payment_receipt_path' => $validated['payment_receipt_path'] ?? null,
            'payment_status'   => 'pending',
        ]);

        // Notify shop owner
        $shopOwner = $shop->owner;
        if ($shopOwner) {
            $shopOwner->notify(new \App\Notifications\AppointmentBookedNotification($appointment));
        }

        return response()->json([
            'success' => true,
            'message' => 'Appointment booked successfully. The shop will confirm your booking shortly.',
            'data'    => $appointment,
        ], 201);
    }
}
