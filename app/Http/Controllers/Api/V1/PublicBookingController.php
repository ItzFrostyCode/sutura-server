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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

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
                'branches'          => $shop->branches()->get(['id', 'name', 'address', 'city']),
                'services'          => $shop->services()
                    ->where('is_active', true)
                    ->get(['id', 'name', 'base_price', 'estimated_days']),
                'appointment_types' => Appointment::TYPES,
            ],
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
                ? ['required', 'exists:shop_branches,id']
                : ['nullable', 'exists:shop_branches,id'],
            'service_id'       => ['nullable', 'exists:services,id'],
            'scheduled_at'     => ['required', 'date', 'after:now'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'notes'            => ['nullable', 'string', 'max:2000'],
            'answers'          => ['nullable', 'array'],
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

        // ── Resolve branch ─────────────────────────────────────────────────────
        $branchId = $validated['shop_branch_id'] ?? null;
        if ($branchCount === 1) {
            $branchId = $shop->branches()->first()->id;
        }

        // ── Double-booking check (only against confirmed appointments) ─────────
        $scheduledAt     = Carbon::parse($validated['scheduled_at']);
        $durationMinutes = $validated['duration_minutes'] ?? 60;
        $newEnd          = $scheduledAt->copy()->addMinutes($durationMinutes);

        $conflict = $shop->appointments()
            ->where('shop_branch_id', $branchId)
            ->where('status', 'confirmed')
            ->where('scheduled_at', '<', $newEnd)
            ->whereRaw(
                "DATE_ADD(scheduled_at, INTERVAL duration_minutes MINUTE) > ?",
                [$scheduledAt]
            )
            ->exists();

        if ($conflict) {
            return response()->json([
                'success' => false,
                'message' => 'This time slot is already booked. Please choose a different time.',
            ], 409);
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
            'scheduled_at'     => $validated['scheduled_at'],
            'duration_minutes' => $durationMinutes,
            'notes'            => $validated['notes'] ?? null,
            'answers'          => $validated['answers'] ?? null,
            'status'           => 'pending',
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
