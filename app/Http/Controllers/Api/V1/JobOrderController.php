<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\StoreJobOrderRequest;
use App\Http\Requests\Shop\UpdateJobOrderRequest;
use App\Models\Shop;
use App\Models\JobOrder;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobOrderController extends Controller
{
    /**
     * Branch managers/staff pinned to a branch may only act on job orders
     * that belong to that same branch (shop_owner is unrestricted). Jobs or
     * users with no branch assigned (single-branch shops) are never blocked.
     */
    private function branchAccessDenied(Request $request, JobOrder $jobOrder): ?JsonResponse
    {
        $user = $request->user();
        if ($user->hasRole('shop_owner')) {
            return null;
        }

        $userBranchId = $user->staffProfile->shop_branch_id ?? null;

        if ($userBranchId && $jobOrder->shop_branch_id && (int) $userBranchId !== (int) $jobOrder->shop_branch_id) {
            return response()->json(['success' => false, 'message' => 'This job order belongs to a different branch.'], 403);
        }

        return null;
    }

    /**
     * Notifies the shop owner's own in-app bell whenever staff or a branch
     * manager makes a change on their behalf — the owner performing the
     * change themselves already knows what they just did, so this only
     * fires for other actors, matching StaffAssignedNotification's own
     * "only notify the party who didn't cause this" precedent.
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
     * "JO-{year}-{sequential}" (e.g. JO-2026-0503) instead of an opaque
     * random string — matches the format already shown throughout the
     * dashboard/print ticket/receipts, and lets an owner recognize order
     * numbers as sequential the way real invoice books work. Scoped per
     * shop and per year; includes soft-deleted orders so a number is never
     * reused once issued.
     */
    private function generateOrderNumber(Shop $shop): string
    {
        $year = now()->year;
        $prefix = "JO-{$year}-";

        // Use a single SQL query instead of loading all job orders into memory.
        // SUBSTRING extracts the numeric part after the prefix, CAST to integer,
        // and MAX() finds the highest number — all in one database round-trip
        // regardless of how many orders exist.
        $lastNumber = $shop->jobOrders()
            ->withTrashed()
            ->where('order_number', 'like', $prefix . '%')
            ->selectRaw("MAX(CAST(SUBSTR(order_number, ?) AS INTEGER)) as max_num", [strlen($prefix) + 1])
            ->value('max_num') ?? 0;

        return $prefix . str_pad((string) ($lastNumber + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Globally unique (not scoped per shop, unlike order_number) — the
     * public tracking lookup has no other way to know which shop's table to
     * search. Lets a customer check their order status without an account,
     * the real "sa na po ba?" pain point named in interview research.
     */
    public static function generateShopPrefix($shop): string
    {
        if (is_numeric($shop)) {
            $shop = Shop::find($shop);
        }
        if (!$shop) {
            return 'SUTR';
        }
        if (!empty($shop->shop_code)) {
            return strtoupper($shop->shop_code);
        }
        $known = [
            'thread-needle'      => 'TNED',
            'bautista-tailors'   => 'BAUT',
            'villanueva-atelier' => 'VILL',
        ];
        if (isset($known[$shop->slug])) {
            return $known[$shop->slug];
        }

        $letters = preg_replace('/[^A-Za-z]/', '', $shop->name);
        if (strlen($letters) >= 4) {
            return strtoupper(substr($letters, 0, 4));
        }
        return strtoupper(str_pad($letters, 4, 'X'));
    }

    private function generateTrackingCode(?Shop $shop = null, ?string $orderNumber = null): string
    {
        $prefix = self::generateShopPrefix($shop);
        $charset = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

        do {
            $suffix = '';
            for ($i = 0; $i < 4; $i++) {
                $suffix .= $charset[random_int(0, strlen($charset) - 1)];
            }
            // Ensure combination of letters and numbers
            if (!preg_match('/[0-9]/', $suffix) || !preg_match('/[A-Z]/', $suffix)) {
                continue;
            }
            $code = "{$prefix}{$suffix}";
        } while (JobOrder::withTrashed()->where('tracking_code', $code)->exists());

        return $code;
    }

    /**
     * Customer-facing "Request a Repair" — the first-ever customer-initiated
     * job order creation path (every other job order is still created by
     * the shop owner/staff dashboard). Deliberately narrow: only fires for
     * a Service actually tagged alteration_repair, and pricing comes only
     * from that service's own real ServicePricing rows (never a guessed
     * flat amount) — a repair with no matching pricing line can't be priced
     * honestly here, so it's rejected rather than defaulting to 0.
     */
    public function customerRepairRequest(Request $request, Shop $shop): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => 'required|integer',
            'garment_description' => 'required|string|max:500',
            'pre_existing_damage_notes' => 'required|string|max:2000',
            'pricing_ids' => 'required|array|min:1',
            'pricing_ids.*' => 'integer',
            'reference_images' => 'nullable|array|max:10',
            'reference_images.*' => 'string|max:1000',
        ]);

        $service = \App\Models\Service::where('id', $validated['service_id'])
            ->where('shop_id', $shop->id)
            ->first();

        if (!$service || !$service->hasType(\App\Models\Service::TYPE_ALTERATION_REPAIR)) {
            return response()->json(['success' => false, 'message' => 'This shop has no matching repair/alteration service.'], 404);
        }

        $pricingRows = \App\Models\ServicePricing::where('service_id', $service->id)
            ->whereIn('id', $validated['pricing_ids'])
            ->get();

        if ($pricingRows->count() !== count($validated['pricing_ids'])) {
            return response()->json(['success' => false, 'message' => 'One or more selected repair items are no longer available.'], 422);
        }

        $totalAmount = (float) $pricingRows->sum('amount');

        $mainBranch = $shop->branches()->where('is_main', true)->first();

        $orderNumber = $this->generateOrderNumber($shop);
        $jobOrder = $shop->jobOrders()->create([
            'order_number' => $orderNumber,
            'tracking_code' => $this->generateTrackingCode($shop, $orderNumber),
            'intake_channel' => 'online',
            'shop_branch_id' => $mainBranch?->id,
            'customer_id' => $request->user()->id,
            'service_id' => $service->id,
            'garment_category' => 'alteration_repair',
            'total_amount' => $totalAmount,
            'balance' => $totalAmount,
            'payment_status' => 'unpaid',
            'status' => 'pending',
            'notes' => $validated['garment_description'],
            'reference_images' => $validated['reference_images'] ?? [],
            'custom_order_data' => [
                'pre_existing_damage_notes' => $validated['pre_existing_damage_notes'],
                'repair_items' => $pricingRows->map(fn ($p) => ['label' => $p->label, 'amount' => (float) $p->amount])->values()->all(),
            ],
        ]);

        $jobOrder->load(['customer:id,name', 'service']);

        $shopOwner = $shop->owner;
        if ($shopOwner) {
            $shopOwner->notify(new \App\Notifications\NewJobOrderNotification($jobOrder));
        }

        return response()->json(['success' => true, 'data' => $jobOrder], 201);
    }

    /**
     * Customer-facing "Bulk Order" off a Showroom catalog item — Size +
     * Quantity only for now (Color deferred). Requires the item to have a
     * linked, bulk_sublimation-typed Service (CatalogItem.service_id) —
     * there's no fallback/auto-detected service, so an unlinked item simply
     * has no Bulk Order entry point. Pricing is the item's own real price
     * × total quantity (never guessed), and each unit becomes its own
     * team_roster row (name left blank — a browsing customer has no real
     * per-recipient names, unlike a staff-entered team roster) so the shop's
     * existing Team Roster & Size Sheet UI can track/complete each piece
     * individually, exactly as it already does for staff-created bulk jobs.
     */
    public function customerBulkOrder(Request $request, Shop $shop): JsonResponse
    {
        $validated = $request->validate([
            'catalog_item_id' => 'required|integer',
            'organization_name' => 'nullable|string|max:255',
            'shop_branch_id' => 'nullable|integer',
            'roster' => 'required|array|min:1|max:500',
            'roster.*.name' => 'nullable|string|max:100',
            'roster.*.size' => 'required|string|max:50',
        ]);

        $item = \App\Models\CatalogItem::where('id', $validated['catalog_item_id'])
            ->where('shop_id', $shop->id)
            ->first();

        if (!$item || !$item->service_id) {
            return response()->json(['success' => false, 'message' => 'This item is not available for bulk ordering.'], 404);
        }

        $service = \App\Models\Service::find($item->service_id);
        if (!$service || !$service->hasType(\App\Models\Service::TYPE_BULK_SUBLIMATION)) {
            return response()->json(['success' => false, 'message' => 'This item is not available for bulk ordering.'], 404);
        }

        $totalQty = count($validated['roster']);

        if ($service->min_order_qty > 1 && $totalQty < $service->min_order_qty) {
            return response()->json([
                'success' => false,
                'message' => "This service requires a minimum of {$service->min_order_qty} pieces.",
            ], 422);
        }

        // Keep whatever extra columns the customer added on the roster
        // table (e.g. "Jersey Number") — $request->validate() above only
        // proves name/size are present, it doesn't strip the rest, so the
        // raw rows (not $validated) carry those extra keys through into
        // custom_order_data.team_roster for the shop owner to see.
        $roster = collect($request->input('roster'))->map(function ($row) {
            return array_merge($row, ['name' => $row['name'] ?? null]);
        })->all();

        $sizeCounts = collect($validated['roster'])->countBy('size');
        $totalAmount = (float) $item->price * $totalQty;

        // Customer-picked branch (via the catalog page's Find sheet) wins
        // when it actually belongs to this shop; otherwise fall back to the
        // shop's main branch same as before the picker existed.
        $pickedBranch = !empty($validated['shop_branch_id'])
            ? $shop->branches()->where('id', $validated['shop_branch_id'])->first()
            : null;
        $mainBranch = $pickedBranch ?? $shop->branches()->where('is_main', true)->first();

        $orderNumber = $this->generateOrderNumber($shop);
        $jobOrder = $shop->jobOrders()->create([
            'order_number' => $orderNumber,
            'tracking_code' => $this->generateTrackingCode($shop, $orderNumber),
            'intake_channel' => 'online',
            'shop_branch_id' => $mainBranch?->id,
            'customer_id' => $request->user()->id,
            'service_id' => $service->id,
            'catalog_item_id' => $item->id,
            'garment_category' => $item->garment_type,
            'material_source' => 'shop_supplied',
            'total_amount' => $totalAmount,
            'balance' => $totalAmount,
            'payment_status' => 'unpaid',
            'status' => 'pending',
            'notes' => "Bulk order: {$item->name} — " . $sizeCounts->map(fn ($qty, $size) => "{$qty}x {$size}")->implode(', '),
            'reference_images' => $item->images()->where('is_primary', true)->value('image_url') ? [$item->images()->where('is_primary', true)->value('image_url')] : [],
            'custom_order_data' => [
                'team_name' => $validated['organization_name'] ?? $item->name,
                'team_roster' => $roster,
            ],
        ]);

        $jobOrder->load(['customer:id,name', 'service', 'catalogItem:id,name']);

        $shopOwner = $shop->owner;
        if ($shopOwner) {
            $shopOwner->notify(new \App\Notifications\NewJobOrderNotification($jobOrder));
        }

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
    public function customerMadeToOrder(Request $request, Shop $shop): JsonResponse
    {
        $validated = $request->validate([
            'catalog_item_id' => 'required|integer',
            'size' => 'nullable|string|max:50',
        ]);

        $item = \App\Models\CatalogItem::where('id', $validated['catalog_item_id'])
            ->where('shop_id', $shop->id)
            ->first();

        if (!$item || !$item->service_id) {
            return response()->json(['success' => false, 'message' => 'This item is not available to order directly yet.'], 404);
        }

        $service = \App\Models\Service::find($item->service_id);
        if (!$service) {
            return response()->json(['success' => false, 'message' => 'This item is not available to order directly yet.'], 404);
        }
        if ($service->hasType(\App\Models\Service::TYPE_BULK_SUBLIMATION)) {
            return response()->json(['success' => false, 'message' => 'This item requires a Bulk Order — please use the bulk order flow instead.'], 422);
        }

        if (!empty($validated['size']) && !empty($item->sizes) && !in_array($validated['size'], $item->sizes, true)) {
            return response()->json(['success' => false, 'message' => 'That size is not offered for this item.'], 422);
        }

        $totalAmount = (float) $item->price;
        $mainBranch = $shop->branches()->where('is_main', true)->first();
        $primaryImage = $item->images()->where('is_primary', true)->value('image_url');

        $orderNumber = $this->generateOrderNumber($shop);
        $jobOrder = $shop->jobOrders()->create([
            'order_number' => $orderNumber,
            'tracking_code' => $this->generateTrackingCode($shop, $orderNumber),
            'intake_channel' => 'online',
            'shop_branch_id' => $mainBranch?->id,
            'customer_id' => $request->user()->id,
            'service_id' => $service->id,
            'catalog_item_id' => $item->id,
            'garment_category' => $item->garment_type,
            'material_source' => 'shop_supplied',
            'total_amount' => $totalAmount,
            'balance' => $totalAmount,
            'payment_status' => 'unpaid',
            'status' => 'pending',
            'notes' => 'Made to Order: ' . $item->name . (!empty($validated['size']) ? ' (Reference size: ' . $validated['size'] . ')' : ''),
            'reference_images' => $primaryImage ? [$primaryImage] : [],
            'custom_order_data' => !empty($validated['size']) ? ['reference_size' => $validated['size']] : null,
        ]);

        $jobOrder->load(['customer:id,name', 'service', 'catalogItem:id,name']);

        $shopOwner = $shop->owner;
        if ($shopOwner) {
            $shopOwner->notify(new \App\Notifications\NewJobOrderNotification($jobOrder));
        }

        return response()->json(['success' => true, 'data' => $jobOrder], 201);
    }

    public function index(Shop $shop, Request $request): JsonResponse
    {
        $query = $shop->jobOrders()->with([
            'customer:id,name,suki_tag',
            'service',
            'assignedStaff:id,name',
            'branch:id,name',
            'catalogItem:id,name,garment_type,price,fabric_image_url',
            'catalogItem.images',
        ]);

        $branchId = null;
        if (!$request->user()->hasRole('shop_owner') && $request->user()->staffProfile?->shop_branch_id) {
            // Staff/branch managers pinned to a branch only ever see that branch's
            // jobs — matches the per-action branch check enforced elsewhere below,
            // so nothing shows up in the list that they'd then be blocked from opening.
            $branchId = $request->user()->staffProfile->shop_branch_id;
        } elseif ($request->filled('branch_id')) {
            $branchId = $request->branch_id;
        }

        if ($branchId) {
            $query->where('shop_branch_id', $branchId);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Used by the appointment Complete flow to look up a specific
        // customer's job orders (e.g. to link a Fitting/Pickup appointment)
        // without being limited to whatever page of the full shop-wide list
        // happens to be loaded elsewhere.
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('assigned_staff_id')) {
            $query->where('assigned_staff_id', $request->assigned_staff_id);
        }

        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        // The Jobs list page's own search box used to run entirely
        // client-side against the same per_page=200-capped fetch — a shop
        // with more than 200 total historical jobs could search for a real
        // order number or customer name that exists beyond that window and
        // get "no results" even though it's genuinely there. Same
        // LOWER()+LIKE pattern as CatalogController::index's search — plain
        // LIKE isn't case-insensitive on Postgres the way it is on MySQL by
        // default, and this project has a Postgres migration planned.
        if ($request->filled('search')) {
            $search = strtolower((string) $request->string('search'));
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(order_number) LIKE ?', ['%' . $search . '%'])
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->whereRaw('LOWER(name) LIKE ?', ['%' . $search . '%']);
                    });
            });
        }

        // Used by the Payments page's "Job Balances" tab, which needs every
        // job still owing money regardless of production status. It used to
        // fetch the shop's entire job history (per_page=500, paid jobs
        // included) just to filter down client-side — the same
        // undercounting risk as the other capped-array bugs fixed this
        // session, and wasteful besides. Filtering server-side means the
        // response only ever contains genuinely-relevant rows, which stay
        // naturally small for any shop actually chasing its payments.
        if ($request->boolean('unpaid_only')) {
            $query->where('payment_status', '!=', 'paid')->where('status', '!=', 'cancelled');
        }

        // The Jobs list page's own tab badges ("All Orders 12", "Walk-in 8",
        // "Online 4") used to be re-derived client-side from this same list
        // fetched at per_page=200 — a shop with more than 200 total
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

        return response()->json([
            'success' => true,
            'data' => $query->latest()->paginate($request->input('per_page', 15)),
            'walk_in_count' => $walkInCount,
            'online_count' => $onlineCount,
            'pending_count' => $pendingCount,
        ]);
    }

    public function store(StoreJobOrderRequest $request, Shop $shop): JsonResponse
    {
        $validated = $request->validated();

        // Server-side enforcement of service-type rules — the frontend already
        // checks these, but a client can't be trusted for money/liability-sensitive
        // rules like minimum order quantity or damage-waiver logging.
        $service = \App\Models\Service::find($validated['service_id']);
        if ($service) {
            // A bulk order's quantity comes from whichever shape it took —
            // a personalized team_roster (one row per piece), OR a plain
            // size_breakdown/total_quantity tally with no names at all
            // (e.g. "30 pcs department shirts"). Only checking team_roster
            // here would wrongly reject a perfectly valid size-breakdown
            // order against this service's minimum.
            $roster = $validated['custom_order_data']['team_roster'] ?? null;
            $bulkQty = is_array($roster) ? count($roster) : 0;
            if ($bulkQty === 0) {
                $bulkQty = (int) ($validated['custom_order_data']['total_quantity'] ?? 0);
            }
            if (
                $service->hasType(\App\Models\Service::TYPE_BULK_SUBLIMATION)
                && $service->min_order_qty > 1
                && $bulkQty < $service->min_order_qty
            ) {
                return response()->json([
                    'success' => false,
                    'message' => "This service requires a minimum of {$service->min_order_qty} pieces.",
                ], 422);
            }

            if (
                $service->hasType(\App\Models\Service::TYPE_ALTERATION_REPAIR)
                && trim($validated['custom_order_data']['pre_existing_damage_notes'] ?? '') === ''
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pre-existing damage/condition notes are required for alteration and repair jobs.',
                ], 422);
            }
        }

        $validated['order_number'] = $this->generateOrderNumber($shop);
        $validated['tracking_code'] = $this->generateTrackingCode($shop, $validated['order_number']);
        $validated['order_type'] = $validated['order_type'] ?? 'walk_in';

        // staff_stages isn't a job_orders column — pull it out before create(),
        // then derive assigned_staff_id from it (first assigned stage, in
        // production order) so the staff portal / analytics reports — which
        // still filter by assigned_staff_id — keep working without the owner
        // having to separately pick a redundant "overall" staff member.
        $staffStages = $validated['staff_stages'] ?? [];
        unset($validated['staff_stages']);
        if (empty($validated['assigned_staff_id']) && !empty($staffStages)) {
            $stageOrder = JobOrder::STAFF_STAGES;
            $byStage = collect($staffStages)->keyBy('stage');
            foreach ($stageOrder as $stage) {
                if ($byStage->has($stage)) {
                    $validated['assigned_staff_id'] = $byStage[$stage]['user_id'];
                    break;
                }
            }
        }

        // Auto-assign branch when not explicitly set. The assigned staff's own
        // branch is the stronger signal of where the work actually happens —
        // without this, a branch manager creating a job and staffing it with
        // someone from a different branch (e.g. borrowing a tailor) silently
        // saved the job under the creator's own branch, not the staff's,
        // producing a job whose branch and assigned staff disagreed with no
        // warning. Falls back to the creator's own branch (staff/branch
        // manager) when the assigned staff has no fixed branch (a floater).
        if (empty($validated['shop_branch_id'])) {
            $assignedStaffId = $validated['assigned_staff_id'] ?? null;
            if ($assignedStaffId) {
                $assignedBranchId = \App\Models\StaffProfile::where('user_id', $assignedStaffId)
                    ->where('shop_id', $shop->id)
                    ->value('shop_branch_id');
                if ($assignedBranchId) {
                    $validated['shop_branch_id'] = $assignedBranchId;
                }
            }
        }
        $staffProfile = $request->user()->staffProfile;
        if ($staffProfile && empty($validated['shop_branch_id'])) {
            $validated['shop_branch_id'] = $staffProfile->shop_branch_id;
        }

        // The owner (no staffProfile) has no branch to inherit from the block
        // above, so a job created without explicitly picking one used to be
        // saved with shop_branch_id = NULL — permanently invisible the moment
        // anyone filters the Production Pipeline to a specific branch, since
        // `WHERE shop_branch_id = ?` never matches NULL in SQL. Confirmed live:
        // an owner-created job vanished from the board under the default
        // branch view with no error or warning. Default to the shop's main
        // branch instead, same as any other job with no other signal.
        if (empty($validated['shop_branch_id'])) {
            $mainBranch = $shop->branches()->where('is_main', true)->first();
            if ($mainBranch) {
                $validated['shop_branch_id'] = $mainBranch->id;
            }
        }

        // Determine payment status based on total amount and balance
        $totalAmount = (float)$validated['total_amount'];
        $balance = (float)$validated['balance'];
        $initialPayment = $totalAmount - $balance;

        if ($balance <= 0) {
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
        // A repeat customer (3+ prior jobs at this shop — the same signal already
        // shown on the Job Detail page as customer_job_count) gets a reduced rate
        // as a real loyalty perk. Deliberately NOT keyed off suki_tag: that field
        // holds a customer-type classification (b2b_suki/reseller/walk_in_retail),
        // not a repeat-customer flag, so it isn't the right signal for this.
        if (!empty($validated['is_rush']) && empty($validated['rush_fee']) && $totalAmount > 0) {
            $priorJobCount = JobOrder::where('shop_id', $shop->id)
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
            $candidate = \App\Models\Appointment::find($request->appointment_id);
            if ($candidate && $candidate->shop_id === $shop->id) {
                $appointment = $candidate;
                if (empty($validated['reference_images']) && !empty($appointment->reference_images)) {
                    $validated['reference_images'] = $appointment->reference_images;
                }
                if (empty($validated['reference_link']) && !empty($appointment->reference_link)) {
                    $validated['reference_link'] = $appointment->reference_link;
                }
                if (empty($validated['garment_category']) && !empty($appointment->garment_category)) {
                    $validated['garment_category'] = $appointment->garment_category;
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
        if (!empty($validated['catalog_item_id'])) {
            $catalogItem = \App\Models\CatalogItem::with('images')->find($validated['catalog_item_id']);
            if ($catalogItem && $catalogItem->shop_id === $shop->id) {
                if (empty($validated['reference_images'])) {
                    $catalogImage = $catalogItem->images->firstWhere('is_primary', true)?->image_url
                        ?? $catalogItem->images->first()?->image_url
                        ?? $catalogItem->fabric_image_url;
                    if ($catalogImage) {
                        $validated['reference_images'] = [$catalogImage];
                    }
                }
                if (empty($validated['garment_category']) && !empty($catalogItem->category)) {
                    $validated['garment_category'] = $catalogItem->category;
                }
            }
        }

        $jobOrder = $shop->jobOrders()->create($validated);

        foreach ($staffStages as $assignment) {
            $jobOrder->staffStages()->attach($assignment['user_id'], [
                'stage' => $assignment['stage'],
                'assigned_at' => now(),
                'completed_at' => null,
            ]);

            // Owner → Staff link: every stage assigned at creation is "new".
            $assignedUser = \App\Models\User::find($assignment['user_id']);
            $assignedUser?->notify(new \App\Notifications\StaffAssignedNotification($jobOrder, $assignment['stage']));
        }

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
            $appointment->update([
                'job_order_id' => $jobOrder->id,
                'outcome' => $appointment->outcome ?? 'converted_to_job',
            ]);
            if ($appointment->status === 'pending') {
                $appointment->update(['status' => 'confirmed']);
            }
        }

        // Record initial payment history if downpayment occurred
        if ($initialPayment > 0) {
            $jobOrder->payments()->create([
                'amount' => $initialPayment,
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'recorded_by' => $request->user()->id,
                'notes' => 'Initial downpayment recorded during order creation.'
            ]);
        }


        $jobOrder->load(['customer:id,name', 'service', 'assignedStaff:id,name', 'staffStages', 'catalogItem:id,name,fabric_image_url', 'catalogItem.images']);

        // Notify shop owner of the new job order
        $shopOwner = $shop->owner;
        if ($shopOwner) {
            $shopOwner->notify(new \App\Notifications\NewJobOrderNotification($jobOrder));
        }

        return response()->json([
            'success' => true,
            'data' => $jobOrder
        ], 201);
    }

    public function show(Request $request, Shop $shop, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $jobOrder->load(['customer', 'service', 'assignedStaff', 'measurement', 'staffStages', 'payments.recordedBy:id,name', 'catalogItem:id,name,fabric_image_url', 'catalogItem.images', 'branch:id,name', 'materials.loggedBy:id,name']);

        // Repeat-customer context surfaced right on the job so the owner can
        // decide on a manual discount ("this is their 5th order") without
        // having to jump to the Customers page first.
        $jobOrder->customer_job_count = $jobOrder->customer_id
            ? JobOrder::where('shop_id', $shop->id)->where('customer_id', $jobOrder->customer_id)->count()
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
            $currentVersion = \App\Models\Measurement::where('shop_id', $shop->id)
                ->where('customer_id', $jobOrder->measurement->customer_id)
                ->where('profile_name', $jobOrder->measurement->profile_name)
                ->whereNull('superseded_at')
                ->first();
            $jobOrder->measurement->is_stale = $currentVersion && $currentVersion->id !== $jobOrder->measurement->id;
            $jobOrder->measurement->current_version_id = $currentVersion?->id;
        }

        return response()->json([
            'success' => true,
            'data' => $jobOrder
        ]);
    }

    public function update(UpdateJobOrderRequest $request, Shop $shop, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $oldStatus = $jobOrder->status;
        $validated = $request->validated();
        $newStatus = $validated['status'] ?? null;

        // Same auto-calculated rush fee default as store() (including the
        // repeat-customer discount) — only fills in a starting figure when
        // is_rush is being newly turned on here without the caller also
        // supplying their own rush_fee; never overrides an explicit value,
        // and never touches a job that was already rush.
        if (
            !empty($validated['is_rush']) && !$jobOrder->is_rush
            && !isset($validated['rush_fee']) && (float) $jobOrder->total_amount > 0
        ) {
            $priorJobCount = JobOrder::where('shop_id', $jobOrder->shop_id)
                ->where('customer_id', $jobOrder->customer_id)
                ->count();
            $rushFeePercent = $priorJobCount >= 3 ? 0.15 : 0.30;
            $validated['rush_fee'] = round((float) $jobOrder->total_amount * $rushFeePercent, 2);
        }

        // Server-side backstop for the same "No DP, No Cut" / "No Balance, No Claim"
        // rules the Kanban UI enforces — this endpoint is also reachable from the
        // Job Detail page's own status dropdown, so the rule has to live here too,
        // not just in one frontend component.
        if ($newStatus && $newStatus !== $oldStatus) {
            $totalAmount = (float) $jobOrder->total_amount;
            $paidSoFar   = $totalAmount - (float) $jobOrder->balance;

            // "No DP, No Layout, No Cut": matches the 50% downpayment policy
            // shown on the Job Detail page and Kanban board. 'pending' and
            // 'design' are deliberately excluded — no fabric or material is
            // committed yet at those stages, only once Pattern Making (or
            // its Bulk Order Override, Mass Cutting & Printing) starts.
            if (in_array($newStatus, JobOrder::STAGES_REQUIRING_DOWNPAYMENT, true) && $totalAmount > 0 && $paidSoFar < ($totalAmount * 0.5)) {
                return response()->json([
                    'success' => false,
                    'message' => 'A 50% downpayment must be collected before production can start on this job.',
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
        }

        $jobOrder->update($validated);

        // Cancelling a job leaves its linked fitting appointment(s) dangling
        // otherwise — still 'pending'/'confirmed' on the calendar for a job
        // that no longer exists, which staff could show up for, and which
        // (once confirmed) blocks that time slot for a real future booking.
        // Confirmed live: cancelling JO-1005 left appointment #35 sitting at
        // 'pending' with no connection back to anything now dead.
        if ($jobOrder->status === 'cancelled' && $oldStatus !== 'cancelled') {
            $jobOrder->appointments()
                ->whereIn('status', ['pending', 'confirmed', 'in_progress'])
                ->get()
                ->each(function (\App\Models\Appointment $appointment) {
                    $appointment->update(['status' => 'cancelled']);
                    $appointment->customer?->notify(new \App\Notifications\AppointmentStatusNotification($appointment, 'cancelled'));
                });
        }

        // Tracks how many times a job has entered Final Adjustments, and when
        // the first one happened — lets the owner judge the shop's own "1 free
        // adjustment within N days" policy manually (the exact policy varies
        // shop to shop, so this only surfaces the facts rather than enforcing
        // one hard-coded rule). Neither field is client-settable (not in
        // $fillable) — both are server-derived from the status transition itself.
        if ($jobOrder->status === 'final_adjustments' && $oldStatus !== 'final_adjustments') {
            $jobOrder->increment('adjustment_count');
            if (!$jobOrder->first_adjustment_at) {
                $jobOrder->forceFill(['first_adjustment_at' => now()])->save();
            }
        }

        // When the OWNER marks a job completed, stamp completion on its staff
        // assignments so "jobs completed" / productivity is derivable without a staff portal.
        if ($jobOrder->status === 'completed' && $oldStatus !== 'completed') {
            \Illuminate\Support\Facades\DB::table('job_order_staff')
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
            $jobOrder->customer->notify(new \App\Notifications\OrderReadyNotification($jobOrder));
            $this->notifyOwnerOfActivity($request, $shop, [
                'type'    => 'job_ready_for_pickup',
                'title'   => 'Job Order Ready for Pickup',
                'message' => "{$jobOrder->order_number} ({$jobOrder->customer?->name}) is ready for pickup.",
                'url'     => '/dashboard/jobs/' . $jobOrder->id,
                'extra'   => ['job_order_id' => $jobOrder->id, 'order_number' => $jobOrder->order_number],
            ]);
        }

        // Phase 3: "Ready for Fitting" auto-triggers a Fitting appointment for
        // the customer instead of the owner having to remember to book one
        // manually. Guarded against duplicates so looping back through
        // Final Adjustments and forward again doesn't spawn a second one —
        // the placeholder date/time is a rough default the owner/staff is
        // expected to actually reschedule with the customer.
        if ($jobOrder->status === 'ready_for_fitting' && $oldStatus !== 'ready_for_fitting') {
            // Locking the job order row for the duration of the check+create
            // serializes two near-simultaneous transitions into
            // 'ready_for_fitting' for the same job — without this, both
            // requests could pass the "no open fitting exists yet" check
            // before either INSERT commits, spawning two auto-fitting
            // appointments instead of one.
            \Illuminate\Support\Facades\DB::transaction(function () use ($shop, $jobOrder) {
                JobOrder::where('id', $jobOrder->id)->lockForUpdate()->firstOrFail();

                $hasOpenFitting = $jobOrder->appointments()
                    ->where('appointment_type', 'fitting')
                    ->whereIn('status', ['pending', 'confirmed', 'in_progress'])
                    ->exists();

                if (!$hasOpenFitting) {
                    // Fitting session limit — the shop's fitting_limit is how many
                    // fitting appointments a job gets before fitting_fee kicks in
                    // (see UpdateShopRequest/SettingsBusinessType; not a rental
                    // concept — every prior fitting counts toward it, completed or
                    // not, since a no-show/cancelled session still used the shop's
                    // time). A job cycling ready_for_fitting → final_adjustments →
                    // ready_for_fitting again is exactly the real scenario this
                    // exists for: a second fitting round after adjustments.
                    $priorFittingCount = $jobOrder->appointments()->where('appointment_type', 'fitting')->count();
                    $overFittingLimit = $shop->fitting_limit && $priorFittingCount >= $shop->fitting_limit;

                    // "Tomorrow at 10am" is just a rough placeholder the owner
                    // is expected to reschedule with the customer anyway — but
                    // landing it on a day the shop announced as closed (see
                    // Shop::closureTitleOn, also enforced for real bookings in
                    // AppointmentController) would still look like a real bug
                    // to whoever opens this appointment first. Walk forward to
                    // the next open day instead, capped so a long closure
                    // can't spin this into an unbounded loop.
                    $placeholderDate = now()->addDay()->setTime(10, 0);
                    for ($i = 0; $i < 30 && $shop->closureTitleOn($placeholderDate, $jobOrder->shop_branch_id); $i++) {
                        $placeholderDate = $placeholderDate->copy()->addDay();
                    }

                    $jobOrder->appointments()->create([
                        'shop_id' => $shop->id,
                        'shop_branch_id' => $jobOrder->shop_branch_id,
                        'customer_id' => $jobOrder->customer_id,
                        'appointment_type' => 'fitting',
                        'intake_channel' => 'walk_in',
                        'scheduled_at' => $placeholderDate,
                        'duration_minutes' => \App\Models\Appointment::TYPE_DEFAULT_DURATIONS['fitting'],
                        'status' => 'pending',
                        'notes' => "Auto-generated when Job Order {$jobOrder->order_number} became Ready for Fitting — please confirm the actual date/time with the customer."
                            . ($overFittingLimit ? " This is fitting #{$priorFittingCount}+1, past the shop's {$shop->fitting_limit}-session limit — a ₱{$shop->fitting_fee} fitting fee has been added to the balance." : ''),
                    ]);

                    if ($overFittingLimit && $shop->fitting_fee > 0) {
                        $jobOrder->increment('total_amount', $shop->fitting_fee);
                        $jobOrder->increment('balance', $shop->fitting_fee);
                        if ($jobOrder->payment_status === 'paid') {
                            $jobOrder->update(['payment_status' => 'partial']);
                        }
                    }
                }
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
            $jobOrder->customer->notify(new \App\Notifications\JobStatusUpdatedNotification($jobOrder, $jobOrder->status));
            $this->notifyOwnerOfActivity($request, $shop, [
                'type'    => 'job_' . $jobOrder->status,
                'title'   => 'Job Order Updated',
                'message' => "{$jobOrder->order_number} ({$jobOrder->customer?->name}) moved to " . str_replace('_', ' ', $jobOrder->status) . '.',
                'url'     => '/dashboard/jobs/' . $jobOrder->id,
                'extra'   => ['job_order_id' => $jobOrder->id, 'order_number' => $jobOrder->order_number],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $jobOrder
        ]);
    }

    public function pay(Request $request, Shop $shop, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id) {
            return response()->json(['message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'sometimes|string|in:cash,gcash,paymaya',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'receipt_path' => 'nullable|string|max:2048',
        ]);

        $paymentAmount = (float) $validated['amount'];

        // A GCash/bank reference number reused across two different orders
        // is exactly what a reused-screenshot scam looks like — the same
        // proof-of-payment submitted twice to get credited twice. Warns
        // rather than blocks: legitimate reference collisions do happen
        // (some banks' reference formats aren't globally unique), so this
        // surfaces the fact for the staff member logging it to judge, the
        // same "surface facts, don't hard-code a rule" approach already
        // used for adjustment_count.
        $duplicateReferenceWarning = null;
        if (!empty($validated['reference'])) {
            $duplicateOrder = JobOrder::where('shop_id', $shop->id)
                ->whereHas('payments', function ($q) use ($validated) {
                    $q->where('reference', $validated['reference'])->whereNull('rejected_at');
                })
                ->first();
            if ($duplicateOrder) {
                $duplicateReferenceWarning = "This reference number was already used on order {$duplicateOrder->order_number} — double-check this isn't a reused receipt before accepting.";
            }
        }

        try {
            // Lock the row for the duration of the transaction so two payments
            // submitted at nearly the same time (e.g. two staff on the same job)
            // can't both read the same starting balance and overpay/double-count.
            \Illuminate\Support\Facades\DB::transaction(function () use ($jobOrder, $paymentAmount, $validated, $request) {
                $locked = JobOrder::where('id', $jobOrder->id)->lockForUpdate()->firstOrFail();

                $currentBalance = (float) $locked->balance;
                if ($paymentAmount > $currentBalance) {
                    throw new \RuntimeException('Payment exceeds remaining balance');
                }

                $newBalance = round($currentBalance - $paymentAmount, 2);
                $paymentStatus = $newBalance <= 0 ? 'paid' : 'partial';

                $locked->update([
                    'balance' => $newBalance,
                    'payment_status' => $paymentStatus,
                ]);

                $locked->payments()->create([
                    'amount' => $paymentAmount,
                    'payment_method' => $validated['payment_method'] ?? 'cash',
                    'reference' => $validated['reference'] ?? null,
                    'recorded_by' => $request->user()->id,
                    'notes' => $validated['notes'] ?? null,
                    'receipt_path' => $validated['receipt_path'] ?? null,
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        // Notify shop owner of the payment
        $shopOwner = $shop->owner;
        if ($shopOwner) {
            $shopOwner->notify(new \App\Notifications\PaymentReceivedNotification($jobOrder, $paymentAmount));
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment logged successfully',
            'warning' => $duplicateReferenceWarning,
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff', 'payments.recordedBy:id,name', 'staffStages'])
        ]);
    }

    /**
     * A one-time, in-the-moment discount the owner grants on an existing job
     * (e.g. a repeat customer) — not a standing coupon/promo code. Reduces
     * the remaining balance directly and is logged to the audit trail, same
     * pattern as AppointmentController's reschedule/complete audit entries.
     */
    public function applyDiscount(Request $request, Shop $shop, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id) {
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
            \Illuminate\Support\Facades\DB::transaction(function () use ($jobOrder, $discountAmount, $validated, $request) {
                $locked = JobOrder::where('id', $jobOrder->id)->lockForUpdate()->firstOrFail();

                $currentBalance = (float) $locked->balance;
                if ($discountAmount > $currentBalance) {
                    throw new \RuntimeException('Discount cannot exceed the remaining balance (₱' . number_format($currentBalance, 2) . ').');
                }

                $newBalance = round($currentBalance - $discountAmount, 2);
                $newDiscountTotal = round((float) ($locked->discount_amount ?? 0) + $discountAmount, 2);

                $locked->update([
                    'balance' => $newBalance,
                    'discount_amount' => $newDiscountTotal,
                    'payment_status' => $newBalance <= 0 ? 'paid' : $locked->payment_status,
                ]);

                $shop = $locked->shop;
                $shop->auditLogs()->create([
                    'user_id'    => $request->user()->id,
                    'action'     => 'discount_applied',
                    'model_type' => JobOrder::class,
                    'model_id'   => $locked->id,
                    'payload'    => [
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
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff', 'payments.recordedBy:id,name', 'staffStages'])
        ]);
    }

    // Corrects a payment's metadata (method/reference/receipt/notes) without
    // touching `amount` — the amount is what the job's balance was already
    // calculated from, so it stays permanently immutable here. If a walk-in
    // payment was logged with the wrong reference number or receipt image,
    // this fixes the record instead of requiring a reversal/re-entry that
    // would distort the payment trail.
    public function updatePayment(Request $request, Shop $shop, JobOrder $jobOrder, Payment $payment): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id || $payment->job_order_id !== $jobOrder->id) {
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
            'payment_method' => 'sometimes|string|in:cash,gcash,paymaya',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'receipt_path' => 'nullable|string|max:2048',
        ]);

        $payment->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Payment updated successfully',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff', 'payments.recordedBy:id,name', 'staffStages'])
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
    public function rejectPayment(Request $request, Shop $shop, JobOrder $jobOrder, Payment $payment): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id || $payment->job_order_id !== $jobOrder->id) {
            return response()->json(['success' => false, 'message' => 'Payment not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($jobOrder, $payment, $validated, $request) {
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

                $newBalance = round((float) $locked->balance + (float) $lockedPayment->amount, 2);
                $hasOtherConfirmedPayments = $locked->payments()
                    ->whereNull('rejected_at')
                    ->where('id', '!=', $lockedPayment->id)
                    ->exists();
                $newPaymentStatus = $newBalance <= 0
                    ? 'paid'
                    : ($hasOtherConfirmedPayments ? 'partial' : 'unpaid');

                $locked->update([
                    'balance' => $newBalance,
                    'payment_status' => $newPaymentStatus,
                ]);

                $lockedPayment->update([
                    'rejected_at' => now(),
                    'rejected_reason' => $validated['reason'],
                    'rejected_by' => $request->user()->id,
                ]);

                $shop = $locked->shop;
                $shop->auditLogs()->create([
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

        // Notify shop owner of the rejection
        if ($request->user()->hasRole('branch_manager')) {
            $shop->owner?->notify(new \App\Notifications\PaymentRejectedNotification($jobOrder, $payment, $request->user()));
        }

        // The customer whose payment this was had no way to find out at
        // all otherwise — PaymentRejectedNotification above only ever
        // reaches the owner, and only for a branch_manager's rejection.
        $jobOrder->customer?->notify(new \App\Notifications\CustomerPaymentRejectedNotification($jobOrder, $payment, $validated['reason']));

        return response()->json([
            'success' => true,
            'message' => 'Payment rejected.',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff', 'payments.recordedBy:id,name', 'staffStages'])
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
    public function rejectOrder(Request $request, Shop $shop, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id) {
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

        $jobOrder->customer->notify(new \App\Notifications\JobStatusUpdatedNotification($jobOrder, 'rejected'));

        return response()->json([
            'success' => true,
            'message' => 'Job order rejected.',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff'])
        ]);
    }

    /**
     * Appends a staff-uploaded photo showing real production progress at
     * whatever stage the job is currently in — the "no proof of progress"
     * pain point real shop owners named in interview research, distinct from
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
    public function notifyCustomer(Request $request, Shop $shop, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Job order not found'], 404);
        }

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $customer = $jobOrder->customer;
        if (!$customer || !$customer->email || str_starts_with($customer->email, 'walkin_')) {
            return response()->json([
                'success' => false,
                'message' => 'This customer has no real email on file to send to.',
            ], 422);
        }

        $customer->notify(new \App\Notifications\CustomMessageNotification($jobOrder, $validated['subject'], $validated['message']));

        return response()->json([
            'success' => true,
            'message' => 'Message sent to ' . $customer->email . '.',
        ]);
    }

    public function addProgressPhoto(Request $request, Shop $shop, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id) {
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
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff'])
        ]);
    }

    public function deleteProgressPhoto(Request $request, Shop $shop, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id) {
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
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff'])
        ]);
    }

    /**
     * Per-order fabric/trim consumption attribution — what was used on THIS
     * job, not a shop-wide stock ledger (staff-module/workroom/09, thesis
     * Scope & Limitations Line 203 excludes inventory entirely). Typically
     * logged by the cutter during the cutting stage.
     */
    public function addMaterial(Request $request, Shop $shop, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $validated = $request->validate([
            'material_name' => 'required|string|max:255',
            'quantity_used' => 'required|numeric|min:0.01',
            'unit' => 'nullable|string|max:20',
            'unit_cost' => 'nullable|numeric|min:0',
        ]);

        $unitCost = $validated['unit_cost'] ?? null;
        $material = $jobOrder->materials()->create([
            'material_name' => $validated['material_name'],
            'quantity_used' => $validated['quantity_used'],
            'unit' => $validated['unit'] ?? 'yard',
            'unit_cost' => $unitCost,
            'subtotal_cost' => $unitCost !== null ? round($validated['quantity_used'] * $unitCost, 2) : null,
            'logged_by_staff_id' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Material logged.',
            'data' => $material->load('loggedBy:id,name'),
        ], 201);
    }

    public function deleteMaterial(Request $request, Shop $shop, JobOrder $jobOrder, \App\Models\OrderMaterial $material): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id || $material->job_order_id !== $jobOrder->id) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $material->delete();

        return response()->json(['success' => true, 'message' => 'Material entry removed.']);
    }

    /**
     * Flips completed on/off for one member of a bulk/team order's roster
     * (custom_order_data.team_roster). A 10-jersey job otherwise has a
     * single status for the whole batch — this is the only place per-piece
     * progress ("7 of 10 done") is tracked, separate from the job's overall
     * production stage.
     */
    public function toggleRosterItem(Request $request, Shop $shop, JobOrder $jobOrder, int $index): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id) {
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

        if (!is_array($roster) || !array_key_exists($index, $roster)) {
            return response()->json(['success' => false, 'message' => 'Roster item not found.'], 404);
        }

        $roster[$index]['completed'] = !($roster[$index]['completed'] ?? false);
        $data[$rosterKey] = $roster;
        $jobOrder->forceFill(['custom_order_data' => $data])->save();

        return response()->json([
            'success' => true,
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff']),
        ]);
    }

    public function assignStaff(Request $request, Shop $shop, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id) {
            return response()->json(['message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        // "present" (not "required") on purpose — an empty array is a valid,
        // deliberate request: every stage was left/set to Unassigned, which
        // should clear existing assignments rather than fail. "required"
        // treats an empty array as missing entirely, which was rejecting
        // exactly that case with a 422.
        $validated = $request->validate([
            'assignments' => 'present|array',
            'assignments.*.user_id' => [
                'required',
                \Illuminate\Validation\Rule::exists('staff_profiles', 'user_id')->where('shop_id', $shop->id),
                function ($attribute, $value, $fail) use ($shop, $jobOrder) {
                    if (!$jobOrder->shop_branch_id) {
                        return;
                    }
                    $staffBranchId = \App\Models\StaffProfile::where('user_id', $value)
                        ->where('shop_id', $shop->id)
                        ->value('shop_branch_id');
                    if ($staffBranchId && (int) $staffBranchId !== (int) $jobOrder->shop_branch_id) {
                        $fail('This staff member belongs to a different branch than this job order.');
                    }
                },
            ],
            'assignments.*.stage' => ['required', \Illuminate\Validation\Rule::in(JobOrder::STAFF_STAGES)],
        ]);

        // Capture existing completion timestamps before replacing the pivot rows —
        // a blind detach+reattach previously wiped completed_at even for stages
        // whose assigned staff wasn't changing, which silently flipped their
        // "Completed" badge back to "In Progress" every time assignments were
        // re-saved (e.g. just to add one more stage).
        $existing = $jobOrder->staffStages()->get()->keyBy(fn ($staff) => $staff->pivot->stage);

        $jobOrder->staffStages()->detach();

        foreach ($validated['assignments'] as $assignment) {
            $previous = $existing->get($assignment['stage']);
            $samePersonAsBefore = $previous && (int) $previous->id === (int) $assignment['user_id'];

            $jobOrder->staffStages()->attach($assignment['user_id'], [
                'stage' => $assignment['stage'],
                'assigned_at' => $samePersonAsBefore ? $previous->pivot->assigned_at : now(),
                'completed_at' => $samePersonAsBefore ? $previous->pivot->completed_at : null,
            ]);

            // Owner → Staff link: only for a stage that's newly filled or
            // handed to a different person — re-saving the same assignment
            // shouldn't spam a notification.
            if (!$samePersonAsBefore) {
                $assignedUser = \App\Models\User::find($assignment['user_id']);
                $assignedUser?->notify(new \App\Notifications\StaffAssignedNotification($jobOrder, $assignment['stage']));
            }
        }

        // Same derivation store() does at creation time (first assigned stage,
        // in production order) — without this, assigning staff here (the only
        // way to assign/reassign staff on an already-created job) left
        // assigned_staff_id stale. Staff Productivity analytics already works
        // around that via a staffStages fallback, but the Kanban card and Job
        // Detail page's "Assigned Staff" field read assigned_staff_id
        // directly, so they kept showing "Unassigned" even for a job with a
        // real staff member actively working a stage. Confirmed live: staff
        // assigned to Cutting via this exact endpoint, board still said
        // Unassigned.
        $stageOrder = JobOrder::STAFF_STAGES;
        $byStage = collect($validated['assignments'])->keyBy('stage');
        $derivedStaffId = null;
        foreach ($stageOrder as $stage) {
            if ($byStage->has($stage)) {
                $derivedStaffId = $byStage[$stage]['user_id'];
                break;
            }
        }
        $jobOrder->update(['assigned_staff_id' => $derivedStaffId]);

        return response()->json([
            'success' => true,
            'message' => 'Staff assigned to stages successfully',
            'data' => $jobOrder->fresh(['staffStages', 'assignedStaff:id,name'])
        ]);
    }

    public function destroy(Request $request, Shop $shop, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id) {
            return response()->json(['message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        // Once money has changed hands, deleting the record would silently erase
        // the payment trail. Cancel it instead so the ledger stays intact.
        if ($jobOrder->payments()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This job order has recorded payments and cannot be deleted. Cancel it instead to keep the payment history intact.'
            ], 400);
        }

        // Logged before delete() — a soft delete, so the record itself
        // survives, but the Audit Log page had no entry at all for who
        // deleted a job order or when, the same accountability gap
        // discount_applied/payment_rejected/etc. already closed elsewhere.
        $shop->auditLogs()->create([
            'user_id'    => $request->user()->id,
            'action'     => 'job_order_deleted',
            'model_type' => JobOrder::class,
            'model_id'   => $jobOrder->id,
            'payload'    => [
                'order_number' => $jobOrder->order_number,
                'status'       => $jobOrder->status,
            ],
            'ip_address' => $request->ip(),
        ]);

        $jobOrder->delete();

        return response()->json([
            'success' => true,
            'message' => 'Job order deleted successfully'
        ]);
    }

    public function restore(Request $request, Shop $shop, int $jobOrderId): JsonResponse
    {
        $jobOrder = JobOrder::onlyTrashed()->where('id', $jobOrderId)->first();

        if (!$jobOrder || $jobOrder->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Deleted job order not found.'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $jobOrder->restore();

        $shop->auditLogs()->create([
            'user_id'    => $request->user()->id,
            'action'     => 'job_order_restored',
            'model_type' => JobOrder::class,
            'model_id'   => $jobOrder->id,
            'payload'    => [
                'order_number' => $jobOrder->order_number,
                'status'       => $jobOrder->status,
            ],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $jobOrder->load(['customer:id,name,suki_tag', 'service', 'assignedStaff:id,name']),
        ]);
    }
}
