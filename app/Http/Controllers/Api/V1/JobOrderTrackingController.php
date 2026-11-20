<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Support\OrderProgress;
use App\Support\PublicStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Two ways to see order status: show() lets anyone check by tracking code
 * alone, no account needed (consumed by /track/[code]) — mirrors a
 * courier/delivery tracking page, the tracking_code itself is the
 * credential. myOrders() is the authenticated counterpart — a logged-in
 * customer's own cross-store order list (consumed by /account/orders),
 * scoped by their real customer_id instead of needing every code memorized.
 */
class JobOrderTrackingController extends Controller
{
    /**
     * Cross-store "My Orders" for whoever is logged in — the authenticated
     * counterpart to show() above, same safe field subset (no staff
     * assignments/internal notes), just scoped by the real customer_id
     * instead of a guessable-free tracking code. No role gate: filters by
     * the caller's own id, so it's inherently scoped to their own data.
     */
    public function myOrders(Request $request): JsonResponse
    {
        $jobOrders = JobOrder::where('customer_id', $request->user()->id)
            ->with([
                'store:id,name,slug,logo_path', 'service:id,name,service_types', 'catalogItem:id,name',
                // Only the still-unverified GCash/PayMaya rows — these never
                // touched balance/payment_status (JobOrderController::pay()),
                // so without this the customer sees no acknowledgement at
                // all that their payment was received while it waits on the
                // shop to confirm it.
                'payments' => fn ($q) => $q->whereNull('verified_at')->whereNull('rejected_at'),
            ])
            // ?active=1: only orders still in the shop (Ask SUTURA's "Where is my order?"), filtered here so an
            // older order still in production is never cut off by the page size.
            ->when($request->boolean('active'), fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled', 'rejected']))
            ->latest()
            ->paginate($request->input('per_page', 20));

        // Uncapped, independent of the display list's per_page (and of ?active=1) so the
        // hub's status tiles stay right as the customer's order count grows. The three buckets
        // mirror the frontend's orderPhases sets (IN_PRODUCTION / READY_FOR_PICKUP / 'completed')
        // so a tile's number always matches the tab it opens.
        $customerId = $request->user()->id;
        $statusCounts = [
            'inProduction' => JobOrder::where('customer_id', $customerId)
                ->whereIn('status', ['pending', 'confirmed', 'awaiting_materials', 'queued', 'design', 'pattern_making', 'mass_cutting_printing', 'cutting', 'sewing', 'in_repair', 'ready_for_fitting', 'final_adjustments', 'qc_ironing', 'qc_check', 'on_hold'])
                ->count(),
            'readyForPickup' => JobOrder::where('customer_id', $customerId)
                ->whereIn('status', ['ready_for_pickup', 'balance_handover'])
                ->count(),
            'completed' => JobOrder::where('customer_id', $customerId)
                ->where('status', 'completed')
                ->count(),
        ];

        $jobOrders->getCollection()->transform(fn (JobOrder $jobOrder) => [
            'id' => $jobOrder->id,
            'order_number' => $jobOrder->order_number,
            // WD-20: the public code exists for the customer only once production is authorized.
            'tracking_code' => PublicStage::of($jobOrder) !== null ? $jobOrder->tracking_code : null,
            'status' => $jobOrder->status,
            'progress' => OrderProgress::for($jobOrder),
            'garment_category' => $jobOrder->garment_category,
            'catalog_item_name' => $jobOrder->catalogItem?->name,
            'service_name' => $jobOrder->service?->name,
            'is_rush' => (bool) $jobOrder->is_rush,
            'due_date' => $jobOrder->due_date,
            'quantity' => $jobOrder->orderedQuantity(),
            'garments' => $jobOrder->garmentCount(),
            'sold_as' => $jobOrder->soldAsSet() ? 'set' : 'garment',
            'people_count' => $jobOrder->custom_order_data['people_count'] ?? null,
            // Linked orders (batches of one design, or different services asked for at the same visit): the list groups them.
            'parent_order_id' => $jobOrder->parent_order_id,
            'batch_no' => $jobOrder->batch_no,
            'group_kind' => $jobOrder->group_kind,
            'total_amount' => (float) $jobOrder->total_amount,
            'balance' => (float) $jobOrder->balance,
            'payment_status' => $jobOrder->payment_status,
            'pending_payment_amount' => (float) $jobOrder->payments->sum('amount'),
            'created_at' => $jobOrder->created_at,
            'store' => $jobOrder->store ? [
                'name' => $jobOrder->store->name,
                'slug' => $jobOrder->store->slug,
                'logo_path' => $jobOrder->store->logo_path,
            ] : null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $jobOrders->items(),
            'total' => $jobOrders->total(),
            'status_counts' => $statusCounts,
            'meta' => [
                'current_page' => $jobOrders->currentPage(),
                'last_page' => $jobOrders->lastPage(),
                'total' => $jobOrders->total(),
            ],
        ]);
    }

    /**
     * Single-order detail for the "My Orders" list — keyed by the order's
     * own id, not tracking_code. tracking_code is only ever generated by
     * JobOrderController::store; seeded/legacy rows can predate it and
     * would otherwise be permanently unopenable from an authenticated
     * customer's own order list even though they own the order and don't
     * need a guessable code to prove it. Ownership check replaces the
     * "the code is the credential" trust model show() above uses.
     */
    /** Where the customer brings the garment: the order's branch (the main branch for an online request). */
    private function dropOffFor(JobOrder $job): ?array
    {
        $b = $job->branch ?? $job->store?->branches()->where('is_main', true)->first();

        return $b ? [
            'name' => $b->name, 'address' => $b->address, 'landmark' => $b->landmark,
            'operating_hours' => $b->operating_hours, 'contact_number' => $b->contact_number,
            'latitude' => $b->latitude, 'longitude' => $b->longitude,
        ] : null;
    }

    public function myOrderDetail(Request $request, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->customer_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }

        $jobOrder->load([
            'store:id,name,slug,logo_path', 'service:id,name,service_types,image_url',
            'catalogItem:id,name,fabric_image_url', 'catalogItem.images', 'staffStages',
            'measurement',
            'payments' => fn ($q) => $q->whereNull('verified_at')->whereNull('rejected_at'),
        ]);

        // The order's own picture of what it is: what the customer attached wins; if they attached
        // nothing (walk-in, or older orders predating the catalog-image fallback), the design/service
        // image stands in so an order with a design or a service never renders without one.
        $references = $jobOrder->reference_images ?: [];
        if ($references === []) {
            $catalogItem = $jobOrder->catalogItem;
            $fallback = $catalogItem?->images->firstWhere('is_primary', true)?->image_url
                ?? $catalogItem?->images->first()?->image_url
                ?? $catalogItem?->fabric_image_url
                ?? $jobOrder->service?->image_url;
            if ($fallback !== null) {
                $references = [$fallback];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $jobOrder->id,
                'order_number' => $jobOrder->order_number,
                'tracking_code' => PublicStage::of($jobOrder) !== null ? $jobOrder->tracking_code : null,
                'status' => $jobOrder->status,
                'progress' => OrderProgress::for($jobOrder),
                // The other parts of a split order, with the overall figures (the customer pays them all).
                'batch' => \App\Support\OrderBatches::summary($jobOrder, true),
                'garment_category' => $jobOrder->garment_category,
                'catalog_item_name' => $jobOrder->catalogItem?->name,
                'service_name' => $jobOrder->service?->name,
                'is_rush' => (bool) $jobOrder->is_rush,
                'rush_fee' => (float) ($jobOrder->rush_fee ?? 0),
                'discount_amount' => (float) ($jobOrder->discount_amount ?? 0),
                'balance_due_by' => $jobOrder->balance_due_by,
                'due_date' => $jobOrder->due_date,
                'estimated_ready_at' => $jobOrder->estimated_ready_at,
                'pipeline' => $this->pipelineFor($jobOrder),
                'quantity' => $jobOrder->orderedQuantity(),
            'garments' => $jobOrder->garmentCount(),
            'sold_as' => $jobOrder->soldAsSet() ? 'set' : 'garment',
                'people_count' => $jobOrder->custom_order_data['people_count'] ?? null,
                'total_amount' => (float) $jobOrder->total_amount,
                'balance' => (float) $jobOrder->balance,
                'payment_status' => $jobOrder->payment_status,
                'pending_payment_amount' => (float) $jobOrder->payments->sum('amount'),
                'payment_policy' => $jobOrder->payment_policy,
                'payment_policy_percent' => $jobOrder->payment_policy_percent,
                'required_deposit' => round($jobOrder->requiredDepositFraction() * ((float) $jobOrder->total_amount - (float) $jobOrder->discount_amount), 2),
                'fitting_requirement' => $jobOrder->fitting_requirement,
                'measurement_requirement' => $jobOrder->measurement_requirement,
                'quotation_flow' => $this->quotationFlowFor($jobOrder),
                // "Have the shop measure me" + the visit the shop booked for it (null until booked).
                'measurement_source' => $jobOrder->measurement_source,
                'measurement_visit' => $jobOrder->measurementVisit(),
                'store_branch_id' => $jobOrder->store_branch_id,
                'created_at' => $jobOrder->created_at,
                'updated_at' => $jobOrder->updated_at,
                'repair_note' => $jobOrder->custom_order_data['repair_note'] ?? null,
                // Work on the customer's own garment: where to bring it, and the shop's drop-off check.
                'garment_check' => \App\Support\GarmentCheck::forCustomer($jobOrder),
                'drop_off' => $this->dropOffFor($jobOrder),
                // The customer's own attachments from the online booking (inspiration photos, the design link the customer
                // pasted, a reference link) — their data, customer-safe, so the order page shows them what they sent.
                // Empty arrays fall back to the order's design/service image (computed above), never to a blank order.
                'reference_images' => $references,
                'design_link' => $jobOrder->design_link,
                'reference_link' => $jobOrder->reference_link,
                'customer_material_status' => $jobOrder->customer_material_status,
                'progress_photos' => $jobOrder->progress_photos,
                'stage_timestamps' => $this->buildStageTimestamps($jobOrder),
                'appointments' => $this->buildCustomerSafeAppointments($jobOrder),
                'payments' => $this->buildCustomerSafePayments($jobOrder),
                'measurement_data' => $this->buildMeasurementData($jobOrder),
                'store' => $jobOrder->store ? [
                    'name' => $jobOrder->store->name,
                    'slug' => $jobOrder->store->slug,
                    'logo_path' => $jobOrder->store->logo_path,
                ] : null,
            ],
        ]);
    }

    /**
     * Customer-safe measurement details for this order: anatomical points
     * (chest, waist, hips, length, shoulders...), standard sizes, or team roster fit.
     */
    private function buildMeasurementData(JobOrder $jobOrder): ?array
    {
        $custom = (array) ($jobOrder->custom_order_data ?? []);

        // 1. Linked Measurement model
        $measurementModel = $jobOrder->measurement;
        $profileMetrics = is_array($measurementModel?->metrics) ? $measurementModel->metrics : [];
        $profileName = $measurementModel?->profile_name ?? null;

        // 2. Direct inputs in custom_order_data
        $directMetrics = is_array($custom['measurements'] ?? null)
            ? $custom['measurements']
            : (is_array($custom['fit'] ?? null) ? $custom['fit'] : []);

        $metrics = ! empty($profileMetrics) ? $profileMetrics : $directMetrics;

        $size = $custom['size'] ?? $custom['top_size'] ?? null;
        $unit = $custom['unit'] ?? 'in';
        $roster = is_array($custom['team_roster'] ?? null) ? $custom['team_roster'] : null;

        if (empty($metrics) && empty($size) && empty($roster) && ! $jobOrder->measurement_source) {
            return null;
        }

        return [
            'profile_name' => $profileName,
            'size' => $size,
            'unit' => $unit,
            'source' => $jobOrder->measurement_source,
            'verified' => $jobOrder->measurements_verified_at !== null,
            'verified_at' => $jobOrder->measurements_verified_at,
            'metrics' => ! empty($metrics) ? $metrics : null,
            'team_roster' => $roster ? array_map(function ($m) {
                return [
                    'name' => $m['name'] ?? '',
                    'gender' => $m['gender'] ?? $m['extra']['Gender'] ?? null,
                    'size' => $m['size'] ?? '',
                    'bottom_size' => $m['bottomSize'] ?? $m['bottom_size'] ?? null,
                    'cut' => $m['cut'] ?? $m['extra']['Cut'] ?? null,
                    'number' => $m['number'] ?? $m['extra']['Jersey Number'] ?? null,
                    'fit' => is_array($m['fit'] ?? null) ? $m['fit'] : null,
                ];
            }, $roster) : null,
        ];
    }

    /**
     * Every payment on this order and what the shop decided about it, so the customer sees "Pending", "Approved" or
     * "Rejected" (with the shop's reason) — the other side of the shop's Collect Payments verify / reject.
     * Customer-safe: no staff names, no internal notes.
     */
    private function buildCustomerSafePayments(JobOrder $jobOrder): array
    {
        return \App\Models\Payment::where('job_order_id', $jobOrder->id)
            ->with('method:id,name')
            ->latest()
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'type' => $p->type ?: \App\Models\Payment::inferType($jobOrder, (float) $p->amount),
                'method' => $p->method?->name ?? $p->payment_method,
                'reference' => $p->reference,
                'source' => $p->source,
                'paid_at' => $p->created_at,
                'status' => $p->status,
                'decided_at' => $p->rejected_at ?? $p->verified_at,
                'rejected_reason' => $p->rejected_at ? $p->rejected_reason : null,
            ])
            ->values()
            ->all();
    }

    /**
     * What the customer needs to see before the shop starts: the quotation, whether it is still an
     * estimate, the cancellation policy they are agreeing to, and whether it is waiting on them.
     * Customer-safe only -- no material costs, tailors or internal notes. Null for orders that do not
     * use the ordering flow.
     */
    private function quotationFlowFor(JobOrder $jobOrder): ?array
    {
        if (! \App\Support\OrderReadiness::gated($jobOrder)) {
            return null;
        }
        $q = $jobOrder->quotation;

        return [
            'quotation_status' => $jobOrder->quotation_status,
            'breakdown' => $q ? [
                'base_price' => $q['base_price'] ?? 0,
                // Line by line when people got different options (8 sets; 2 blouses); null for one price.
                'price_lines' => $jobOrder->custom_order_data['price_lines'] ?? null,
                'quantity_adjustment' => $q['quantity_adjustment'] ?? 0,
                'additional_charges' => $q['additional_charges'] ?? [],
                'customer_provided_deduction' => $q['customer_provided_deduction'] ?? [],
                'total' => $q['total'] ?? (float) $jobOrder->total_amount,
            ] : null,
            'needs_approval' => $jobOrder->status === 'pending' && $jobOrder->quotation_status === 'final' && ! $jobOrder->customer_approved_at,
            'decision' => $jobOrder->customer_decision,
            'valid_until' => $jobOrder->quotation_valid_until?->toDateString(),
            'expired' => $jobOrder->quotation_valid_until?->lt(today()) ?? false,
            'terms_text' => \App\Support\OrderReadiness::termsText($jobOrder),
            'terms_locked' => $jobOrder->payment_terms_locked_at !== null,
            'approved_at' => $jobOrder->customer_approved_at,
            'confirmed_at' => $jobOrder->confirmed_at,
            'required_deposit' => \App\Support\OrderReadiness::requiredDeposit($jobOrder),
            'policy' => $jobOrder->store?->cancellationPolicyText(),
            'waiting_for_materials' => $jobOrder->status === 'awaiting_materials',
        ];
    }

    /**
     * Appointments linked to this job order (job_order_id, set manually by
     * staff/owner or automatically on ready_for_fitting — CUSTOMER-WORKFLOW.md
     * §7.4), reduced to the same customer-safe field subset
     * AppointmentController::myAppointments() already uses elsewhere — no
     * staff assignment, no internal notes, no payment reference/receipt.
     * Reuses the existing JobOrder::appointments() relationship; no new table.
     */
    private function buildCustomerSafeAppointments(JobOrder $jobOrder): array
    {
        return $jobOrder->appointments()
            ->with(['service:id,name,service_types', 'branch:id,name,address,city,is_main'])
            ->orderByDesc('scheduled_at')
            ->get()
            ->map(fn ($appointment) => [
                'id' => $appointment->id,
                'appointment_type' => $appointment->appointment_type,
                'purpose_label' => $appointment->purpose_label,
                'status' => $appointment->status,
                'scheduled_at' => $appointment->scheduled_at,
                'checked_in_at' => $appointment->checked_in_at,
                'duration_minutes' => $appointment->duration_minutes,
                'service_name' => $appointment->service?->name,
                'branch' => $appointment->branch ? [
                    'name' => $appointment->branch->name,
                    'address' => $appointment->branch->address,
                    'city' => $appointment->branch->city,
                    'is_main' => (bool) $appointment->branch->is_main,
                ] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Best-effort real "when did this stage happen" per pipeline stage, for
     * the customer detail page's timeline — assembled from data that's
     * already tracked, not a dedicated stage-history table (none exists).
     * design/pattern_making/cutting/sewing/qc_ironing come from the
     * job_order_staff pivot's assigned_at (when staff was put on that
     * stage — the earliest, if more than one staff worked it). Stages with
     * no tracked timestamp anywhere (mass_cutting_printing, ready_for_fitting,
     * completed) are left null rather than guessed — the frontend just
     * omits the date/time line for those instead of showing a wrong one.
     */
    private function buildStageTimestamps(JobOrder $jobOrder): array
    {
        $stageStart = fn (string $stage) => $jobOrder->staffStages
            ->where('pivot.stage', $stage)
            ->pluck('pivot.assigned_at')
            ->filter()
            ->min();

        return [
            'pending' => $jobOrder->created_at,
            'confirmed' => $jobOrder->confirmed_at,
            'awaiting_materials' => null,
            'in_production' => $jobOrder->production_started_at,
            'design' => $stageStart('design'),
            'pattern_making' => $stageStart('pattern_making'),
            'mass_cutting_printing' => null,
            'cutting' => $stageStart('cutting'),
            'sewing' => $stageStart('sewing'),
            'ready_for_fitting' => null,
            'final_adjustments' => $jobOrder->first_adjustment_at,
            'qc_ironing' => $stageStart('qc_ironing'),
            'ready_for_pickup' => $jobOrder->ready_for_pickup_at,
            'balance_handover' => null,
            'completed' => null,
        ];
    }

    /**
     * Public tracker (DSD-02 WF-8, WD-11, WD-20). Answers with ONLY the customer-visible stage and the date it was
     * last updated: never the customer, amounts, balance, payment status, notes, photos or appointments (those are in
     * the signed-in account view). An unknown code, a malformed one and an order not yet authorized for production all
     * get the same response, which never repeats the code that was sent.
     */
    public function show(string $trackingCode): JsonResponse
    {
        $raw = strtoupper(trim($trackingCode));
        $normalized = str_replace(['-', ' '], '', $raw);

        // The random tracking code only. The order number (ORD-0001, 0002, …) is sequential and
        // repeats per shop, so accepting it would let anyone walk through other customers' orders.
        $jobOrder = $normalized === '' || strlen($normalized) > 40 ? null : JobOrder::where('tracking_code', $raw)
            ->orWhereRaw("REPLACE(REPLACE(tracking_code, '-', ''), ' ', '') = ?", [$normalized])
            ->first();

        $stage = $jobOrder ? PublicStage::of($jobOrder) : null;
        if ($stage === null) {
            return response()->json([
                'success' => false,
                'message' => 'We could not find an order for that code. Check the code and try again.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'stage' => $stage,
                'updated_on' => $jobOrder->updated_at?->timezone('Asia/Manila')->toDateString(),
            ],
        ]);
    }

    /**
     * Which production pipeline the customer's stepper should draw. Four distinct
     * pipelines exist so each order type shows only the stages that actually apply:
     *
     *  repair     — damaged garment: queued → in_repair → qc_check → ready
     *  alteration — fit adjustment on existing garment: queued → in_repair (shown as
     *               "In Alteration") → qc_check → ready (no designing, no mass cutting)
     *  bulk       — team/uniform set: design → mass_cutting_printing → cutting → sewing → ready
     *  custom     — bespoke: design → pattern_making → cutting → sewing → fitting → ready
     */
    private function pipelineFor(JobOrder $jobOrder): string
    {
        if ($jobOrder->isRepairOnly()) {
            return 'repair';
        }

        if ($jobOrder->isAlterationOnly()) {
            return 'alteration';
        }

        return $jobOrder->isBulkOrder() ? 'bulk' : 'custom';
    }
}
