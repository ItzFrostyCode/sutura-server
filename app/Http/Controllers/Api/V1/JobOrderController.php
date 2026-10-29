<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreJobOrderRequest;
use App\Http\Requests\Store\UpdateJobOrderRequest;
use App\Models\Appointment;
use App\Models\CatalogItem;
use App\Models\JobOrder;
use App\Models\Measurement;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Service;
use App\Models\ServicePricing;
use App\Models\StaffProfile;
use App\Models\Store;
use App\Rules\SafeLink;
use App\Support\DesignPricing;
use App\Support\JobMeasurementSync;
use App\Support\JobOrderPurger;
use App\Support\OrderNumber;
use App\Support\OrderReadiness;
use App\Support\OrderRequirements;
use App\Support\SafeNotify;
use App\Support\StaffJobUpdateGuard;
use App\Models\User;
use App\Notifications\AppointmentStatusNotification;
use App\Notifications\CustomerPaymentRejectedNotification;
use App\Notifications\CustomMessageNotification;
use App\Notifications\JobStatusUpdatedNotification;
use App\Notifications\NewJobOrderNotification;
use App\Notifications\OrderReadyNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Notifications\PaymentRejectedNotification;
use App\Notifications\StoreActivityNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class JobOrderController extends Controller
{
    /**
     * Branch managers/staff pinned to a branch may only act on job orders
     * that belong to that same branch (store_owner is unrestricted). Jobs or
     * users with no branch assigned (single-branch stores) are never blocked.
     */
    private function branchAccessDenied(Request $request, JobOrder $jobOrder): ?JsonResponse
    {
        $user = $request->user();
        if ($user->hasRole('store_owner')) {
            return null;
        }

        $userBranchId = $user->staffProfile->store_branch_id ?? null;

        if ($userBranchId && $jobOrder->store_branch_id && (int) $userBranchId !== (int) $jobOrder->store_branch_id) {
            return response()->json(['success' => false, 'message' => 'This job order belongs to a different branch.'], 403);
        }

        return null;
    }

    /**
     * Notifies the store owner's own in-app bell — and, when the activity
     * happened on a specific branch, that branch's own manager too, not just
     * the owner — whenever staff or a branch manager makes a change on their
     * behalf. The owner performing the change themselves already knows what
     * they just did, so this only fires for other actors; the acting branch
     * manager is likewise never notified of their own action.
     */
    private function notifyOwnerOfActivity(Request $request, Store $store, array $payload, ?JobOrder $jobOrder = null): void
    {
        $actor = $request->user();

        if (! $actor->hasRole('store_owner')) {
            $owner = $store->owner;
            if ($owner) {
                $owner->notify(new StoreActivityNotification(
                    $payload['type'],
                    $payload['title'],
                    $payload['message'],
                    $payload['url'],
                    $payload['extra'] ?? []
                ));
            }
        }

        if ($jobOrder?->store_branch_id) {
            $branchManagers = User::whereHas('staffProfile', function ($q) use ($store, $jobOrder) {
                $q->where('store_id', $store->id)
                    ->where('store_branch_id', $jobOrder->store_branch_id)
                    ->where('is_branch_manager', true);
            })->where('id', '!=', $actor->id)->get();

            foreach ($branchManagers as $manager) {
                $manager->notify(new StoreActivityNotification(
                    $payload['type'],
                    $payload['title'],
                    $payload['message'],
                    $payload['url'],
                    $payload['extra'] ?? []
                ));
            }
        }
    }

    /**
     * "ORD-0001", "ORD-0002", … — the store's single order series, shared with
     * walk-in catalog orders (see App\Support\OrderNumber).
     */
    private function generateOrderNumber(Store $store): string
    {
        return OrderNumber::next($store->id);
    }

    /**
     * Globally unique (not scoped per store, unlike order_number) — the
     * public tracking lookup has no other way to know which store's table to
     * search. Lets a customer check their order status without an account,
     * the real "sa na po ba?" pain point named in interview research.
     */
    public static function generateStorePrefix($store): string
    {
        if (is_numeric($store)) {
            $store = Store::find($store);
        }
        if (! $store) {
            return 'SUTR';
        }
        if (! empty($store->store_code)) {
            return strtoupper($store->store_code);
        }
        $known = [
            'thread-needle' => 'TNED',
        ];
        if (isset($known[$store->slug])) {
            return $known[$store->slug];
        }

        $letters = preg_replace('/[^A-Za-z]/', '', $store->name);
        if (strlen($letters) >= 4) {
            return strtoupper(substr($letters, 0, 4));
        }

        return strtoupper(str_pad($letters, 4, 'X'));
    }

    private function generateTrackingCode(?Store $store = null, ?string $orderNumber = null): string
    {
        return \App\Support\TrackingCode::make($store);
    }

    /**
     * Customer-facing "Request a Repair" — the first-ever customer-initiated
     * job order creation path (every other job order is still created by
     * the store owner/staff dashboard). Deliberately narrow: only fires for
     * a Service actually tagged alteration_repair, and pricing comes only
     * from that service's own real ServicePricing rows (never a guessed
     * flat amount) — a repair with no matching pricing line can't be priced
     * honestly here, so it's rejected rather than defaulting to 0.
     */
    public function customerRepairRequest(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => 'required|integer',
            'garment_description' => 'required|string|max:500',
            'pre_existing_damage_notes' => 'required|string|max:2000',
            // A fit change (hem, shorten, take in) needs only the part being changed: where, and by how much. Not a body measurement.
            'local_adjustment' => 'nullable|string|max:500',
            'pricing_ids' => 'required|array|min:1',
            'pricing_ids.*' => 'integer',
            'reference_images' => 'nullable|array|max:10',
            'reference_images.*' => 'string|max:1000',
        ]);

        $service = Service::where('id', $validated['service_id'])
            ->where('store_id', $store->id)
            ->first();

        if (! $service || ! $service->hasType(Service::TYPE_ALTERATION_REPAIR)) {
            return response()->json(['success' => false, 'message' => 'This store has no matching repair/alteration service.'], 404);
        }

        $pricingRows = ServicePricing::where('service_id', $service->id)
            ->whereIn('id', $validated['pricing_ids'])
            ->get();

        if ($pricingRows->count() !== count($validated['pricing_ids'])) {
            return response()->json(['success' => false, 'message' => 'One or more selected repair items are no longer available.'], 422);
        }

        $totalAmount = (float) $pricingRows->sum('amount');

        $mainBranch = $store->branches()->where('is_main', true)->first();

        $orderNumber = $this->generateOrderNumber($store);
        $jobOrder = $store->jobOrders()->create([
            'order_number' => $orderNumber,
            'tracking_code' => $this->generateTrackingCode($store, $orderNumber),
            'intake_channel' => 'online',
            'store_branch_id' => $mainBranch?->id,
            'customer_id' => $request->user()->id,
            'service_id' => $service->id,
            'garment_category' => 'alteration_repair',
            'total_amount' => $totalAmount,
            'balance' => $totalAmount,
            'payment_status' => 'unpaid',
            'status' => 'pending',
            'reference_images' => $validated['reference_images'] ?? [],
            'custom_order_data' => [
                // The customer's original request, written once at submission and
                // never overwritten — kept out of `notes` specifically because
                // `notes` is where staff/owner operational commentary gets appended
                // elsewhere in this codebase (e.g. AppointmentController::complete()'s
                // fitting-notes append). Mixing the two would let staff text drift
                // the customer's own repair request out of view. See
                // docs/REPAIR-WORKFLOW.md §3.
                'repair_note' => $validated['garment_description'],
                'pre_existing_damage_notes' => $validated['pre_existing_damage_notes'],
                'local_adjustment' => trim((string) ($validated['local_adjustment'] ?? '')) ?: null,
                'repair_items' => $pricingRows->map(fn ($p) => ['label' => $p->label, 'amount' => (float) $p->amount])->values()->all(),
            ],
        ]);

        $jobOrder->load(['customer:id,name', 'service']);

        // The customer lands in the shop's Client Book (never detached from the others).
        $store->customers()->syncWithoutDetaching([$request->user()->id]);

        $storeOwner = $store->owner;
        if ($storeOwner) {
            $storeOwner->notify(new NewJobOrderNotification($jobOrder));
        }
        $this->notifyManagersOfOnlineOrder($store, $jobOrder);

        return response()->json(['success' => true, 'data' => $jobOrder], 201);
    }

    /**
     * Customer-facing "Bulk Order" off a Catalog Designs item — Size +
     * Quantity only for now (Color deferred). Requires the item to have a
     * linked, bulk_sublimation-typed Service (CatalogItem.service_id) —
     * there's no fallback/auto-detected service, so an unlinked item simply
     * has no Bulk Order entry point. Pricing is the item's own real price
     * × total quantity (never guessed), and each unit becomes its own
     * team_roster row (name left blank — a browsing customer has no real
     * per-recipient names, unlike a staff-entered team roster) so the store's
     * existing Team Roster & Size Sheet UI can track/complete each piece
     * individually, exactly as it already does for staff-created bulk jobs.
     */
    public function customerBulkOrder(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate([
            'catalog_item_id' => 'required|integer',
            'organization_name' => 'nullable|string|max:255',
            'store_branch_id' => 'nullable|integer',
            'roster' => 'required|array|min:1|max:500',
            'roster.*.name' => 'nullable|string|max:100',
            'roster.*.size' => 'required|string|max:50',
        ]);

        $item = CatalogItem::where('id', $validated['catalog_item_id'])
            ->where('store_id', $store->id)
            ->first();

        if (! $item || ! $item->service_id) {
            return response()->json(['success' => false, 'message' => 'This item is not available for bulk ordering.'], 404);
        }

        $service = Service::find($item->service_id);
        if (! $service || ! $service->hasType(Service::TYPE_BULK_SUBLIMATION)) {
            return response()->json(['success' => false, 'message' => 'This item is not available for bulk ordering.'], 404);
        }

        $totalQty = count($validated['roster']);

        // The minimum is the shop's usual one, not a wall: a smaller request still goes to the shop, which decides.
        $belowMinimum = $service->min_order_qty > 1 && $totalQty < $service->min_order_qty;

        // Keep whatever extra columns the customer added on the roster
        // table (e.g. "Jersey Number") — $request->validate() above only
        // proves name/size are present, it doesn't strip the rest, so the
        // raw rows (not $validated) carry those extra keys through into
        // custom_order_data.team_roster for the store owner to see.
        $roster = collect($request->input('roster'))->map(function ($row) {
            return array_merge($row, ['name' => $row['name'] ?? null]);
        })->all();

        $sizeCounts = collect($validated['roster'])->countBy('size');
        // Priced by the service (plus the design's extra charge, if any) — never the design's old own price.
        // A design that needs a quotation starts at 0 and the shop quotes it in the order flow.
        $pricing = DesignPricing::resolve($item, $service);
        $baseUnit = $pricing['amount'] ?? 0;

        // Size extras per piece from roster
        $totalSizeExtras = 0.0;
        foreach ($validated['roster'] as $r) {
            $totalSizeExtras += DesignPricing::parseSizeExtra($r['size'] ?? '');
        }
        $totalSizeExtras = round($totalSizeExtras, 2);

        // Resolve selected add-ons across the bulk order
        $addOnsResult = DesignPricing::resolveAddOns($item, $service, $request->input('selected_add_ons'));
        $totalAddOns = round($addOnsResult['total'] * $totalQty, 2);

        $totalAmount = $pricing['needs_quote']
            ? 0
            : round(($baseUnit * $totalQty) + $totalSizeExtras + $totalAddOns, 2);

        // Pre-build quotation additional charges
        $additionalCharges = [];
        if ($totalSizeExtras > 0) {
            $additionalCharges[] = [
                'label' => 'Size adjustments (bulk roster)',
                'amount' => $totalSizeExtras,
            ];
        }
        foreach ($addOnsResult['items'] as $addon) {
            $levelLabel = $addon['level'] === 'design' ? 'Design add-on' : 'Service add-on';
            $additionalCharges[] = [
                'label' => "{$levelLabel}: {$addon['name']} ({$totalQty} pcs)",
                'amount' => round($addon['price'] * $totalQty, 2),
            ];
        }

        $initialQuotation = [
            'base_price' => round($baseUnit * $totalQty, 2),
            'quantity_adjustment' => 0,
            'additional_charges' => $additionalCharges,
            'customer_provided_deduction' => [],
            'notes' => null,
            'total' => $totalAmount,
        ];

        // Customer-picked branch (via the catalog page's Find sheet) wins
        // when it actually belongs to this store; otherwise fall back to the
        // store's main branch same as before the picker existed.
        $pickedBranch = ! empty($validated['store_branch_id'])
            ? $store->branches()->where('id', $validated['store_branch_id'])->first()
            : null;
        $mainBranch = $pickedBranch ?? $store->branches()->where('is_main', true)->first();

        $orderNumber = $this->generateOrderNumber($store);
        $jobOrder = $store->jobOrders()->create([
            'order_number' => $orderNumber,
            'tracking_code' => $this->generateTrackingCode($store, $orderNumber),
            'intake_channel' => 'online',
            'store_branch_id' => $mainBranch?->id,
            'customer_id' => $request->user()->id,
            'service_id' => $service->id,
            'catalog_item_id' => $item->id,
            'garment_category' => $item->garment_type,
            'material_source' => 'store_supplied',
            'total_amount' => $totalAmount,
            'balance' => $totalAmount,
            'payment_status' => 'unpaid',
            'status' => 'pending',
            'uses_confirmation_flow' => true,
            'quotation' => $initialQuotation,
            'notes' => "Bulk order: {$item->name} — ".$sizeCounts->map(fn ($qty, $size) => "{$qty}x {$size}")->implode(', ')
                .($totalSizeExtras > 0 ? ' (Size adjustments: ₱'.number_format($totalSizeExtras, 2).')' : '')
                .($belowMinimum ? " (Below the usual minimum of {$service->min_order_qty}: {$totalQty} requested. The shop confirms.)" : '')
                .(! empty($addOnsResult['items']) ? ' (Add-ons: '.collect($addOnsResult['items'])->pluck('name')->implode(', ').')' : '')
                .($pricing['needs_quote'] ? '. Price to be quoted by the shop.' : ''),
            'reference_images' => $item->images()->where('is_primary', true)->value('image_url') ? [$item->images()->where('is_primary', true)->value('image_url')] : [],
            'custom_order_data' => [
                'team_name' => $validated['organization_name'] ?? $item->name,
                'team_roster' => $roster,
                'people_count' => count($roster),
                'size_surcharges_total' => $totalSizeExtras,
                'selected_add_ons' => $addOnsResult['items'],
                'pricing_snapshot' => [
                    'mode' => $item->pricing_mode ?? 'same',
                    'base_unit_price' => $baseUnit,
                    'quantity' => $totalQty,
                    'base_total' => round($baseUnit * $totalQty, 2),
                    'size_surcharges_total' => $totalSizeExtras,
                    'add_ons_total' => $totalAddOns,
                    'total' => $totalAmount,
                    'captured_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        $jobOrder->load(['customer:id,name', 'service', 'catalogItem:id,name']);

        // The customer lands in the shop's Client Book (never detached from the others).
        $store->customers()->syncWithoutDetaching([$request->user()->id]);

        $storeOwner = $store->owner;
        if ($storeOwner) {
            $storeOwner->notify(new NewJobOrderNotification($jobOrder));
        }
        $this->notifyManagersOfOnlineOrder($store, $jobOrder);

        return response()->json(['success' => true, 'data' => $jobOrder], 201);
    }

    /**
     * Customer-facing "Made to Order" — a single piece of a specific
     * Showroom item, no team roster (that's Bulk Order's shape, not this
     * one). Requires the item to have a linked Service same as Bulk Order,
     * but explicitly rejects a bulk_sublimation-typed one — that service's
     * min_order_qty is a real business rule (e.g. "10 pieces minimum") a
     * single-piece order would otherwise silently bypass.
     */
    public function customerMadeToOrder(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate([
            'catalog_item_id' => 'required|integer',
            'size' => 'nullable|string|max:50',
            'selected_add_ons' => 'nullable|array|max:30',
        ]);

        $item = CatalogItem::where('id', $validated['catalog_item_id'])
            ->where('store_id', $store->id)
            ->first();

        if (! $item || ! $item->service_id) {
            return response()->json(['success' => false, 'message' => 'This item is not available to order directly yet.'], 404);
        }

        $service = Service::find($item->service_id);
        if (! $service) {
            return response()->json(['success' => false, 'message' => 'This item is not available to order directly yet.'], 404);
        }
        if ($service->hasType(Service::TYPE_BULK_SUBLIMATION)) {
            return response()->json(['success' => false, 'message' => 'This item requires a Bulk Order — please use the bulk order flow instead.'], 422);
        }

        if (! empty($validated['size']) && ! empty($item->sizes) && ! in_array($validated['size'], $item->sizes, true)) {
            return response()->json(['success' => false, 'message' => 'That size is not offered for this item.'], 422);
        }

        // Same rule as a bulk order: the service's price plus the design's extra charge, or 0 until quoted.
        $pricing = DesignPricing::resolve($item, $service);
        $baseUnit = $pricing['amount'] ?? 0;
        $sizeExtra = DesignPricing::parseSizeExtra($validated['size'] ?? null);
        $addOnsResult = DesignPricing::resolveAddOns($item, $service, $request->input('selected_add_ons'));

        $totalAmount = $pricing['needs_quote']
            ? 0
            : round($baseUnit + $sizeExtra + $addOnsResult['total'], 2);

        $additionalCharges = [];
        if ($sizeExtra > 0 && ! empty($validated['size'])) {
            $additionalCharges[] = [
                'label' => "Size adjustment ({$validated['size']})",
                'amount' => $sizeExtra,
            ];
        }
        foreach ($addOnsResult['items'] as $addon) {
            $levelLabel = $addon['level'] === 'design' ? 'Design add-on' : 'Service add-on';
            $additionalCharges[] = [
                'label' => "{$levelLabel}: {$addon['name']}",
                'amount' => $addon['price'],
            ];
        }

        $initialQuotation = [
            'base_price' => $baseUnit,
            'quantity_adjustment' => 0,
            'additional_charges' => $additionalCharges,
            'customer_provided_deduction' => [],
            'notes' => null,
            'total' => $totalAmount,
        ];

        $mainBranch = $store->branches()->where('is_main', true)->first();
        $primaryImage = $item->images()->where('is_primary', true)->value('image_url');

        $orderNumber = $this->generateOrderNumber($store);
        $jobOrder = $store->jobOrders()->create([
            'order_number' => $orderNumber,
            'tracking_code' => $this->generateTrackingCode($store, $orderNumber),
            'intake_channel' => 'online',
            'store_branch_id' => $mainBranch?->id,
            'customer_id' => $request->user()->id,
            'service_id' => $service->id,
            'catalog_item_id' => $item->id,
            'garment_category' => $item->garment_type,
            'material_source' => 'store_supplied',
            'total_amount' => $totalAmount,
            'balance' => $totalAmount,
            'payment_status' => 'unpaid',
            'status' => 'pending',
            'uses_confirmation_flow' => true,
            'quotation' => $initialQuotation,
            'notes' => 'Made to Order: '.$item->name.(! empty($validated['size']) ? ' (Reference size: '.$validated['size'].')' : '')
                .(! empty($addOnsResult['items']) ? ' (Add-ons: '.collect($addOnsResult['items'])->pluck('name')->implode(', ').')' : '')
                .($pricing['needs_quote'] ? '. Price to be quoted by the shop.' : ''),
            'reference_images' => $primaryImage ? [$primaryImage] : [],
            'custom_order_data' => array_filter([
                'reference_size' => $validated['size'] ?? null,
                'size_extra' => $sizeExtra > 0 ? $sizeExtra : null,
                'selected_add_ons' => $addOnsResult['items'] ?: null,
                'pricing_snapshot' => [
                    'mode' => $item->pricing_mode ?? 'same',
                    'base_unit_price' => $baseUnit,
                    'size_extra' => $sizeExtra,
                    'add_ons_total' => $addOnsResult['total'],
                    'total' => $totalAmount,
                    'captured_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        $jobOrder->load(['customer:id,name', 'service', 'catalogItem:id,name']);

        // The customer lands in the shop's Client Book (never detached from the others).
        $store->customers()->syncWithoutDetaching([$request->user()->id]);

        $storeOwner = $store->owner;
        if ($storeOwner) {
            $storeOwner->notify(new NewJobOrderNotification($jobOrder));
        }
        $this->notifyManagersOfOnlineOrder($store, $jobOrder);

        return response()->json(['success' => true, 'data' => $jobOrder], 201);
    }

    /**
     * Customer-facing general order request: a made-to-measure garment or service with no appointment and no
     * catalog design. It waits as a pending request for the shop to accept, then to price. Nothing is paid
     * and nothing starts here. Repairs and bulk orders keep their own entry points (price list / roster).
     */
    public function customerOrderRequest(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => 'required|integer',
            'garment_description' => 'required|string|min:5|max:1000',
            'quantity' => 'nullable|integer|min:1|max:50',
            'measurements_note' => 'nullable|string|max:1000',
            'own_fabric' => 'nullable|boolean',
            'reference_images' => 'nullable|array|max:10',
            'reference_images.*' => 'string|max:1000',
            'store_branch_id' => 'nullable|integer',
        ]);

        $service = Service::where('id', $validated['service_id'])->where('store_id', $store->id)->first();
        if (! $service) {
            return response()->json(['success' => false, 'message' => 'That service is not offered by this shop.'], 404);
        }
        if ($service->hasType(Service::TYPE_ALTERATION_REPAIR)) {
            return response()->json(['success' => false, 'message' => 'For repairs and alterations, use "Request a repair".'], 422);
        }
        if ($service->hasType(Service::TYPE_BULK_SUBLIMATION)) {
            return response()->json(['success' => false, 'message' => 'For team or bulk orders, use the bulk order form.'], 422);
        }

        $branch = ! empty($validated['store_branch_id'])
            ? $store->branches()->where('id', $validated['store_branch_id'])->first()
            : null;
        $branch ??= $store->branches()->where('is_main', true)->first();

        $orderNumber = $this->generateOrderNumber($store);
        $jobOrder = $store->jobOrders()->create([
            'order_number' => $orderNumber,
            'tracking_code' => $this->generateTrackingCode($store, $orderNumber),
            'intake_channel' => 'online',
            'store_branch_id' => $branch?->id,
            'customer_id' => $request->user()->id,
            'service_id' => $service->id,
            'quantity' => $validated['quantity'] ?? 1,
            'material_source' => ! empty($validated['own_fabric']) ? 'customer_supplied' : 'store_supplied',
            'total_amount' => 0,
            'balance' => 0,
            'payment_status' => 'unpaid',
            'status' => 'pending',
            'uses_confirmation_flow' => true,
            'notes' => 'Online request: '.trim($validated['garment_description']),
            'reference_images' => $validated['reference_images'] ?? [],
            'custom_order_data' => array_filter([
                'request_note' => trim($validated['garment_description']),
                'measurements_note' => trim((string) ($validated['measurements_note'] ?? '')) ?: null,
            ]),
        ]);
        $jobOrder->load(['customer:id,name', 'service']);

        // The customer lands in the shop's Client Book (never detached from the others).
        $store->customers()->syncWithoutDetaching([$request->user()->id]);

        $store->owner?->notify(new NewJobOrderNotification($jobOrder));
        $this->notifyManagersOfOnlineOrder($store, $jobOrder);

        return response()->json(['success' => true, 'data' => $jobOrder], 201);
    }

    /**
     * Customer-facing "Online Order" off a store service page — the same four parts as the shop's
     * walk-in wizard (customer, measurements, order specs, invoice & payment), stored under the
     * same custom_order_data keys so the shop reads both kinds of order alike. Landing point for
     * the service detail page's "Online Order" button; repairs keep their own request form, and
     * bulk / made-to-order off a catalog design keep theirs.
     */
    public function customerServiceOrder(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => 'required|integer',
            'contact_phone' => 'required|string|max:20',
            'store_branch_id' => 'nullable|integer',

            'order_mode' => 'required|in:individual,bulk',
            'measurement_id' => 'nullable|integer',
            'individual_measurements' => 'nullable|array',
            'individual_measurements.unit' => 'nullable|in:in,cm',
            'individual_measurements.*' => 'nullable',
            // What the person gets (top, bottom or both): asked first, before any size, whenever the service needs sizing.
            'measure_area' => 'nullable|in:top,bottom,both',
            'size_breakdown' => 'required_if:order_mode,bulk|array',
            'size_breakdown.*' => 'nullable|integer|min:0|max:500',
            // Top & bottom in bulk: how many of each size for the bottoms (the same number of sets as the tops).
            'size_breakdown_bottom' => 'nullable|array',
            'size_breakdown_bottom.*' => 'nullable|integer|min:0|max:500',
            'team_name' => 'nullable|string|max:255',
            // Who will wear it when that is not the customer (a child, a relative): keeps their measurements apart from the customer's own.
            'wearer_name' => 'nullable|string|max:120',
            'measurement_sheets' => 'nullable|array|max:5',
            'measurement_sheets.*' => 'string|max:1000',

            'pricing_id' => 'nullable|integer',
            'size' => 'nullable|string|max:50',
            // The bottom's size when one person gets top & bottom ('size' is then the top's).
            'size_bottom' => 'nullable|string|max:50',
            'material_source' => ['required', Rule::in(JobOrder::MATERIAL_SOURCES)],
            'material_description' => 'nullable|string|max:500',
            'answers' => 'nullable|array',
            'answers.*' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:2000',
            'reference_images' => 'nullable|array|max:5',
            'reference_images.*' => 'string|max:1000',
            'reference_link' => ['nullable', 'string', 'max:500', new SafeLink],

            'quantity' => 'required_if:order_mode,individual|nullable|integer|min:1|max:500',
            'rush_requested' => 'nullable|boolean',
            'needed_by' => 'nullable|date|after:today',
            // Only the customer's preference: nothing is paid with the request.
            'payment_term' => 'required|in:full,downpayment',
        ]);

        $service = Service::where('id', $validated['service_id'])
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->with('pricing')
            ->first();

        if (! $service) {
            return response()->json(['success' => false, 'message' => 'This service is not available to order right now.'], 404);
        }
        if ($service->hasType(Service::TYPE_ALTERATION_REPAIR)) {
            return response()->json(['success' => false, 'message' => 'Repairs use the repair request form instead.'], 422);
        }

        // A service with several pricing lines needs one picked; a single line is implied.
        $pricing = null;
        if (! empty($validated['pricing_id'])) {
            $pricing = $service->pricing->firstWhere('id', (int) $validated['pricing_id']);
            if (! $pricing) {
                return response()->json(['success' => false, 'message' => 'That option is no longer offered. Please pick another.'], 422);
            }
        } elseif ($service->pricing->count() === 1) {
            $pricing = $service->pricing->first();
        } elseif ($service->pricing->count() > 1) {
            return response()->json([
                'success' => false,
                'message' => 'Please choose an option for this service.',
                'errors' => ['pricing_id' => ['Choose an option.']],
            ], 422);
        }

        // The option picked decides what is covered and what one ordered unit is, taken from the option itself on the server
        // (never from what the client says it is). An option with no coverage leaves the customer's own Top / Bottom choice as it was.
        $optionSnapshot = $pricing ? $pricing->snapshot() : [];
        if ($service->pricing->count() <= 1) {
            unset($optionSnapshot['option']); // a single option is implied: the order shows no option name, as before
        }
        if ($pricing && in_array($pricing->covers, ServicePricing::COVERS, true)) {
            $validated['measure_area'] = $pricing->covers;
        }

        // The service decides first whether any body sizing is asked at all (see "Sizing" below); the typed points only
        // ever come from the covered part: top = chest, shoulders, sleeve, neck, length; bottom = waist, hips, inseam.
        $area = $validated['measure_area'] ?? null;
        $pointsByArea = ['top' => ['chest', 'shoulders', 'sleeve', 'neck', 'length'], 'bottom' => ['waist', 'hips', 'inseam']];
        $allowedPoints = match ($area) {
            'top' => $pointsByArea['top'],
            'bottom' => $pointsByArea['bottom'],
            default => [...$pointsByArea['top'], ...$pointsByArea['bottom']],
        };

        // Only the walk-in form's measurement fields, each a plain number.
        $measurements = null;
        if ($validated['order_mode'] === 'individual' && ! empty($validated['individual_measurements'])) {
            $measurements = ['unit' => $validated['individual_measurements']['unit'] ?? 'in'];
            foreach ($allowedPoints as $key) {
                $value = $validated['individual_measurements'][$key] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                if (! is_numeric($value) || $value <= 0 || $value > 200) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Measurements must be numbers between 0 and 200.',
                        'errors' => ["individual_measurements.$key" => ['Enter a number between 0 and 200.']],
                    ], 422);
                }
                $measurements[$key] = (string) (0 + $value);
            }
            if (count($measurements) === 1) {
                $measurements = null;
            }
        }

        $sizeBreakdown = null;
        if ($validated['order_mode'] === 'bulk') {
            $sizeBreakdown = collect($validated['size_breakdown'] ?? [])
                ->only(['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL'])
                ->map(fn ($qty) => (int) $qty)
                ->filter(fn ($qty) => $qty > 0)
                ->all();
            if (! $sizeBreakdown) {
                return response()->json([
                    'success' => false,
                    'message' => 'Add how many pieces you need in at least one size.',
                    'errors' => ['size_breakdown' => ['Enter at least one size quantity.']],
                ], 422);
            }
        }

        $sizeBreakdownBottom = null;
        if ($sizeBreakdown && $area === 'both') {
            $sizeBreakdownBottom = collect($validated['size_breakdown_bottom'] ?? [])
                ->only(['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL'])
                ->map(fn ($qty) => (int) $qty)
                ->filter(fn ($qty) => $qty > 0)
                ->all();
            // A set is one top and one bottom, so both lists count the same number of people.
            if (array_sum($sizeBreakdownBottom) !== array_sum($sizeBreakdown)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The bottoms must add up to the same number as the tops.',
                    'errors' => ['size_breakdown_bottom' => ['Tops and bottoms must be the same number of pieces.']],
                ], 422);
            }
        }

        // Sizing, in order: (1) a service that needs no body measurements asks nothing about sizing, whatever was sent;
        // (2) otherwise what the person gets (top / bottom / both) comes first; (3) then, for one person, a top & bottom
        // standard size needs both sizes. Payment never depends on any of this: it follows the service's own payment rules.
        $needsSizing = OrderRequirements::resolve($store, [$service])['measurement_requirement'] !== 'none';
        if (! $needsSizing) {
            $area = null;
            $measurements = null;
            $validated['size'] = $validated['size_bottom'] = null;
            $sizeBreakdownBottom = null;
        } else {
            if (! $area) {
                return response()->json([
                    'success' => false,
                    'message' => 'Choose what you are ordering first: Top, Bottom or Top & Bottom.',
                    'errors' => ['measure_area' => ['Choose Top, Bottom or Top & Bottom.']],
                ], 422);
            }
            if (! $sizeBreakdown && $area === 'both' && ! empty($validated['size']) && empty($validated['size_bottom'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Choose the bottom size too.',
                    'errors' => ['size_bottom' => ['Choose the bottom size.']],
                ], 422);
            }
        }
        // One line for the shop to read: "M", or "M / L" when the top and the bottom differ.
        $standardSize = $sizeBreakdown ? null : ($area === 'both'
            ? (trim(($validated['size'] ?? '').' / '.($validated['size_bottom'] ?? ''), ' /') ?: null)
            : ($validated['size'] ?? null));

        $quantity = $sizeBreakdown ? (int) array_sum($sizeBreakdown) : (int) $validated['quantity'];
        if ($quantity > 500) {
            return response()->json(['success' => false, 'message' => 'Online orders are capped at 500 pieces. Message the shop for larger batches.'], 422);
        }
        if ($service->min_order_qty > 1 && $quantity < $service->min_order_qty) {
            return response()->json([
                'success' => false,
                'message' => "This service requires a minimum of {$service->min_order_qty} pieces.",
                'errors' => ['quantity' => ["Order at least {$service->min_order_qty} pieces."]],
            ], 422);
        }

        // Required service questions must be answered, and only known questions are kept.
        // Keyed by the question's label, same as booking answers (the job form pre-fills from them).
        $answers = [];
        $missing = [];
        foreach ($service->custom_fields ?? [] as $field) {
            $label = trim((string) ($field['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $value = trim((string) ($validated['answers'][$label] ?? ''));
            if ($value !== '') {
                $answers[$label] = $value;
            } elseif (! empty($field['required']) && ($field['type'] ?? '') !== 'checkbox') {
                $missing[] = $label;
            }
        }
        if ($missing) {
            return response()->json([
                'success' => false,
                'message' => 'Please answer: '.implode(', ', $missing).'.',
                'errors' => ['answers' => ['Some required questions are unanswered.']],
            ], 422);
        }

        $branch = ! empty($validated['store_branch_id'])
            ? $store->branches()->live()->where('id', $validated['store_branch_id'])->first()
            : null;
        if (! empty($validated['store_branch_id']) && ! $branch) {
            return response()->json(['success' => false, 'message' => 'That branch is not taking orders right now.'], 422);
        }
        $branch ??= $store->branches()->live()->where('is_main', true)->first()
            ?? $store->branches()->live()->first();

        $customerId = $request->user()->id;
        $measurementId = null;
        if (! empty($validated['measurement_id'])) {
            $measurementId = Measurement::where('id', $validated['measurement_id'])
                ->where('store_id', $store->id)
                ->where('customer_id', $customerId)
                ->value('id');
            if (! $measurementId) {
                return response()->json(['success' => false, 'message' => 'That measurement record is not on file with this shop.'], 422);
            }
        }

        $unitPrice = $pricing && $pricing->amount !== null ? (float) $pricing->amount : $service->effectivePrice();
        $totalAmount = round($unitPrice * $quantity, 2);

        // standard_size is the key OrderReadiness::measurementsRecorded() reads for "the shop has
        // something to cut by"; a bulk order keeps its size_breakdown instead and the shop signs
        // the measurements off on the order page, same as for walk-in team orders.
        $sheets = $validated['measurement_sheets'] ?? [];
        $customOrderData = array_filter([
            ...$answers,
            'contact_phone' => trim($validated['contact_phone']),
            'individual_measurements' => $measurements,
            'size_breakdown' => $sizeBreakdown,
            'measurement_sheets' => $sheets ?: null,
            'team_name' => $sizeBreakdown ? (trim((string) ($validated['team_name'] ?? '')) ?: null) : null,
            'wearer_name' => $sizeBreakdown ? null : (trim((string) ($validated['wearer_name'] ?? '')) ?: null),
            ...$optionSnapshot,
            'standard_size' => $standardSize,
            'measure_area' => $area,
            'size_breakdown_bottom' => $sizeBreakdownBottom ?: null,
            'customer_fabric' => $validated['material_source'] === 'customer_supplied' ? ($validated['material_description'] ?? null) : null,
            'item_rate' => (string) $unitPrice,
            'payment_term' => $validated['payment_term'],
            // The customer asks; the shop decides whether to take it as rush and sets the fee.
            'rush_requested' => ! empty($validated['rush_requested']) ? 'Yes' : null,
            'needed_by' => ! empty($validated['needed_by']) ? \Carbon\Carbon::parse($validated['needed_by'])->toDateString() : null,
        ], fn ($v) => $v !== null && $v !== '');

        // A request asks for no money. The shop reviews it, confirms the details and the price, the customer accepts that
        // price, and only then is the deposit due (paid from My Orders and verified by the shop). The term the customer
        // prefers travels with the order as a note; the rules it runs under are snapshotted by JobOrder::booted.

        $orderNumber = $this->generateOrderNumber($store);
        $jobOrder = DB::transaction(function () use ($store, $orderNumber, $branch, $customerId, $service, $measurementId, $quantity, $validated, $totalAmount, $sheets, $customOrderData) {
            $jobOrder = $store->jobOrders()->create([
            'order_number' => $orderNumber,
            'tracking_code' => $this->generateTrackingCode($store, $orderNumber),
            'intake_channel' => 'online',
            'store_branch_id' => $branch?->id,
            'customer_id' => $customerId,
            'service_id' => $service->id,
            'measurement_id' => $measurementId,
            'quantity' => $quantity,
            // No garment_category: this order belongs to a service, and the legacy garment
            // taxonomy is left untouched rather than being forced into the 10 old values.
            'material_source' => $validated['material_source'],
            'total_amount' => $totalAmount,
            'balance' => $totalAmount,
            'payment_status' => 'unpaid',
            'status' => 'pending',
            // Same as the other online order entry points: the order runs the shop's normal
            // confirm -> quote -> approve -> verify deposit -> production pipeline.
            'uses_confirmation_flow' => true,
            'notes' => ! empty($validated['notes']) ? 'Customer note: '.$validated['notes'] : null,
            // Sheets ride along with the reference photos, same as walk-in.
            'reference_images' => array_values(array_unique([...($validated['reference_images'] ?? []), ...$sheets])),
            'reference_link' => $validated['reference_link'] ?? null,
            'custom_order_data' => $customOrderData ?: null,
            ]);

            return $jobOrder;
        });

        $jobOrder->load(['customer:id,name', 'service']);

        // The customer lands in the shop's Client Book (never detached from the others).
        $store->customers()->syncWithoutDetaching([$customerId]);

        // The customer typed these measurements themselves — the profile they become is marked
        // theirs. An explicitly linked saved profile is kept as-is, never re-versioned behind
        // the customer's back.
        if (! $measurementId) {
            JobMeasurementSync::syncForJob($store, $jobOrder, 'customer');
        }

        $storeOwner = $store->owner;
        if ($storeOwner) {
            $storeOwner->notify(new NewJobOrderNotification($jobOrder));
        }
        $this->notifyManagersOfOnlineOrder($store, $jobOrder);

        return response()->json(['success' => true, 'data' => $jobOrder], 201);
    }

    /**
     * Branch managers see an order a customer placed online (the owner and the floor staff are told elsewhere): the
     * order's branch's managers, or every manager when it has no branch. One notice each, never doubled with "New order".
     */
    private function notifyManagersOfOnlineOrder(Store $store, JobOrder $jobOrder): void
    {
        User::whereHas('staffProfile', fn ($q) => $q->where('store_id', $store->id)->where('is_branch_manager', true)
            ->when($jobOrder->store_branch_id, fn ($q2) => $q2->where('store_branch_id', $jobOrder->store_branch_id)))
            ->get()
            ->each(fn (User $m) => SafeNotify::send($m, new StoreActivityNotification(
                'job_new_request', 'New online request',
                "{$jobOrder->order_number} from {$jobOrder->customer?->name} is waiting for your review.",
                '/dashboard/jobs/'.$jobOrder->id, ['job_order_id' => $jobOrder->id],
            )));
    }

    /**
     * What the signed-in customer's online order will actually be asked to pay up front,
     * for the "Online Order" wizard's payment step: the same effective policy
     * customerServiceOrder() applies (trusted customers owe no deposit), so the wizard
     * never has to re-derive — or see — the trust flag itself. Read-only, no rules change.
     */
    public function customerServiceOrderPaymentPolicy(Store $store, Request $request): JsonResponse
    {
        $request->validate(['service_id' => ['required', 'integer', Rule::exists('services', 'id')->where('store_id', $store->id)]]);
        $service = $store->services()->find($request->input('service_id'));

        $requirements = OrderRequirements::forCustomer($store, [$service], $request->user()->id);

        return response()->json([
            'success' => true,
            'data' => [
                'payment_policy' => $requirements['payment_policy'],
                'payment_policy_percent' => $requirements['payment_policy_percent'],
            ],
        ]);
    }

    public function index(Store $store, Request $request): JsonResponse
    {
        $query = $store->jobOrders()->with([
            'customer:id,name,suki_tag',
            'service',
            'assignedStaff:id,name',
            'branch:id,name',
            'catalogItem:id,name,garment_type,price,fabric_image_url',
            'catalogItem.images',
            // The Being made card names the tailor (lead first).
            'assignees:id,job_order_id,user_id,role',
            'assignees.user:id,name',
        ]);

        $branchId = null;
        if (! $request->user()->hasRole('store_owner') && $request->user()->staffProfile?->store_branch_id) {
            // Staff/branch managers pinned to a branch only ever see that branch's
            // jobs — matches the per-action branch check enforced elsewhere below,
            // so nothing shows up in the list that they'd then be blocked from opening.
            $branchId = $request->user()->staffProfile->store_branch_id;
        } elseif ($request->filled('branch_id')) {
            $branchId = $request->branch_id;
        }

        if ($branchId) {
            $query->where('store_branch_id', $branchId);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Used by the appointment Complete flow to look up a specific
        // customer's job orders (e.g. to link a Fitting/Pickup appointment)
        // without being limited to whatever page of the full store-wide list
        // happens to be loaded elsewhere.
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('assigned_staff_id')) {
            $query->where('assigned_staff_id', $request->assigned_staff_id);
        }

        // "My jobs": ones I'm attributed to (I moved them into a production stage) or hold as the assigned staff.
        if ($request->boolean('mine')) {
            $myUserId = $request->user()->id;
            $query->where(function ($q) use ($myUserId) {
                $q->where('assigned_staff_id', $myUserId)
                    ->orWhereIn('job_orders.id', DB::table('job_order_staff')->where('user_id', $myUserId)->select('job_order_id'));
            });
        }

        // The Archived page: archived orders newest-archived first, each flagged with whether it has any
        // payment or refund record (those can never be deleted for good, only restored).
        if ($request->boolean('trashed')) {
            $query->onlyTrashed()->withExists(['payments as has_payment_rows', 'refunds as has_refund_rows'])->reorder('deleted_at', 'desc');
        }

        // The Jobs list page's own search box used to run entirely
        // client-side against the same per_page=200-capped fetch — a store
        // with more than 200 total historical jobs could search for a real
        // order number or customer name that exists beyond that window and
        // get "no results" even though it's genuinely there. Same
        // LOWER()+LIKE pattern as CatalogController::index's search — plain
        // LIKE isn't case-insensitive on Postgres the way it is on MySQL by
        // default, and this project has a Postgres migration planned.
        if ($request->filled('search')) {
            $search = strtolower((string) $request->string('search'));
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(order_number) LIKE ?', ['%'.$search.'%'])
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%']);
                    });
            });
        }

        // Used by the Payments page's "Job Balances" tab, which needs every
        // job still owing money regardless of production status. It used to
        // fetch the store's entire job history (per_page=500, paid jobs
        // included) just to filter down client-side — the same
        // undercounting risk as the other capped-array bugs fixed this
        // session, and wasteful besides. Filtering server-side means the
        // response only ever contains genuinely-relevant rows, which stay
        // naturally small for any store actually chasing its payments.
        if ($request->boolean('unpaid_only')) {
            $query->where('payment_status', '!=', 'paid')->where('status', '!=', 'cancelled');
        }

        // The Jobs list page's own tab badges ("All Orders 12", "Walk-in 8",
        // "Online 4") used to be re-derived client-side from this same list
        // fetched at per_page=200 — a store with more than 200 total
        // historical job orders (old completed/cancelled ones included,
        // nothing prunes them) would see those badges silently undercount.
        // Same fix as the Home dashboard's alert widgets: real, unbounded
        // counts, cloned off the already-filtered query before pagination
        // consumes it, so branch/status/customer/staff filters still apply
        // consistently to both the list and these counts.
        $walkInCount = (clone $query)->where('intake_channel', 'walk_in')->count();
        $onlineCount = (clone $query)->where('intake_channel', 'online')->count();
        // Same fix, same reasoning, for the "X job orders awaiting feasibility
        // review" banner (pending status).
        $pendingCount = (clone $query)->where('status', 'pending')->count();

        // assignees_count: the Confirmed column's "Tailor" check, without loading every assignee.
        $query->withCount('assignees');
        // The Ready card's "0 of 20 handed over".
        $query->withSum('handovers as handed_over_count', 'quantity');
        // The Done card's "Handed over Oct 22".
        $query->withMax('handovers as last_handover_at', 'handed_over_at');
        $page = $request->boolean('trashed') ? $query->paginate($request->input('per_page', 15)) : $query->latest()->paginate($request->input('per_page', 15));
        if ($request->boolean('trashed')) {
            $page->getCollection()->each(function (JobOrder $j) {
                $j->setAttribute('has_payments', (bool) ($j->has_payment_rows || $j->has_refund_rows));
                $j->offsetUnset('has_payment_rows');
                $j->offsetUnset('has_refund_rows');
            });
        }

        // Plain staff get the list without any amounts (the totals above are owner / branch manager figures on the dashboard, not here).
        $page->getCollection()->each(fn (JobOrder $j) => $j->seenBy($request->user()));

        return response()->json([
            'success' => true,
            'data' => $page,
            'walk_in_count' => $walkInCount,
            'online_count' => $onlineCount,
            'pending_count' => $pendingCount,
        ]);
    }

    /**
     * The style notes written at the consultation (what the shop noted at the visit, and the shop's fitting note). The
     * customer's own note goes to the order's notes, their answers to the service's questions and the fabric to the fabric
     * choice, so none of those is repeated here.
     */
    private function consultationNotesFrom(Appointment $a): ?string
    {
        $lines = array_filter([trim((string) $a->visit_notes), trim((string) $a->fitting_notes) !== '' ? "Shop's fitting note: ".trim($a->fitting_notes) : '']);

        return $lines ? implode("\n", $lines) : null;
    }

    public function store(StoreJobOrderRequest $request, Store $store): JsonResponse
    {
        $validated = $request->validated();
        // The extra services are made after the main order (see below); they are not columns of it.
        unset($validated['extra_services']);
        // Set explicitly rather than relying on the DB column default — an
        // Eloquent model just-created from an array without this key never
        // gets it back until the row is re-fetched, so the create response
        // would silently omit "quantity": 1 even though that's what the row
        // actually holds.
        $validated['quantity'] = $validated['quantity'] ?? 1;

        // Server-side enforcement of service-type rules — the frontend already
        // checks these, but a client can't be trusted for money/liability-sensitive
        // rules like minimum order quantity or damage-waiver logging.
        $service = Service::find($validated['service_id']);

        // The option this order is made from: its label, what it covers and what one ordered unit is are written here, from the
        // option itself, and never taken from the client. Whatever the client put under those keys is discarded.
        foreach (['option', 'option_covers', 'option_sold_as'] as $key) {
            unset($validated['custom_order_data'][$key]);
        }
        $pricingId = $validated['pricing_id'] ?? null;
        unset($validated['pricing_id']);
        if ($service && $pricingId) {
            $option = ServicePricing::where('id', $pricingId)->where('service_id', $service->id)->first();
            if (! $option) {
                return response()->json(['success' => false, 'message' => 'That option is not offered by this service.', 'errors' => ['pricing_id' => ['Choose one of this service\'s options.']]], 422);
            }
            $validated['custom_order_data'] = array_merge($validated['custom_order_data'] ?? [], $option->snapshot());
            // An option that covers something decides it: the one wearer, and every person on a team list, get that coverage.
            if (in_array($option->covers, ServicePricing::COVERS, true)) {
                $validated['custom_order_data']['measure_area'] = $option->covers;
                if (is_array($validated['custom_order_data']['team_roster'] ?? null)) {
                    $validated['custom_order_data']['team_roster'] = array_map(fn ($row) => is_array($row) ? [...$row, 'measure_area' => $option->covers] : $row, $validated['custom_order_data']['team_roster']);
                }
            }
        }

        if ($service) {
            // A bulk order's quantity comes from whichever shape it took —
            // a personalized team_roster (one row per piece), OR a plain
            // size_breakdown/total_quantity tally with no names at all
            // (e.g. "30 pcs department shirts"). Only checking team_roster
            // here would wrongly reject a perfectly valid size-breakdown
            // order against this service's minimum.
            $roster = $validated['custom_order_data']['team_roster'] ?? null;
            $people = is_array($roster) ? count($roster) : 0;
            // From now on a group order counts its people (tracker); the marker keeps older orders as they were.
            if ($people > 0) {
                $validated['custom_order_data']['people_count'] = $people;
            }
            // The usual minimum is in pieces, not wearers: 5 people with 2 polos each is 10 pieces.
            $bulkQty = $people > 0 ? JobOrder::rosterPieces($roster) : (int) ($validated['custom_order_data']['total_quantity'] ?? 0);

            // `quantity` (several identical pieces for one person) is a
            // different concept from a bulk order's roster (different
            // people). Force it to 1 for a bulk order regardless of what a
            // client sent — the frontend already guards this, but the
            // server shouldn't trust it, same reasoning as every other
            // check in this block.
            if ($service->hasType(Service::TYPE_BULK_SUBLIMATION)) {
                $validated['quantity'] = 1;
            }

            // The service's minimum is the shop's usual one: the owner or a branch manager can go below it
            // (agreed with the customer) after seeing the warning; plain staff ask them.
            if (
                $service->hasType(Service::TYPE_BULK_SUBLIMATION)
                && $service->min_order_qty > 1
                && $bulkQty < $service->min_order_qty
            ) {
                $canContinue = $request->user()->hasRole('store_owner') || $request->user()->hasRole('branch_manager');
                if (! ($canContinue && $request->boolean('confirm_below_minimum'))) {
                    return response()->json([
                        'success' => false,
                        'code' => 'below_minimum',
                        'minimum' => (int) $service->min_order_qty,
                        'quantity' => $bulkQty,
                        'can_continue' => $canContinue,
                        'message' => "Below the usual minimum: {$bulkQty} of {$service->min_order_qty}. ".($canContinue ? 'You can continue if you agreed on it.' : 'Ask the owner or a branch manager.'),
                    ], 422);
                }
                $validated['custom_order_data']['below_minimum'] = ['minimum' => (int) $service->min_order_qty, 'quantity' => $bulkQty];
            }

            if (
                $service->hasType(Service::TYPE_ALTERATION_REPAIR)
                && trim($validated['custom_order_data']['pre_existing_damage_notes'] ?? '') === ''
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pre-existing damage/condition notes are required for alteration and repair jobs.',
                ], 422);
            }
        }

        // Only the owner and branch managers reach this method (route group); staff never create orders.

        $validated['order_number'] = $this->generateOrderNumber($store);
        $validated['tracking_code'] = $this->generateTrackingCode($store, $validated['order_number']);
        $validated['order_type'] = $validated['order_type'] ?? 'walk_in';
        // Every order made here goes through the ordering flow (quotation -> customer approval ->
        // deposit -> confirmed -> start). Repairs are exempt at the gate (OrderReadiness::gated).
        $validated['uses_confirmation_flow'] = true;
        // A request the shop writes itself is already accepted; only online requests wait for that yes.
        $validated['shop_accepted_at'] = now();

        // assigned_staff_id is no longer accepted from the client at creation
        // time — no more upfront "who's doing this" pre-assignment. It's
        // purely server-derived now, auto-set the moment anyone actually
        // moves the job into a production stage (see the auto-attribution
        // block in update()), same job_order_staff table the Staff
        // Productivity report already reads. Explicitly null, not unset —
        // same "column exists but missing from the create response until
        // re-fetched" bug class as quantity above: create()'s in-memory
        // model never reports an attribute it was never given at all.
        $validated['assigned_staff_id'] = null;

        // Auto-assign branch when not explicitly set, falling back to the
        // creator's own branch (staff/branch manager).
        $staffProfile = $request->user()->staffProfile;
        if ($staffProfile && empty($validated['store_branch_id'])) {
            $validated['store_branch_id'] = $staffProfile->store_branch_id;
        }

        // The owner (no staffProfile) has no branch to inherit from the block
        // above, so a job created without explicitly picking one used to be
        // saved with store_branch_id = NULL — permanently invisible the moment
        // anyone filters the Production Pipeline to a specific branch, since
        // `WHERE store_branch_id = ?` never matches NULL in SQL. Confirmed live:
        // an owner-created job vanished from the board under the default
        // branch view with no error or warning. Default to the store's main
        // branch instead, same as any other job with no other signal.
        if (empty($validated['store_branch_id'])) {
            $mainBranch = $store->branches()->where('is_main', true)->first();
            if ($mainBranch) {
                $validated['store_branch_id'] = $mainBranch->id;
            }
        }

        // Determine payment status based on total amount and balance
        $totalAmount = (float) $validated['total_amount'];
        $balance = (float) $validated['balance'];
        $initialPayment = $totalAmount - $balance;

        // A zero total is an order still waiting for its quotation — not a paid one.
        if ($balance <= 0 && $totalAmount > 0) {
            $validated['payment_status'] = 'paid';
        } elseif ($initialPayment > 0) {
            $validated['payment_status'] = 'partial';
        } else {
            $validated['payment_status'] = 'unpaid';
        }

        // Auto-calculates a starting rush fee when the owner/staff flags a job as
        // rush but doesn't type in their own figure — a sane default instead of a
        // blank field, never overriding an explicit value the caller actually
        // provided. Always editable afterward via the normal update() flow, same
        // as any other field — this only fills in a default.
        //
        // A repeat customer (3+ prior jobs at this store — the same signal already
        // shown on the Job Detail page as customer_job_count) gets a reduced rate
        // as a real loyalty perk. Deliberately NOT keyed off suki_tag: that field
        // holds a customer-type classification (b2b_suki/reseller/walk_in_retail),
        // not a repeat-customer flag, so it isn't the right signal for this.
        if (! empty($validated['is_rush']) && empty($validated['rush_fee']) && $totalAmount > 0) {
            $priorJobCount = JobOrder::where('store_id', $store->id)
                ->where('customer_id', $validated['customer_id'])
                ->count();
            $rushFeePercent = $priorJobCount >= 3 ? 0.15 : 0.30;
            $validated['rush_fee'] = round($totalAmount * $rushFeePercent, 2);
        }

        // Resolve the linked appointment (if any) before creating the job so its
        // design-reference photos/link — attached by the customer at booking time —
        // carry over onto the job the assigned staff actually work from, instead of
        // being stranded on an appointment record nobody looks at again.
        $appointment = null;
        if ($request->filled('appointment_id')) {
            $candidate = Appointment::find($request->appointment_id);
            if ($candidate && $candidate->store_id === $store->id) {
                if (! in_array($candidate->status, ['confirmed', 'in_progress', 'completed'], true)) {
                    return response()->json(['success' => false, 'message' => 'Approve the appointment first. A job order can only come from an approved visit.'], 422);
                }
                if ((int) $candidate->customer_id !== (int) $validated['customer_id']) {
                    return response()->json(['success' => false, 'message' => 'That appointment belongs to a different customer.'], 422);
                }
                if ($candidate->job_order_id) {
                    return response()->json(['success' => false, 'message' => 'A job order was already made from this appointment.'], 422);
                }
            }
            if ($candidate && $candidate->store_id === $store->id) {
                $appointment = $candidate;
                // Fill only what the form did not send at all (an older screen). A field the form sent, even empty, is the
                // person's choice: removing a photo, a design or a note must stay removed.
                $absent = fn (string $key) => ! $request->has($key);
                if ($absent('reference_images') && ! empty($appointment->reference_images)) {
                    $validated['reference_images'] = $appointment->reference_images;
                }
                if ($absent('reference_link') && ! empty($appointment->reference_link)) {
                    $validated['reference_link'] = $appointment->reference_link;
                }
                if ($absent('garment_category') && ! empty($appointment->garment_category)) {
                    $validated['garment_category'] = $appointment->garment_category;
                }
                if ($absent('catalog_item_id') && ! empty($appointment->catalog_item_id)) {
                    $validated['catalog_item_id'] = $appointment->catalog_item_id;
                }
                // What was said in the consultation travels onto the order, so the people who make it have it too.
                if ($absent('design_source') && ! empty($appointment->design_source)) {
                    $validated['design_source'] = $appointment->design_source;
                }
                if ($absent('design_link') && ! empty($appointment->design_link)) {
                    $validated['design_link'] = $appointment->design_link;
                }
                if ($absent('consultation_notes')) {
                    $validated['consultation_notes'] = $this->consultationNotesFrom($appointment);
                }
                if ($absent('store_branch_id') && ! empty($appointment->store_branch_id)) {
                    $validated['store_branch_id'] = $appointment->store_branch_id;
                }
            }
        }

        // Server-authoritative intake channel — auto-tagged from the linked
        // appointment's own channel (online booking vs walk-in) instead of a
        // manual "How did they order?" toggle, which staff had to remember
        // to set correctly by hand. Any client-supplied intake_channel is
        // ignored on purpose.
        $validated['intake_channel'] = $appointment?->intake_channel === 'online' ? 'online' : 'walk_in';

        // Same idea as the appointment carry-over above, but for a Design Catalog
        // item the owner/staff explicitly linked at creation time (e.g. "make this
        // customer's order using this catalog design") — entirely optional, most
        // jobs are fully custom and have no catalog_item_id at all. Falls back to
        // the item's primary gallery photo, then its fabric swatch, so staff still
        // see a reference even for catalog items with no gallery images uploaded.
        if (! empty($validated['catalog_item_id'])) {
            $catalogItem = CatalogItem::with('images')->find($validated['catalog_item_id']);
            if ($catalogItem && $catalogItem->store_id === $store->id) {
                if (empty($validated['reference_images'])) {
                    $catalogImage = $catalogItem->images->firstWhere('is_primary', true)?->image_url
                        ?? $catalogItem->images->first()?->image_url
                        ?? $catalogItem->fabric_image_url;
                    if ($catalogImage) {
                        $validated['reference_images'] = [$catalogImage];
                    }
                }
                if (empty($validated['garment_category']) && ! empty($catalogItem->category)) {
                    $validated['garment_category'] = $catalogItem->category;
                }
            }
        }

        // A branch manager works inside their own branch: the order, and the visit it comes from, must be that branch's.
        $pinned = $request->user()->hasRole('store_owner') ? null : $request->user()->staffProfile?->store_branch_id;
        if ($pinned) {
            $outside = fn ($branch) => $branch && (int) $branch !== (int) $pinned;
            if ($outside($validated['store_branch_id'] ?? null) || $outside($appointment?->store_branch_id)) {
                return response()->json(['success' => false, 'message' => 'You can only create orders for your own branch.'], 403);
            }
            $validated['store_branch_id'] = (int) $pinned;
        }

        // A wearer may only be linked to a profile that belongs to this customer in this shop: no one else's measurements.
        $linked = collect($validated['custom_order_data']['team_roster'] ?? [])->pluck('measurement_id')->filter()->map(fn ($id) => (int) $id)->unique();
        if ($linked->isNotEmpty() && Measurement::where('store_id', $store->id)->where('customer_id', $validated['customer_id'])->whereIn('id', $linked)->count() !== $linked->count()) {
            return response()->json(['success' => false, 'message' => 'A person on the team list is linked to measurements that do not belong to this customer.'], 422);
        }

        $jobOrder = $store->jobOrders()->create($validated);

        // Auto-record the order's measurements (custom_order_data) as a versioned
        // measurement profile for the customer and link the job to it.
        // Shop-entered data keeps the default 'store_owner' source; a future
        // customer-self-service endpoint would pass 'customer' explicitly.
        JobMeasurementSync::syncForJob($store, $jobOrder);
        // A team order's Custom Fit wearers each get their own profile, linked from their row.
        JobMeasurementSync::syncRoster($store, $jobOrder);

        // Link back to the appointment now that the job exists
        if ($appointment) {
            // 'outcome' has its own dedicated dropdown on the "Complete
            // Appointment" modal, but a job order is just as often created
            // straight from an appointment via this appointment_id linkage
            // without ever going through that modal — leaving outcome null
            // forever even though the appointment demonstrably *did*
            // convert. Set it here too so outcome-based reporting (booking
            // conversion rate) reflects real conversions regardless of
            // which of the two paths the owner used. Never overwrites an
            // outcome that's already set.
            // Creating the Production Order closes the visit: completed, converted to a job.
            $appointment->update(array_filter([
                'job_order_id' => $jobOrder->id,
                'outcome' => 'converted_to_job',
                'status' => in_array($appointment->status, ['pending', 'confirmed', 'in_progress'], true) ? 'completed' : null,
            ]));
        }

        // Record initial payment history if downpayment occurred
        if ($initialPayment > 0) {
            $jobOrder->payments()->create([
                'amount' => $initialPayment,
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'recorded_by' => $request->user()->id,
                // Money already in hand at the counter: the balance was reduced for it, so it counts as verified.
                'verified_at' => now(),
                'verified_by' => $request->user()->id,
                'notes' => 'Initial downpayment recorded during order creation.',
            ]);
        }

        // More services asked for at the same time: each is its own linked order.
        $extras = $request->validated()['extra_services'] ?? [];
        $extraOrders = $extras ? \App\Support\OrderBatches::attachExtraServices($store, $jobOrder, $extras) : [];
        $jobOrder->setAttribute('extra_orders', collect($extraOrders)->map(fn ($o) => ['id' => $o->id, 'order_number' => $o->order_number])->values());

        $jobOrder->load(['customer:id,name', 'service', 'assignedStaff:id,name', 'staffStages', 'catalogItem:id,name,fabric_image_url', 'catalogItem.images']);

        // Notify store owner of the new job order
        $storeOwner = $store->owner;
        if ($storeOwner) {
            $storeOwner->notify(new NewJobOrderNotification($jobOrder));
        }
        // The customer hears it was written up, with the code to follow it (their extra orders are listed on the same page).
        $more = count($extraOrders);
        SafeNotify::send($jobOrder->customer()->first(), new \App\Notifications\OrderUpdateNotification(
            $jobOrder,
            'created',
            'Order received',
            "Your order {$jobOrder->order_number} at {$store->name} has been written up".($more > 0 ? " (with {$more} more linked ".($more === 1 ? 'order' : 'orders').')' : '')
                .". Follow it under My Orders or with the tracking code {$jobOrder->tracking_code}.",
            $request->user(),
        ));

        return response()->json([
            'success' => true,
            'data' => $jobOrder,
        ], 201);
    }

    public function show(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $jobOrder->load(['customer', 'service', 'assignedStaff', 'measurement', 'staffStages', 'payments.recordedBy:id,name', 'catalogItem:id,name,fabric_image_url,service_id,price,pricing_mode,extra_charge', 'catalogItem.images', 'branch:id,name']);
        // The order this one follows up, and the follow-ups made from it (a free fix is flagged for the label).
        $jobOrder->load(['followUpOf:id,order_number', 'followUps:id,order_number,status,follow_up_of_id,custom_order_data']);
        $jobOrder->followUps->each(function (JobOrder $f) {
            $f->setAttribute('free_fix', (bool) ($f->custom_order_data['free_fix'] ?? false));
            $f->makeHidden('custom_order_data');
        });
        // The design's own starting price (its service's price, an extra charge, or a quote) -- the quotation starts from it.
        if ($jobOrder->catalogItem) {
            DesignPricing::attach([$jobOrder->catalogItem]);
        }

        // Repeat-customer context surfaced right on the job so the owner can
        // decide on a manual discount ("this is their 5th order") without
        // having to jump to the Customers page first.
        $jobOrder->customer_job_count = $jobOrder->customer_id
            ? JobOrder::where('store_id', $store->id)->where('customer_id', $jobOrder->customer_id)->count()
            : 0;

        // A job order pins measurement_id at creation and nothing ever
        // re-points it — Measurement::update() always closes out the old
        // version and creates a new one (see MeasurementController@update),
        // so a correction made after this job started (e.g. staff re-measures
        // at fitting and fixes a wrong waist) never reaches a job already in
        // production unless someone notices and manually re-links it. Surface
        // that gap here instead of leaving it silent: tell the frontend
        // whether a newer version exists and what its id is.
        if ($jobOrder->measurement) {
            $currentVersion = Measurement::where('store_id', $store->id)
                ->where('customer_id', $jobOrder->measurement->customer_id)
                ->where('profile_name', $jobOrder->measurement->profile_name)
                ->whereNull('superseded_at')
                ->first();
            $jobOrder->measurement->is_stale = $currentVersion && $currentVersion->id !== $jobOrder->measurement->id;
            $jobOrder->measurement->current_version_id = $currentVersion?->id;
        }

        // The group this order belongs to (batches), with amounts only for people allowed to see money.
        $seesMoney = $request->user()->hasRole('store_owner') || $request->user()->hasRole('branch_manager');
        $jobOrder->setAttribute('batch', \App\Support\OrderBatches::summary($jobOrder, $seesMoney));
        $jobOrder->setAttribute('can_split', $seesMoney && \App\Support\OrderBatches::canSplit($jobOrder));
        $jobOrder->seenBy($request->user());

        return response()->json([
            'success' => true,
            'data' => $jobOrder,
        ]);
    }

    public function update(UpdateJobOrderRequest $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $validated = $request->validated();
        // Plain staff only work on production: notes, the completion photo and production-stage moves. The raw request is
        // checked, not just the validated fields, so a price or payment value sent along is refused instead of silently dropped.
        if (StaffJobUpdateGuard::appliesTo($request->user()) && StaffJobUpdateGuard::changesMoreThanProduction($jobOrder, $request->all())) {
            return response()->json(['success' => false, 'message' => StaffJobUpdateGuard::MESSAGE], 403);
        }
        // Inside production the order moves only through Next / Back / Hold / Resume (OrderProductionController).
        if (($validated['status'] ?? null) && $validated['status'] !== $jobOrder->status
            && ($stop = OrderReadiness::productionJumpError($jobOrder, $jobOrder->status, $validated['status']))) {
            return response()->json(['success' => false, 'message' => $stop], 422);
        }

        return $this->applyUpdate($request, $store, $jobOrder, $validated);
    }

    /**
     * The update itself, once access and the request are checked: gates, deposit rule, stamps, staff attribution,
     * hold clock and customer / owner notifications. OrderProductionController calls it too, so Next / Back / Hold /
     * Resume have exactly the side effects of a status change.
     */
    public function applyUpdate(Request $request, Store $store, JobOrder $jobOrder, array $validated): JsonResponse
    {
        $oldStatus = $jobOrder->status;
        $newStatus = $validated['status'] ?? null;
        if ($newStatus === 'cancelled' && $oldStatus !== 'cancelled') {
            return response()->json(['success' => false, 'message' => 'Use Cancel this order.'], 422);
        }
        // Ordering-flow orders (not repairs) follow the workflow sequence: ... -> Quality Check -> Ready for
        // Final Fitting / Collection -> Customer Fitting -> Adjustment -> Balance & Handover -> Completed.
        // The customer is invited to the fitting/collection when the order becomes Ready for Final Fitting /
        // Collection; older orders keep inviting them when it becomes Ready for Fitting.
        $flow = OrderReadiness::gated($jobOrder);
        $fittingTrigger = $flow ? 'ready_for_pickup' : 'ready_for_fitting';
        $startedNow = false;

        // Same auto-calculated rush fee default as store() (including the
        // repeat-customer discount) — only fills in a starting figure when
        // is_rush is being newly turned on here without the caller also
        // supplying their own rush_fee; never overrides an explicit value,
        // and never touches a job that was already rush.
        if (
            ! empty($validated['is_rush']) && ! $jobOrder->is_rush
            && ! isset($validated['rush_fee']) && (float) $jobOrder->total_amount > 0
        ) {
            $priorJobCount = JobOrder::where('store_id', $jobOrder->store_id)
                ->where('customer_id', $jobOrder->customer_id)
                ->count();
            $rushFeePercent = $priorJobCount >= 3 ? 0.15 : 0.30;
            $validated['rush_fee'] = round((float) $jobOrder->total_amount * $rushFeePercent, 2);
        }

        // Server-side backstop for the same "No DP, No Cut" / "No Balance, No Claim"
        // rules the Kanban UI enforces — this endpoint is also reachable from the
        // Job Detail page's own status dropdown, so the rule has to live here too,
        // not just in one frontend component.
        // On the ordering flow only the owner or a branch manager confirms (authorizes production) or cancels.
        $isMoneyRole = $request->user()->hasRole('store_owner') || $request->user()->hasRole('branch_manager');
        if ($flow && $newStatus && $newStatus !== $oldStatus && in_array($newStatus, ['confirmed', 'cancelled'], true) && ! $isMoneyRole) {
            return response()->json(['success' => false, 'message' => 'Only the owner or a branch manager can confirm or cancel an order.'], 403);
        }

        if ($newStatus && $newStatus !== $oldStatus) {
            // Ordering flow: confirming needs a final, customer-approved quotation and a verified
            // deposit; starting work needs materials, measurements and a tailor. Same rules as the
            // dashboard checklist (App\Support\OrderReadiness) -- orders without the flow are skipped.
            if ($gate = OrderReadiness::transitionError($jobOrder, $oldStatus, $newStatus)) {
                if (isset($gate['message'])) {
                    $gate['message'] = JobOrder::withoutAmounts($gate['message'], $request->user());
                }

                return response()->json(['success' => false] + $gate, 422);
            }

            $totalAmount = (float) $jobOrder->total_amount;
            // A discount lowers balance, not total, so it must be subtracted
            // here too — otherwise a discount counts as cash paid and a
            // discounted job can pass the 50% gate without enough payment.
            $discount = (float) $jobOrder->discount_amount;
            $paidSoFar = $totalAmount - (float) $jobOrder->balance - $discount;
            $amountDue = $totalAmount - $discount;

            // "No DP, No Layout, No Cut": matches the 50% downpayment policy
            // shown on the Job Detail page and Kanban board. 'pending' and
            // 'design' are deliberately excluded — no fabric or material is
            // committed yet at those stages, only once Pattern Making (or
            // its Bulk Order Override, Mass Cutting & Printing) starts.
            // Repairs skip this unless the shop opted in (requiresDownpaymentFor).
            // The required share is this job's snapshotted payment policy
            // (none / full / deposit / custom percent), not a fixed 50%.
            $fraction = $jobOrder->requiredDepositFraction();
            // The deposit was verified when the order was confirmed; a later approved change raises the total, but
            // that must not lock an order that is already in production out of its next stage.
            $depositAlreadyCleared = $flow && $jobOrder->production_started_at !== null;
            if (! $depositAlreadyCleared && $jobOrder->requiresDownpaymentFor($newStatus) && $amountDue > 0 && $paidSoFar + 0.005 < ($amountDue * $fraction)) {
                $label = $fraction >= 1 ? 'The full payment' : 'A '.(int) round($fraction * 100).'% downpayment';
                return response()->json([
                    'success' => false,
                    'message' => $label.' must be collected before production can start on this job.',
                ], 422);
            }

            // Final fitting, per this job's snapshotted requirement. 'none' means the
            // shop does not fit this order, so it never enters the fitting stage;
            // 'required' means it cannot be handed over before a fitting is done.
            if ($newStatus === 'ready_for_fitting' && $jobOrder->fitting_requirement === 'none') {
                return response()->json([
                    'success' => false,
                    'message' => 'This order does not need a final fitting. Move it to QC / Ironing or Ready for Pickup instead.',
                ], 422);
            }
            if (
                ! $flow
                && in_array($newStatus, ['ready_for_pickup', 'completed'], true)
                && $jobOrder->fitting_requirement === 'required'
                && ! $jobOrder->fittings()->where('appointment_type', 'fitting')->where('status', 'completed')->exists()
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'A final fitting is required for this order. Complete the fitting appointment before handing it over.',
                ], 422);
            }

            if ($newStatus === 'completed' && (float) $jobOrder->balance > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'The remaining balance must be paid before this job can be marked completed.',
                ], 422);
            }

            // Completion photo upload stays available (upload → column →
            // print ticket) but is no longer a hard gate on reaching Ready
            // for Pickup — per explicit owner request, it's optional, not
            // required. Was previously enforced here ("No QC Photo, No
            // Ready for Pickup"); removed rather than left as dead
            // commented-out logic.

            // 'rejected' has its own dedicated endpoint (JobOrderController@rejectOrder)
            // with rules this generic endpoint doesn't enforce: owner/branch_manager
            // only (staff can reach this route), pending-only, and a required reason.
            // Blocking it here closes off a bypass of all three via a plain status edit.
            if ($newStatus === 'rejected') {
                return response()->json([
                    'success' => false,
                    'message' => 'Use the dedicated reject endpoint to decline a pending order.',
                ], 422);
            }

            // Fitting limit, 'block' policy — the counterpart to the 'fee'
            // policy's charge below (Phase 3 block, after save). Checked
            // pre-save, same style as the DP gate above, so a blocked
            // transition never partially applies. 'fee' policy (default)
            // is unaffected here; it's still handled after save, where the
            // auto-fitting appointment actually gets created.
            if ($newStatus === $fittingTrigger && $oldStatus !== $fittingTrigger && $store->fitting_limit_policy === 'block' && (! $flow || OrderReadiness::fittingNeeded($jobOrder))) {
                $priorFittingCount = $jobOrder->fittings()->where('appointment_type', 'fitting')->count();
                if ($store->fitting_limit && $priorFittingCount >= $store->fitting_limit) {
                    return response()->json([
                        'success' => false,
                        'message' => "This job has already used its {$store->fitting_limit} included fitting session(s), and this shop does not allow additional fittings beyond the limit. Move it to QC/Ironing or Ready for Pickup instead, or contact the customer directly.",
                    ], 422);
                }
            }
        }

        // Measurement snapshot lock — mirrors the "No DP, No Cut" boundary
        // above (STAGES_REQUIRING_DOWNPAYMENT = production has actually
        // started). Before that point (pending/design/queued), re-pointing
        // measurement_id is the "Sync with Current Version" action's normal
        // job — freely switching to whatever the customer's latest profile
        // is. Once production has started, the whole point of pinning
        // measurement_id at creation is that it stays the exact snapshot
        // cutting/sewing was done against; letting it silently swap out
        // mid-job (even to a "newer" version) would cut/sew to numbers that
        // no longer match what was actually already worked. Checked against
        // $oldStatus (the job's status *before* this request), not
        // $newStatus, so a request that simultaneously advances the job
        // into production can't launder a stale-snapshot swap through the
        // same call.
        if (
            array_key_exists('measurement_id', $validated)
            && (int) ($validated['measurement_id'] ?? 0) !== (int) $jobOrder->measurement_id
            && in_array($oldStatus, JobOrder::STAGES_REQUIRING_DOWNPAYMENT, true)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'This job has already started production — its measurement snapshot is locked and can no longer be switched to a different version.',
            ], 422);
        }

        // Pieces can be corrected while the order is still a request (the quotation window does this);
        // after it is confirmed the price, deposit and handover all rest on that number.
        if (array_key_exists('quantity', $validated) && (int) $validated['quantity'] !== (int) $jobOrder->quantity && $oldStatus !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Pieces can only change before the order is confirmed.'], 422);
        }

        if (array_key_exists('custom_order_data', $validated)) {
            $existingData = $jobOrder->custom_order_data ?? [];
            // What the order was made from (option, coverage, unit) was fixed when it was created: an edit cannot change it.
            $incoming = $validated['custom_order_data'] ?? [];
            foreach (['option', 'option_covers', 'option_sold_as'] as $key) {
                unset($incoming[$key]);
            }
            $validated['custom_order_data'] = array_merge($existingData, $incoming);

            // A group order's team list edited on the order page: the people count follows the list, and the number of
            // people or pieces (what the price, deposit and handover rest on) can only change while it is still a request.
            $newRoster = $validated['custom_order_data']['team_roster'] ?? null;
            $oldRoster = $existingData['team_roster'] ?? null;
            if ((int) ($existingData['people_count'] ?? 0) > 0 && is_array($newRoster) && count($newRoster) > 0) {
                $changed = count($newRoster) !== count((array) $oldRoster)
                    || JobOrder::rosterPieces($newRoster) !== JobOrder::rosterPieces(is_array($oldRoster) ? $oldRoster : []);
                if ($changed && $oldStatus !== 'pending') {
                    return response()->json(['success' => false, 'message' => 'People and pieces on the team list can only change before the order is confirmed.'], 422);
                }
                $validated['custom_order_data']['people_count'] = count($newRoster);
            }

            // A wearer may only be linked to a profile of this order's customer in this shop, same as when the order is made.
            $linked = collect($newRoster ?? [])->pluck('measurement_id')->filter()->map(fn ($id) => (int) $id)->unique();
            if ($linked->isNotEmpty() && Measurement::where('store_id', $store->id)->where('customer_id', $jobOrder->customer_id)->whereIn('id', $linked)->count() !== $linked->count()) {
                return response()->json(['success' => false, 'message' => 'A person on the team list is linked to measurements that do not belong to this customer.'], 422);
            }
        }

        $jobOrder->update($validated);

        // Re-sync the measurement profile when the measurements or the linked
        // profile can have changed — superseding the old version rather than
        // overwriting it.
        if (array_key_exists('custom_order_data', $validated) || array_key_exists('measurement_id', $validated)) {
            JobMeasurementSync::syncForJob($store, $jobOrder);
        }

        // Ordering flow milestones, stamped by the server from the transition itself.
        if ($newStatus && $newStatus !== $oldStatus && $jobOrder->uses_confirmation_flow) {
            if ($jobOrder->status === 'confirmed' && ! $jobOrder->confirmed_at) {
                $jobOrder->forceFill([
                    'confirmed_at' => now(), 'production_authorized_at' => now(), 'production_authorized_by' => $request->user()->id,
                ])->save();
                OrderReadiness::syncAwaitingMaterials($jobOrder->fresh());
                $jobOrder->refresh();
            }
            if (JobOrder::startsProduction($jobOrder->status) && ! $jobOrder->production_started_at) {
                $jobOrder->forceFill(['production_started_at' => now()])->save();
                $startedNow = true;
            }
            // A quality check and a fitting result belong to one round: going back to production, to quality
            // check or to correction starts the next round, so each has to be passed again.
            if (in_array($jobOrder->status, [...JobOrder::PRODUCTION_TASKS, 'qc_ironing', 'final_adjustments'], true)) {
                $jobOrder->forceFill(['qc_passed_at' => null, 'qc_passed_by' => null, 'fitting_passed_at' => null])->save();
            }
            if ($oldStatus === 'ready_for_fitting' && $jobOrder->status === 'balance_handover') {
                $jobOrder->forceFill(['fitting_passed_at' => now()])->save();
            }
        }

        // Auto-derived production attribution: replaces the old "owner
        // manually pre-assigns a staff member to a stage before they can
        // touch it" model. Any staff member at the matching branch could
        // already update a job's status regardless of assignment (this
        // endpoint never checked staffStages before allowing it) — this
        // just makes that reality visible instead of requiring a separate
        // supervisory step first. Whoever actually moves the job into a
        // production stage is recorded as having worked that stage; whoever
        // moves it back OUT of one closes that stage's completed_at. Same
        // job_order_staff table and shape the Staff Productivity report
        // already reads (JobOrderStaff/StaffController::index) — jobs
        // touched, completion rate — nothing there needed to change, it's
        // just populated automatically now instead of via assignStaff().
        if ($newStatus && $newStatus !== $oldStatus) {
            $stageFor = fn (?string $status) => $status === 'mass_cutting_printing' ? 'pattern_making' : $status;
            $oldStage = $stageFor($oldStatus);
            $newStage = $stageFor($newStatus);
            $actorId = $request->user()->id;

            if ($oldStage && in_array($oldStage, JobOrder::STAFF_STAGES, true)) {
                DB::table('job_order_staff')
                    ->where('job_order_id', $jobOrder->id)
                    ->where('stage', $oldStage)
                    ->whereNull('completed_at')
                    ->update(['completed_at' => now()]);
            }

            if ($newStage && in_array($newStage, JobOrder::STAFF_STAGES, true)) {
                $existingId = DB::table('job_order_staff')
                    ->where('job_order_id', $jobOrder->id)
                    ->where('stage', $newStage)
                    ->value('id');

                if ($existingId) {
                    // completed_at reset to null too — covers a job re-entering
                    // a stage it already finished once before (e.g. a revert
                    // during final_adjustments rework), so a second real visit
                    // to this stage isn't shown as already completed.
                    DB::table('job_order_staff')->where('id', $existingId)->update(['user_id' => $actorId, 'completed_at' => null]);
                } else {
                    DB::table('job_order_staff')->insert([
                        'job_order_id' => $jobOrder->id,
                        'user_id' => $actorId,
                        'stage' => $newStage,
                        'assigned_at' => now(),
                        'completed_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $jobOrder->update(['assigned_staff_id' => $actorId]);
        }

        // Cancelling a job leaves its linked fitting appointment(s) dangling
        // otherwise — still 'pending'/'confirmed' on the calendar for a job
        // that no longer exists, which staff could show up for, and which
        // (once confirmed) blocks that time slot for a real future booking.
        // Confirmed live: cancelling JO-1005 left appointment #35 sitting at
        // 'pending' with no connection back to anything now dead.
        if ($jobOrder->status === 'cancelled' && $oldStatus !== 'cancelled') {
            $jobOrder->fittings()
                ->whereIn('status', ['pending', 'confirmed', 'in_progress'])
                ->get()
                ->each(function (Appointment $appointment) use ($request) {
                    $appointment->update(['status' => 'cancelled']);
                    $appointment->customer?->notify(new AppointmentStatusNotification($appointment, 'cancelled', null, $request->user()));
                });
        }

        // Tracks how many times a job has entered Final Adjustments, and when
        // the first one happened — lets the owner judge the store's own "1 free
        // adjustment within N days" policy manually (the exact policy varies
        // store to store, so this only surfaces the facts rather than enforcing
        // one hard-coded rule). Neither field is client-settable (not in
        // $fillable) — both are server-derived from the status transition itself.
        if ($jobOrder->status === 'final_adjustments' && $oldStatus !== 'final_adjustments') {
            $jobOrder->increment('adjustment_count');
            if (! $jobOrder->first_adjustment_at) {
                $jobOrder->forceFill(['first_adjustment_at' => now()])->save();
            }
        }

        // When the OWNER marks a job completed, stamp completion on its staff
        // assignments so "jobs completed" / productivity is derivable without a staff portal.
        if ($jobOrder->status === 'completed' && $oldStatus !== 'completed') {
            DB::table('job_order_staff')
                ->where('job_order_id', $jobOrder->id)
                ->whereNull('completed_at')
                ->update(['completed_at' => now()]);
        }

        // Same aging-alert pattern as ready_for_pickup_at below — on_hold is
        // correctly excluded from the overdue_jobs KPI (the owner paused it
        // on purpose, it's not "late"), but that left it with zero aging
        // visibility anywhere at all. Cleared back to null the moment the
        // job leaves on_hold, so a job that gets held twice gets a fresh
        // clock each time, not the first hold's stale timestamp.
        if ($jobOrder->status === 'on_hold' && $oldStatus !== 'on_hold') {
            $jobOrder->forceFill(['held_at' => now()])->save();
        } elseif ($oldStatus === 'on_hold' && $jobOrder->status !== 'on_hold' && $jobOrder->held_at) {
            $jobOrder->forceFill(['held_at' => null])->save();
        }

        if ($jobOrder->status === 'ready_for_pickup' && $oldStatus !== 'ready_for_pickup') {
            // Server-derived, not client-settable — start of the "unclaimed
            // pickup" aging clock the Reports page now surfaces, so an item
            // sitting on the rack gets flagged before it becomes a forfeited
            // deposit (cancellation_reason=forfeited_deposit_abandoned) instead
            // of only after.
            $jobOrder->forceFill(['ready_for_pickup_at' => now()])->save();
            SafeNotify::send($jobOrder->customer, new OrderReadyNotification($jobOrder, $request->user()));
            $this->notifyOwnerOfActivity($request, $store, [
                'type' => 'job_ready_for_pickup',
                'title' => 'Job Order Ready for Pickup',
                'message' => "{$jobOrder->order_number} ({$jobOrder->customer?->name}) is ready for pickup.",
                'url' => '/dashboard/jobs/'.$jobOrder->id,
                'extra' => ['job_order_id' => $jobOrder->id, 'order_number' => $jobOrder->order_number],
            ], $jobOrder);
        }

        // Phase 3: "Ready for Fitting" auto-triggers a Fitting appointment for
        // the customer instead of the owner having to remember to book one
        // manually. Guarded against duplicates so looping back through
        // Final Adjustments and forward again doesn't spawn a second one —
        // the placeholder date/time is a rough default the owner/staff is
        // expected to actually reschedule with the customer.
        if ($jobOrder->status === $fittingTrigger && $oldStatus !== $fittingTrigger && (! $flow || OrderReadiness::fittingNeeded($jobOrder))) {
            // Locking the job order row for the duration of the check+create
            // serializes two near-simultaneous transitions into
            // 'ready_for_fitting' for the same job — without this, both
            // requests could pass the "no open fitting exists yet" check
            // before either INSERT commits, spawning two auto-fitting
            // appointments instead of one.
            DB::transaction(function () use ($store, $jobOrder) {
                JobOrder::where('id', $jobOrder->id)->lockForUpdate()->firstOrFail();

                $jobOrder->createFittingPlaceholder($store);
            });
        }

        // Matches the thesis's own scope objective — customers "receive automated
        // SMS or email notifications at each stage transition, from placement to
        // final pickup." ready_for_pickup keeps its dedicated notification above;
        // every other production/fulfillment stage is covered here. Derived from
        // JobOrder::STATUSES (rather than a hand-kept literal list) so a future
        // pipeline status is notifiable by default instead of silently excluded.
        $otherNotifiableStatuses = array_diff(JobOrder::STATUSES, ['pending', 'ready_for_pickup']);
        if (in_array($jobOrder->status, $otherNotifiableStatuses, true) && $jobOrder->status !== $oldStatus) {
            // On the ordering flow the customer is told when work starts and when the order is ready (above) --
            // not about each internal task, the quality check, the fitting itself or the handover.
            $customerQuiet = $flow ? ['qc_ironing', 'ready_for_fitting', 'balance_handover', ...($startedNow ? [] : JobOrder::PRODUCTION_TASKS)] : [];
            if (! in_array($jobOrder->status, $customerQuiet, true)) {
                // A failed email (bad SMTP login, provider down) must not turn an already-saved stage change into a 500.
                SafeNotify::send($jobOrder->customer, new JobStatusUpdatedNotification($jobOrder, $jobOrder->status, $request->user()));
            }
            $this->notifyOwnerOfActivity($request, $store, [
                'type' => 'job_'.$jobOrder->status,
                'title' => 'Job Order Updated',
                'message' => "{$jobOrder->order_number} ({$jobOrder->customer?->name}) moved to ".str_replace('_', ' ', $jobOrder->status).'.',
                'url' => '/dashboard/jobs/'.$jobOrder->id,
                'extra' => ['job_order_id' => $jobOrder->id, 'order_number' => $jobOrder->order_number],
            ], $jobOrder);
        }

        return response()->json([
            'success' => true,
            'data' => $jobOrder->seenBy($request->user()),
        ]);
    }

    public function pay(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'sometimes|string|in:'.implode(',', \App\Models\Payment::METHODS),
            // Where it was made and what it is for — both optional (type is worked out when absent).
            'source' => 'sometimes|in:'.implode(',', \App\Models\Payment::SOURCES),
            'type' => 'sometimes|nullable|in:'.implode(',', \App\Models\Payment::TYPES),
            'payment_method_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('payment_methods', 'id')->where('store_id', $store->id)],
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            // A GCash/PayMaya payment is the artifact being verified against —
            // required, not optional, now that it actually gates something
            // (see the pending-verification handling below). Cash needs no
            // proof: the money is physically in hand at the counter.
            'receipt_path' => [\Illuminate\Validation\Rule::requiredIf(fn () => $request->input('payment_method', 'cash') !== 'cash'), 'nullable', 'string', 'max:2048'],
            'cash_tendered' => [\Illuminate\Validation\Rule::requiredIf(fn () => $request->input('payment_method', 'cash') === 'cash'), 'nullable', 'numeric', 'min:0'],
        ]);

        $paymentMethod = $validated['payment_method'] ?? 'cash';
        $paymentAmount = (float) $validated['amount'];

        $cashTendered = null;
        $changeAmount = null;
        if ($paymentMethod === 'cash') {
            $cashTendered = (float) $validated['cash_tendered'];
            if ($cashTendered < $paymentAmount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cash tendered cannot be less than the amount being paid.',
                ], 422);
            }
            $changeAmount = round($cashTendered - $paymentAmount, 2);
        }

        // A GCash/bank reference number reused across two different orders
        // is exactly what a reused-screenshot scam looks like — the same
        // proof-of-payment submitted twice to get credited twice. Warns
        // rather than blocks: legitimate reference collisions do happen
        // (some banks' reference formats aren't globally unique), so this
        // surfaces the fact for the staff member logging it to judge, the
        // same "surface facts, don't hard-code a rule" approach already
        // used for adjustment_count.
        $duplicateReferenceWarning = null;
        if (! empty($validated['reference'])) {
            $duplicateOrder = JobOrder::where('store_id', $store->id)
                ->whereHas('payments', function ($q) use ($validated) {
                    $q->where('reference', $validated['reference'])->whereNull('rejected_at');
                })
                ->first();
            if ($duplicateOrder) {
                $duplicateReferenceWarning = "This reference number was already used on order {$duplicateOrder->order_number} — double-check this isn't a reused receipt before accepting.";
            }
        }

        $isCash = $paymentMethod === 'cash';

        try {
            // Lock the row for the duration of the transaction so two payments
            // submitted at nearly the same time (e.g. two staff on the same job)
            // can't both read the same starting balance and overpay/double-count.
            DB::transaction(function () use ($jobOrder, $paymentAmount, $paymentMethod, $isCash, $cashTendered, $changeAmount, $validated, $request) {
                $locked = JobOrder::where('id', $jobOrder->id)->lockForUpdate()->firstOrFail();

                // Cash is verified on the spot — the money is already in the
                // till, so it applies to the balance immediately, same as
                // before this change. GCash/PayMaya only ever prove a screenshot
                // was uploaded, not that the money actually landed, so those
                // stay unapplied (balance untouched) until an owner/branch
                // manager calls verifyPayment() below.
                if ($isCash) {
                    $currentBalance = (float) $locked->balance;
                    if ($paymentAmount > $currentBalance) {
                        throw new \RuntimeException('Payment exceeds remaining balance');
                    }

                    $newBalance = round($currentBalance - $paymentAmount, 2);
                    $locked->update([
                        'balance' => $newBalance,
                        'payment_status' => $newBalance <= 0 ? 'paid' : 'partial',
                    ]);
                } else {
                    // Still has to fit under the current balance so a customer
                    // can't "pay" more than what's owed even before verification.
                    if ($paymentAmount > (float) $locked->balance) {
                        throw new \RuntimeException('Payment exceeds remaining balance');
                    }
                }

                $locked->payments()->create([
                    'amount' => $paymentAmount,
                    'payment_method' => $paymentMethod,
                    'source' => $validated['source'] ?? 'walk_in',
                    'type' => $validated['type'] ?? \App\Models\Payment::inferType($locked, $paymentAmount),
                    'payment_method_id' => $validated['payment_method_id'] ?? null,
                    'reference' => $validated['reference'] ?? null,
                    'recorded_by' => $request->user()->id,
                    'notes' => $validated['notes'] ?? null,
                    'receipt_path' => $validated['receipt_path'] ?? null,
                    'cash_tendered' => $cashTendered,
                    'change_amount' => $changeAmount,
                    'verified_at' => $isCash ? now() : null,
                    'verified_by' => $isCash ? $request->user()->id : null,
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        // Notify store owner of the payment — a GCash/PayMaya one still needs
        // their (or a branch manager's) action to verify it, so the message
        // makes that explicit instead of reading like a done deal.
        $storeOwner = $store->owner;
        if ($storeOwner) {
            $storeOwner->notify(new PaymentReceivedNotification($jobOrder, $paymentAmount, ! $isCash));
        }

        return response()->json([
            'success' => true,
            'message' => $isCash ? 'Payment logged successfully' : 'Payment submitted — pending verification before it applies to the balance.',
            'warning' => $duplicateReferenceWarning,
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff', 'payments.recordedBy:id,name', 'payments.verifiedBy:id,name', 'staffStages']),
        ]);
    }

    /**
     * Owner/branch-manager-only confirmation that a GCash/PayMaya payment
     * actually landed (the counterpart to cash, which auto-verifies at
     * creation since the money is already in hand). Only once this fires
     * does the payment apply to the job's balance/payment_status — mirrors
     * pay()'s own locked-transaction math, just deferred.
     */
    public function verifyPayment(Request $request, Store $store, JobOrder $jobOrder, Payment $payment): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id || $payment->job_order_id !== $jobOrder->id) {
            return response()->json(['success' => false, 'message' => 'Payment not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        try {
            DB::transaction(function () use ($jobOrder, $payment, $request) {
                $lockedPayment = Payment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

                if ($lockedPayment->rejected_at !== null) {
                    throw new \RuntimeException('This payment was already rejected and can no longer be verified.');
                }
                if ($lockedPayment->verified_at !== null) {
                    throw new \RuntimeException('This payment is already verified.');
                }

                $locked = JobOrder::where('id', $jobOrder->id)->lockForUpdate()->firstOrFail();

                $currentBalance = (float) $locked->balance;
                $amount = (float) $lockedPayment->amount;
                if ($amount > $currentBalance) {
                    throw new \RuntimeException('This payment amount no longer fits the remaining balance — it may have been overtaken by another payment since it was submitted.');
                }

                $newBalance = round($currentBalance - $amount, 2);
                $locked->update([
                    'balance' => $newBalance,
                    'payment_status' => $newBalance <= 0 ? 'paid' : 'partial',
                ]);

                $lockedPayment->update([
                    'verified_at' => now(),
                    'verified_by' => $request->user()->id,
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment verified.',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff', 'payments.recordedBy:id,name', 'payments.verifiedBy:id,name', 'staffStages']),
        ]);
    }

    /**
     * A one-time, in-the-moment discount the owner grants on an existing job
     * (e.g. a repeat customer) — not a standing coupon/promo code. Reduces
     * the remaining balance directly and is logged to the audit trail, same
     * pattern as AppointmentController's reschedule/complete audit entries.
     */
    public function applyDiscount(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:500',
        ]);

        $discountAmount = (float) $validated['amount'];

        try {
            DB::transaction(function () use ($jobOrder, $discountAmount, $validated, $request) {
                $locked = JobOrder::where('id', $jobOrder->id)->lockForUpdate()->firstOrFail();

                $currentBalance = (float) $locked->balance;
                if ($discountAmount > $currentBalance) {
                    throw new \RuntimeException('Discount cannot exceed the remaining balance (₱'.number_format($currentBalance, 2).').');
                }

                $newBalance = round($currentBalance - $discountAmount, 2);
                $newDiscountTotal = round((float) ($locked->discount_amount ?? 0) + $discountAmount, 2);

                $locked->update([
                    'balance' => $newBalance,
                    'discount_amount' => $newDiscountTotal,
                    'payment_status' => $newBalance <= 0 ? 'paid' : $locked->payment_status,
                    // The reason is shown on the receipt too ("bulk 20 pcs"); the audit log below keeps every one.
                    'custom_order_data' => ! empty($validated['reason'])
                        ? array_merge((array) $locked->custom_order_data, ['discount_reason' => $validated['reason']])
                        : $locked->custom_order_data,
                ]);

                $store = $locked->store;
                $store->auditLogs()->create([
                    'user_id' => $request->user()->id,
                    'action' => 'discount_applied',
                    'model_type' => JobOrder::class,
                    'model_id' => $locked->id,
                    'payload' => [
                        'amount' => $discountAmount,
                        'reason' => $validated['reason'] ?? null,
                    ],
                    'ip_address' => $request->ip(),
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Discount applied successfully.',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff', 'payments.recordedBy:id,name', 'staffStages']),
        ]);
    }

    /**
     * Undoes the discount on an order: the discount goes back onto the balance (never onto total_amount),
     * the payment status is worked out again, and the removal is logged with the old amount and reason.
     */
    public function removeDiscount(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        try {
            DB::transaction(function () use ($jobOrder, $request) {
                $locked = JobOrder::where('id', $jobOrder->id)->lockForUpdate()->firstOrFail();

                if ($locked->status === 'completed') {
                    throw new \RuntimeException('This order is completed — its payments can no longer change.');
                }
                if (in_array($locked->status, ['cancelled', 'rejected'], true)) {
                    throw new \RuntimeException('This order is '.$locked->status.' — its payments can no longer change.');
                }

                $discount = round((float) ($locked->discount_amount ?? 0), 2);
                if ($discount <= 0) {
                    throw new \RuntimeException('This order has no discount.');
                }

                $newBalance = round((float) $locked->balance + $discount, 2);
                $data = (array) $locked->custom_order_data;
                $oldReason = $data['discount_reason'] ?? null;
                unset($data['discount_reason']);

                $locked->update([
                    'balance' => $newBalance,
                    'discount_amount' => 0,
                    'payment_status' => $newBalance <= 0.005
                        ? 'paid'
                        : (((float) $locked->total_amount - $newBalance) > 0.005 ? 'partial' : 'unpaid'),
                    'custom_order_data' => $data,
                ]);

                $locked->store->auditLogs()->create([
                    'user_id' => $request->user()->id,
                    'action' => 'discount_removed',
                    'model_type' => JobOrder::class,
                    'model_id' => $locked->id,
                    'payload' => [
                        'amount' => $discount,
                        'reason' => $oldReason,
                    ],
                    'ip_address' => $request->ip(),
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Discount removed.',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff', 'payments.recordedBy:id,name', 'staffStages']),
        ]);
    }

    /**
     * "No downpayment for this order" — the owner or a branch manager drops the deposit for one
     * order, e.g. a customer the shop already knows. Only before production starts. The order's
     * old rule is kept in the audit log, so "require it again" puts back exactly what it had.
     * The shop-wide and per-service "No downpayment" settings stay where they are (OrderRequirements).
     */
    public function depositWaiver(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $validated = $request->validate([
            'waive' => 'required|boolean',
            'reason' => 'required_if:waive,true|nullable|string|max:500',
        ]);

        if (in_array($jobOrder->status, JobOrder::STAGES_REQUIRING_DOWNPAYMENT, true)
            || in_array($jobOrder->status, ['completed', 'cancelled', 'rejected'], true)) {
            return response()->json(['success' => false, 'message' => 'The downpayment can only be changed before production starts.'], 422);
        }

        $waive = (bool) $validated['waive'];
        $lastWaiver = $store->auditLogs()
            ->where('action', 'deposit_waived')
            ->where('model_type', JobOrder::class)
            ->where('model_id', $jobOrder->id)
            ->latest('id')
            ->first();

        if ($waive && $jobOrder->payment_policy === 'none') {
            return response()->json(['success' => false, 'message' => 'This order already needs no downpayment.'], 422);
        }
        if (! $waive && $jobOrder->payment_policy !== 'none') {
            return response()->json(['success' => false, 'message' => 'This order already needs its downpayment.'], 422);
        }
        if (! $waive && ! $lastWaiver) {
            return response()->json(['success' => false, 'message' => 'This order needs no downpayment because of its service or shop setting. Change it there.'], 422);
        }

        DB::transaction(function () use ($store, $jobOrder, $waive, $validated, $lastWaiver, $request) {
            if ($waive) {
                $payload = [
                    'reason' => $validated['reason'],
                    'previous_policy' => $jobOrder->payment_policy ?? 'deposit',
                    'previous_percent' => $jobOrder->payment_policy_percent,
                ];
                $jobOrder->update(['payment_policy' => 'none']);
            } else {
                $payload = [
                    'restored_policy' => $lastWaiver->payload['previous_policy'] ?? 'deposit',
                    'restored_percent' => $lastWaiver->payload['previous_percent'] ?? null,
                ];
                $jobOrder->update([
                    'payment_policy' => $payload['restored_policy'],
                    'payment_policy_percent' => $payload['restored_percent'],
                ]);
            }

            $store->auditLogs()->create([
                'user_id' => $request->user()->id,
                'action' => $waive ? 'deposit_waived' : 'deposit_waiver_removed',
                'model_type' => JobOrder::class,
                'model_id' => $jobOrder->id,
                'payload' => $payload,
                'ip_address' => $request->ip(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => $waive ? 'No downpayment is needed for this order.' : 'The downpayment is required again.',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff', 'payments.recordedBy:id,name', 'staffStages']),
        ]);
    }

    // Corrects a payment's metadata (method/reference/receipt/notes) without
    // touching `amount` — the amount is what the job's balance was already
    // calculated from, so it stays permanently immutable here. If a walk-in
    // payment was logged with the wrong reference number or receipt image,
    // this fixes the record instead of requiring a reversal/re-entry that
    // would distort the payment trail.
    public function updatePayment(Request $request, Store $store, JobOrder $jobOrder, Payment $payment): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id || $payment->job_order_id !== $jobOrder->id) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        // Once a job is completed (garment delivered/picked up), its payment
        // records are final — matching the same "never silently rewrite the
        // payment trail" principle already applied elsewhere (amount is
        // always locked; this closes the remaining loophole where method/
        // reference/notes could still be edited after the fact).
        if ($jobOrder->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'This job is already completed — its payment records can no longer be edited.',
            ], 422);
        }

        $validated = $request->validate([
            'payment_method' => 'sometimes|string|in:'.implode(',', \App\Models\Payment::METHODS),
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'receipt_path' => 'nullable|string|max:2048',
        ]);

        $payment->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Payment updated successfully',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff', 'payments.recordedBy:id,name', 'staffStages']),
        ]);
    }

    /**
     * Reject a specific payment discovered to be fraudulent/bad after the
     * fact (e.g. a fake GCash receipt caught during a later review — staff
     * couldn't have known at the time). Reverses the balance/payment_status
     * regardless of the job's current production status: the "no balance,
     * no claim" rule in update() only blocks *advancing to* completed with a
     * balance owed, it doesn't forbid a completed job from later having a
     * balance again — that state becoming possible is exactly how a fraud
     * discovered after the garment was already handed over becomes visible.
     */
    public function rejectPayment(Request $request, Store $store, JobOrder $jobOrder, Payment $payment): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id || $payment->job_order_id !== $jobOrder->id) {
            return response()->json(['success' => false, 'message' => 'Payment not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        try {
            DB::transaction(function () use ($jobOrder, $payment, $validated, $request) {
                $locked = JobOrder::where('id', $jobOrder->id)->lockForUpdate()->firstOrFail();

                // Lock the Payment row too, and re-check idempotency on the
                // freshly-locked instance — checking the outer $payment
                // parameter (loaded before the lock, at request start) would
                // leave a TOCTOU window where two concurrent requests both
                // read rejected_at === null and both apply the reversal.
                $lockedPayment = Payment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

                if ($lockedPayment->rejected_at !== null) {
                    throw new \RuntimeException('This payment has already been rejected.');
                }

                // A payment still pending verification (an unconfirmed GCash/
                // PayMaya screenshot) was never applied to the balance in the
                // first place — pay() left it untouched on purpose. Reversing
                // it here too would double-credit the balance for money that
                // was never actually counted as received.
                if ($lockedPayment->verified_at !== null) {
                    $newBalance = round((float) $locked->balance + (float) $lockedPayment->amount, 2);
                    $hasOtherConfirmedPayments = $locked->payments()
                        ->whereNull('rejected_at')
                        ->whereNotNull('verified_at')
                        ->where('id', '!=', $lockedPayment->id)
                        ->exists();
                    $newPaymentStatus = $newBalance <= 0
                        ? 'paid'
                        : ($hasOtherConfirmedPayments ? 'partial' : 'unpaid');

                    $locked->update([
                        'balance' => $newBalance,
                        'payment_status' => $newPaymentStatus,
                    ]);
                }

                $lockedPayment->update([
                    'rejected_at' => now(),
                    'rejected_reason' => $validated['reason'],
                    'rejected_by' => $request->user()->id,
                ]);

                $store = $locked->store;
                $store->auditLogs()->create([
                    'user_id' => $request->user()->id,
                    'action' => 'payment_rejected',
                    'model_type' => Payment::class,
                    'model_id' => $lockedPayment->id,
                    'payload' => [
                        'job_order_id' => $locked->id,
                        'amount' => (float) $lockedPayment->amount,
                        'reason' => $validated['reason'],
                    ],
                    'ip_address' => $request->ip(),
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        // Notify store owner of the rejection
        if ($request->user()->hasRole('branch_manager')) {
            $store->owner?->notify(new PaymentRejectedNotification($jobOrder, $payment, $request->user()));
        }

        // The customer whose payment this was had no way to find out at
        // all otherwise — PaymentRejectedNotification above only ever
        // reaches the owner, and only for a branch_manager's rejection.
        $jobOrder->customer?->notify(new CustomerPaymentRejectedNotification($jobOrder, $payment, $validated['reason']));

        return response()->json([
            'success' => true,
            'message' => 'Payment rejected.',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff', 'payments.recordedBy:id,name', 'staffStages']),
        ]);
    }

    /**
     * Declines a job order before any production has started — a business
     * decision (feasibility, capacity, fabric availability), not a
     * production task, so plain staff can't do this any more than they can
     * touch payments. Only reachable from 'pending': once real work has
     * started, cancel the job instead (see UpdateJobOrderRequest's
     * cancellation_reason flow).
     */
    public function rejectOrder(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        if ($jobOrder->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only a pending order can be rejected — cancel it instead.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        $jobOrder->update([
            'status' => 'rejected',
            'rejection_reason' => $validated['reason'],
        ]);

        SafeNotify::send($jobOrder->customer, new JobStatusUpdatedNotification($jobOrder, 'rejected', $request->user()));

        return response()->json([
            'success' => true,
            'message' => 'Job order rejected.',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff'])->seenBy($request->user()),
        ]);
    }

    /**
     * Appends a staff-uploaded photo showing real production progress at
     * whatever stage the job is currently in — the "no proof of progress"
     * pain point real store owners named in interview research, distinct from
     * completion_photo_url (one photo, only at the very end) and
     * reference_images (the customer's own inspiration photos attached at
     * intake, not staff-uploaded evidence). Appends, never overwrites — a
     * job accumulates one photo per stage over its lifetime, same "add a
     * ledger entry" shape as payments rather than replacing a single field.
     */
    /**
     * Owner-composed free-form email to the job's customer — separate from
     * the automatic status-change notifications, which fire on their own.
     * The frontend renders its own preview before calling this; there's no
     * separate preview endpoint since nothing here needs server data the
     * owner doesn't already have in front of them.
     */
    public function notifyCustomer(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $customer = $jobOrder->customer;
        if (! $customer || ! $customer->email || str_starts_with($customer->email, 'walkin_')) {
            return response()->json([
                'success' => false,
                'message' => 'This customer has no real email on file to send to.',
            ], 422);
        }

        $customer->notify(new CustomMessageNotification($jobOrder, $validated['subject'], $validated['message']));

        return response()->json([
            'success' => true,
            'message' => 'Message sent to '.$customer->email.'.',
        ]);
    }

    public function addProgressPhoto(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $validated = $request->validate([
            'url' => 'required|string|max:2048',
        ]);

        $photos = $jobOrder->progress_photos ?? [];
        $photos[] = [
            'url' => $validated['url'],
            'stage' => $jobOrder->status,
            'uploaded_at' => now()->toIso8601String(),
        ];
        $jobOrder->forceFill(['progress_photos' => $photos])->save();

        return response()->json([
            'success' => true,
            'message' => 'Progress photo added.',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff'])->seenBy($request->user()),
        ]);
    }

    public function deleteProgressPhoto(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $validated = $request->validate([
            'url' => 'required|string',
        ]);

        $photos = collect($jobOrder->progress_photos ?? [])
            ->filter(fn ($p) => ($p['url'] ?? '') !== $validated['url'])
            ->values()
            ->all();

        $jobOrder->forceFill(['progress_photos' => $photos])->save();

        return response()->json([
            'success' => true,
            'message' => 'Progress photo removed.',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff'])->seenBy($request->user()),
        ]);
    }

    /**
     * Flips completed on/off for one member of a bulk/team order's roster
     * (custom_order_data.team_roster). A 10-jersey job otherwise has a
     * single status for the whole batch — this is the only place per-piece
     * progress ("7 of 10 done") is tracked, separate from the job's overall
     * production stage.
     */
    public function toggleRosterItem(Request $request, Store $store, JobOrder $jobOrder, int $index): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $data = $jobOrder->custom_order_data ?? [];
        // 'roster' is an older key some existing orders still use alongside
        // the current 'team_roster' — same fallback the frontend already
        // reads with (see teamRoster in the Job Detail page).
        $rosterKey = array_key_exists('team_roster', $data) ? 'team_roster' : 'roster';
        $roster = $data[$rosterKey] ?? null;

        if (! is_array($roster) || ! array_key_exists($index, $roster)) {
            return response()->json(['success' => false, 'message' => 'Roster item not found.'], 404);
        }

        $roster[$index]['completed'] = ! ($roster[$index]['completed'] ?? false);
        $data[$rosterKey] = $roster;
        $jobOrder->forceFill(['custom_order_data' => $data])->save();

        return response()->json([
            'success' => true,
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff'])->seenBy($request->user()),
        ]);
    }

    public function destroy(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        // Only a closed-by-cancel order can be archived: an ongoing one is cancelled first (with the customer's
        // agreement), and a completed one stays in Done.
        if ($jobOrder->status === 'completed') {
            return response()->json(['success' => false, 'message' => "A completed order stays in Done and can't be archived."], 422);
        }
        if (! in_array($jobOrder->status, ['cancelled', 'rejected'], true)) {
            return response()->json(['success' => false, 'message' => "An ongoing order can't be archived. Cancel it first, with the customer's agreement."], 422);
        }

        // Once money has changed hands, deleting the record would silently erase
        // the payment trail. Cancel it instead so the ledger stays intact.
        if ($jobOrder->payments()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This job order has recorded payments and cannot be archived. Cancel it instead to keep the payment history intact.',
            ], 400);
        }

        // Archive = soft delete. Logged before delete(): the record survives, but the Audit Log
        // needs who archived a job order and when, the same accountability gap
        // discount_applied/payment_rejected/etc. already closed elsewhere.
        $store->auditLogs()->create([
            'user_id' => $request->user()->id,
            'action' => 'job_order_archived',
            'model_type' => JobOrder::class,
            'model_id' => $jobOrder->id,
            'payload' => [
                'order_number' => $jobOrder->order_number,
                'status' => $jobOrder->status,
            ],
            'ip_address' => $request->ip(),
        ]);

        // The consultation it was made from goes back to "ready for a job order"; the fittings it made are cancelled.
        Appointment::where('job_order_id', $jobOrder->id)->where('kind', '!=', 'production')->update(['job_order_id' => null, 'outcome' => null]);
        $jobOrder->fittings()->whereIn('status', ['pending', 'confirmed', 'in_progress'])->get()->each(fn (Appointment $a) => $a->update(['status' => 'cancelled']));

        $jobOrder->delete();

        return response()->json([
            'success' => true,
            'message' => 'Order archived.',
        ]);
    }

    public function restore(Request $request, Store $store, int $jobOrderId): JsonResponse
    {
        $jobOrder = JobOrder::onlyTrashed()->where('id', $jobOrderId)->first();

        if (! $jobOrder || $jobOrder->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Archived order not found.'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $jobOrder->restore();

        // The consultation it was made from points at it again (if nothing else took that consultation meanwhile).
        if ($jobOrder->appointment_id) {
            Appointment::whereKey($jobOrder->appointment_id)->whereNull('job_order_id')->update(['job_order_id' => $jobOrder->id, 'outcome' => 'converted_to_job']);
        }

        $store->auditLogs()->create([
            'user_id' => $request->user()->id,
            'action' => 'job_order_restored',
            'model_type' => JobOrder::class,
            'model_id' => $jobOrder->id,
            'payload' => [
                'order_number' => $jobOrder->order_number,
                'status' => $jobOrder->status,
            ],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $jobOrder->load(['customer:id,name,suki_tag', 'service', 'assignedStaff:id,name']),
        ]);
    }

    /** Delete an ARCHIVED order for good. Refused while it has payment or refund records. */
    public function destroyPermanently(Request $request, Store $store, int $jobOrderId): JsonResponse
    {
        $jobOrder = JobOrder::onlyTrashed()->where('id', $jobOrderId)->first();

        if (! $jobOrder || $jobOrder->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Archived order not found.'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        if (JobOrderPurger::hasPaymentRecords($jobOrder)) {
            return response()->json(['success' => false, 'message' => 'This order has payment records, so it stays in the archive.'], 422);
        }

        DB::transaction(function () use ($request, $store, $jobOrder) {
            // Written first: once the row is gone this is the only trace of it.
            $store->auditLogs()->create([
                'user_id' => $request->user()->id,
                'action' => 'job_order_deleted_permanently',
                'model_type' => JobOrder::class,
                'model_id' => $jobOrder->id,
                'payload' => [
                    'order_number' => $jobOrder->order_number,
                    'customer' => $jobOrder->customer?->name,
                    'total' => $jobOrder->total_amount,
                ],
                'ip_address' => $request->ip(),
            ]);

            JobOrderPurger::purge($jobOrder);
        });

        return response()->json(['success' => true, 'message' => 'Order deleted permanently.']);
    }
}
