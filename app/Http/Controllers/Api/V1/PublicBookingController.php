<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\CatalogItem;
use App\Models\Service;
use App\Models\Store;
use App\Models\User;
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
                'appointment_types' => Appointment::PUBLIC_TYPES,
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
            'appointment_type' => ['nullable', Rule::in(Appointment::PUBLIC_TYPES)],
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
            // Where the design comes from, and a link to the customer's own file.
            'design_source' => ['nullable', Rule::in(Appointment::DESIGN_SOURCES)],
            'design_link' => ['nullable', 'url', 'max:1000'],
            // How many people it is for (the shop sets up the group from it).
            'people_count' => ['nullable', 'integer', 'min:1', 'max:500'],
            'answers' => ['nullable', 'array'],
            // Whose fabric: the shop's, or one the customer brings (with a short note about it).
            'material_source' => ['nullable', Rule::in(['shop', 'own'])],
            'material_note' => ['nullable', 'string', 'max:255'],

            // Multi-item configurable appointment request items
            // A booking is for one service (and optionally one of its designs). Another need is another booking.
            'items' => ['nullable', 'array', 'max:1'],
            'items.*.service_id' => ['nullable', Rule::exists('services', 'id')->where('store_id', $store->id)],
            'items.*.catalog_item_id' => ['nullable', Rule::exists('catalog_items', 'id')->where('store_id', $store->id)],
            'items.*.item_name' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric'],
            'items.*.selected_size' => ['nullable', 'string', 'max:50'],
            'items.*.selected_color' => ['nullable', 'string', 'max:100'],
            'items.*.add_ons' => ['nullable', 'array'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'items.max' => 'A booking is for one service. Please book another appointment for another service.',
            'scheduled_at.after_or_equal' => 'Please choose a time at least 2 hours from now.',
        ]);

        $rawItems = $validated['items'] ?? [];
        $normalizedItems = [];

        if (! empty($rawItems)) {
            foreach ($rawItems as $idx => $it) {
                $service = null;
                $catalogItem = null;

                if (! empty($it['catalog_item_id'])) {
                    $catalogItem = CatalogItem::with('service')->find($it['catalog_item_id']);
                }
                if (! empty($it['service_id'])) {
                    $service = Service::find($it['service_id']);
                } elseif ($catalogItem?->service) {
                    $service = $catalogItem->service;
                }

                $itemName = $it['item_name'] ?? $catalogItem?->name ?? $service?->name ?? 'Custom Item';
                // The service's minimum is the shop's usual one, not a wall: the booking goes to the shop, which confirms it.
                $qty = max(1, (int) ($it['quantity'] ?? 1));

                // A design costs its service's price (price is settled on the job order, so this is only the "from" price shown).
                $unitPrice = $service?->base_price;

                $normalizedItems[] = [
                    'service_id' => $service?->id ?? ($it['service_id'] ?? null),
                    'catalog_item_id' => $catalogItem?->id ?? ($it['catalog_item_id'] ?? null),
                    'item_name' => $itemName,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'selected_size' => $it['selected_size'] ?? null,
                    'selected_color' => $it['selected_color'] ?? null,
                    'add_ons' => $it['add_ons'] ?? null,
                    'notes' => $it['notes'] ?? null,
                ];
            }

            // Sync top-level service_id and catalog_item_id from first item if not explicitly provided
            if (empty($validated['service_id']) && ! empty($normalizedItems[0]['service_id'])) {
                $validated['service_id'] = $normalizedItems[0]['service_id'];
            }
            if (empty($validated['catalog_item_id']) && ! empty($normalizedItems[0]['catalog_item_id'])) {
                $validated['catalog_item_id'] = $normalizedItems[0]['catalog_item_id'];
            }
            if (empty($validated['selected_size']) && ! empty($normalizedItems[0]['selected_size'])) {
                $validated['selected_size'] = $normalizedItems[0]['selected_size'];
            }
            if (empty($validated['selected_color']) && ! empty($normalizedItems[0]['selected_color'])) {
                $validated['selected_color'] = $normalizedItems[0]['selected_color'];
            }
        } elseif (! empty($validated['service_id']) || ! empty($validated['catalog_item_id'])) {
            $catalogItem = ! empty($validated['catalog_item_id']) ? CatalogItem::with('service')->find($validated['catalog_item_id']) : null;
            $service = ! empty($validated['service_id']) ? Service::find($validated['service_id']) : $catalogItem?->service;

            $itemName = $catalogItem?->name ?? $service?->name ?? 'Custom Service';
            $minQty = (int) ($service?->min_order_qty ?? 1);
            $qty = 1;
            if ($qty < $minQty) {
                $qty = $minQty;
            }

            $normalizedItems[] = [
                'service_id' => $service?->id,
                'catalog_item_id' => $catalogItem?->id,
                'item_name' => $itemName,
                'quantity' => $qty,
                'unit_price' => $service?->base_price,
                'selected_size' => $validated['selected_size'] ?? null,
                'selected_color' => $validated['selected_color'] ?? null,
                'add_ons' => null,
                'notes' => null,
            ];
        }

        $kind = $validated['kind'];

        // Measuring or altering something needs that something: a general visit can only be a consultation or "other".
        if ($kind === 'shop' && in_array($validated['appointment_type'] ?? null, Appointment::TYPES_REQUIRING_SERVICE, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Choose the service this visit is for. A general visit can only be a consultation or something else.',
                'errors' => ['appointment_type' => ['Choose a service for a measurement or alteration visit.']],
            ], 422);
        }

        // A package stands in for its services: when none was picked, the package's first service is used.
        // A service visit needs the service or design it is for; a shop visit is none of those.
        $hasServiceLink = ! empty($validated['service_id']) || ! empty($validated['catalog_item_id']) || ! empty($normalizedItems);
        if ($kind === 'service' && ! $hasServiceLink) {
            return response()->json([
                'success' => false,
                'message' => 'Please choose the service this visit is for.',
                'errors' => ['service_id' => ['Choose a service or design for a service visit.']],
            ], 422);
        }
        if ($kind === 'shop') {
            $validated['service_id'] = $validated['catalog_item_id'] = null;
            $normalizedItems = [];
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
            if (Appointment::capacityUsedOn($store, $scheduledAt) >= $store->max_appointments_per_day) {
                return response()->json([
                    'success' => false,
                    'message' => 'This date is fully booked. Please choose another date.',
                ], 409);
            }
        }

        // A signed-in customer with no number yet or updating their number saves it here, so the shop can text them.
        if (! empty($validated['phone']) && $customer->phone !== $validated['phone']) {
            $customer->forceFill(['phone' => $validated['phone']])->save();
        }

        // ── Anti-spam / rebooking-block guards ─────────────────────────────────
        if (Appointment::isBlockedFromRebooking($store, $customer->id)) {
            return response()->json([
                'success' => false,
                'message' => 'This store is not accepting new bookings from this account. Please contact the store directly.',
            ], 403);
        }

        // A customer can legitimately have appointments for different services/designs,
        // or multiple non-overlapping visits at the same store on the same date,
        // but cannot physically be in two places at once.
        // For different stores or branches, a travel buffer is enforced.
        // For the same store & branch, strict physical time overlap is prevented.
        if (Appointment::hasCustomerScheduleConflict($customer->id, $scheduledAt, $durationMinutes, null, $store->id, $branchId)) {
            return response()->json([
                'success' => false,
                'message' => 'You already have an appointment scheduled that overlaps or is too close to this time. Please choose a different time.',
            ], 409);
        }

        // ── Create appointment (with transactional concurrency guard) ──────────
        $appointment = DB::transaction(function () use ($store, $customer, $branchId, $type, $kind, $validated, $scheduledAt, $durationMinutes, $normalizedItems) {
            if (Appointment::hasSchedulingConflict($store, $branchId, $scheduledAt, $durationMinutes, null, true)) {
                return null;
            }

            $apt = $store->appointments()->create([
                'customer_id' => $customer->id,
                'store_branch_id' => $branchId,
                'service_id' => $validated['service_id'] ?? null,
                'catalog_item_id' => $validated['catalog_item_id'] ?? null,
                'selected_size' => $validated['selected_size'] ?? null,
                'selected_color' => $validated['selected_color'] ?? null,
                'appointment_type' => $type,
                'kind' => $kind,
                'purpose_label' => in_array($type, ['other', 'alteration'], true) && ! empty($validated['purpose_label']) ? trim((string) $validated['purpose_label']) : null,
                'intake_channel' => 'online',
                'scheduled_at' => $validated['scheduled_at'],
                'duration_minutes' => $durationMinutes,
                'notes' => $validated['notes'] ?? null,
                'reference_images' => $validated['reference_images'] ?? null,
                'reference_link' => $validated['reference_link'] ?? null,
                'design_source' => $validated['design_source'] ?? null,
                'design_link' => $validated['design_link'] ?? null,
                'people_count' => $validated['people_count'] ?? null,
                'answers' => $validated['answers'] ?? null,
                'material_source' => $kind === 'service' ? ($validated['material_source'] ?? null) : null,
                'material_note' => $kind === 'service' && ($validated['material_source'] ?? null) === 'own' ? ($validated['material_note'] ?? null) : null,
                'status' => 'pending',
            ]);

            foreach ($normalizedItems as $itemData) {
                $apt->items()->create($itemData);
            }

            return $apt;
        });

        if (! $appointment) {
            return response()->json([
                'success' => false,
                'message' => 'This time slot is already reserved or currently requested. Please choose a different time.',
            ], 409);
        }

        // Eager-load items with service and catalog item for complete response
        $appointment->load([
            'items.service:id,name,service_category,base_price',
            'items.catalogItem:id,name,garment_type',
            'service:id,name,service_category',
            'catalogItem:id,name,garment_type',
            'branch:id,name,address,city',
        ]);

        // Notify store owner
        try {
            $storeOwner = $store->owner;
            if ($storeOwner) {
                $storeOwner->notify(new AppointmentBookedNotification($appointment));
            }
            // The manager of the branch it was booked at answers it, so they are told too.
            User::whereHas('staffProfile', fn ($q) => $q->where('store_id', $store->id)->where('store_branch_id', $branchId)->where('is_branch_manager', true))
                ->where('id', '!=', $storeOwner?->id)
                ->get()
                ->each(fn (User $manager) => $manager->notify(new AppointmentBookedNotification($appointment)));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to notify store owner of appointment: '.$e->getMessage());
        }

        // Also notify the customer themselves — a record of what they just
        // booked (when + why), in-app and by email, so if they forget the
        // details later they have something to check back against instead
        // of only the store owner knowing.
        try {
            $customer->notify(new AppointmentStatusNotification($appointment, 'submitted'));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to notify customer of appointment: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Appointment booked successfully. The store will confirm your booking shortly.',
            'data' => $appointment,
        ], 201);
    }
}
