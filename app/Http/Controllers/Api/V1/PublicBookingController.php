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
                'operating_hours'   => $shop->operating_hours,
                'active_special_hours' => $shop->active_special_hours,
                'special_hours'     => $shop->specialHours()->get(),
                'branches'          => $shop->branches()->get(['id', 'name', 'address', 'city']),
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
            'answers'          => ['nullable', 'array'],
            'payment_method'   => ['nullable', 'string', 'in:cash,gcash,bank_transfer'],
            'payment_reference'=> ['nullable', 'string', 'max:255'],
            'payment_receipt_path' => ['nullable', 'string', 'max:1000'],

            // Catalog Order context
            'catalog_item_id'   => ['nullable', Rule::exists('catalog_items', 'id')->where('shop_id', $shop->id)],
            'fulfillment_type'  => ['nullable', 'in:pickup,shipping,delivery'],
            'delivery_address'  => ['nullable', 'string', 'max:1000'],
            'rental_start_date' => ['nullable', 'date'],
            'rental_end_date'   => ['nullable', 'date', 'after_or_equal:rental_start_date'],
        ]);

        $type = $validated['appointment_type'];

        // ── Catalog Order validations ───────────────────────────────────────
        $catalogItem = null;
        if (!empty($validated['catalog_item_id'])) {
            $catalogItem = \App\Models\CatalogItem::find($validated['catalog_item_id']);
            if ($catalogItem) {
                if ($catalogItem->listing_type === 'for_rent') {
                    if (($validated['fulfillment_type'] ?? 'pickup') !== 'pickup') {
                        return response()->json([
                            'success' => false,
                            'message' => 'The given data was invalid.',
                            'errors'  => ['fulfillment_type' => ['For Rent items must be Pickup at Shop only.']],
                        ], 422);
                    }
                    if (empty($validated['rental_start_date']) || empty($validated['rental_end_date'])) {
                        return response()->json([
                            'success' => false,
                            'message' => 'The given data was invalid.',
                            'errors'  => ['rental_start_date' => ['Pickup Date and Return Date are required for rentals.']],
                        ], 422);
                    }
                }
            }
        }

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

        $confirmedAppointments = $shop->appointments()
            ->where('shop_branch_id', $branchId)
            ->where('status', 'confirmed')
            ->where('scheduled_at', '<', $newEnd)
            ->get(['scheduled_at', 'duration_minutes']);

        $conflict = $confirmedAppointments->contains(function (Appointment $appointment) use ($scheduledAt): bool {
            return Carbon::parse($appointment->scheduled_at)
                ->addMinutes($appointment->duration_minutes ?? 60)
                ->gt($scheduledAt);
        });

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
            'intake_channel'   => 'online',
            'scheduled_at'     => $validated['scheduled_at'],
            'duration_minutes' => $durationMinutes,
            'notes'            => $validated['notes'] ?? null,
            'answers'          => $validated['answers'] ?? null,
            'status'           => 'pending',
            'payment_method'   => $validated['payment_method'] ?? 'cash',
            'payment_reference'=> $validated['payment_reference'] ?? null,
            'payment_receipt_path' => $validated['payment_receipt_path'] ?? null,
            'payment_status'   => 'pending',
        ]);

        // ── Create Catalog Order if item was ordered ───────────────────────────
        if ($catalogItem) {
            $orderNotes = "";
            $fulfillment = $validated['fulfillment_type'] ?? 'pickup';
            if ($fulfillment === 'shipping' || $fulfillment === 'delivery') {
                $orderNotes = "Customer requested delivery. Please calculate shipping fee and notify customer.";
            }

            \App\Models\CatalogOrder::create([
                'shop_id'                 => $shop->id,
                'catalog_item_id'         => $catalogItem->id,
                'customer_id'             => $customer->id,
                'type'                    => 'online',
                'status'                  => 'pending',
                'total_amount'            => $catalogItem->price,
                'delivery_address'        => $validated['delivery_address'] ?? null,
                'payment_status'          => 'pending',
                'payment_method'          => $validated['payment_method'] ?? 'cash',
                'payment_reference'       => $validated['payment_reference'] ?? null,
                'payment_receipt_path'    => $validated['payment_receipt_path'] ?? null,
                'intake_channel'          => 'online',
                'fulfillment_type'        => $fulfillment,
                'rental_start_date'       => $validated['rental_start_date'] ?? null,
                'rental_end_date'         => $validated['rental_end_date'] ?? null,
                'security_deposit_amount' => $catalogItem->listing_type === 'for_rent' ? ($catalogItem->price * 0.5) : null, // Default 50% deposit
            ]);

            if ($orderNotes) {
                $appointment->update([
                    'notes' => ($appointment->notes ? $appointment->notes . "\n" : "") . $orderNotes
                ]);
            }
        }

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
