<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreMeasurementRequest;
use App\Models\Appointment;
use App\Models\CatalogItem;
use App\Models\Measurement;
use App\Models\Store;
use App\Support\BranchPin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeasurementController extends Controller
{
    private const RELATIONS = ['customer:id,name', 'service:id,name', 'catalogItem:id,name'];

    /**
     * What a measurement is for: naming a design also names its service, and a measurement taken at an
     * appointment is for whatever that appointment booked unless the shop says otherwise.
     */
    private function withPurpose(array $data, ?Appointment $appointment): array
    {
        $data['service_id'] = $data['service_id'] ?? null;
        $data['catalog_item_id'] = $data['catalog_item_id'] ?? null;

        if (! $data['service_id'] && ! $data['catalog_item_id'] && $appointment) {
            $data['service_id'] = $appointment->service_id;
            $data['catalog_item_id'] = $appointment->catalog_item_id;
        }
        if ($data['catalog_item_id'] && ! $data['service_id']) {
            $data['service_id'] = CatalogItem::whereKey($data['catalog_item_id'])->value('service_id');
        }

        return $data;
    }

    /**
     * Cross-store "My Measurements" for whoever is logged in — the customer
     * counterpart to index() above, which is store-scoped and staff/owner
     * only. Same "Measurement Obfuscation" privacy rule the doc corpus
     * describes (customer-module/customer/01_account_and_auth/26 §1) is
     * satisfied by construction here: this only ever returns rows where
     * customer_id is the caller's own id, so no other customer's body
     * measurements are ever reachable through this endpoint. Read-only —
     * a customer can view what a store's staff recorded, not edit it.
     */
    public function myMeasurements(Request $request): JsonResponse
    {
        $customerId = $request->user()->id;
        // Each version carries the customer's own job orders it was used for, so My Measurements can show them order by order.
        $measurements = Measurement::where('customer_id', $customerId)
            ->with([
                'store:id,name,slug,logo_path',
                'service:id,name',
                'catalogItem:id,name',
                'jobOrders' => fn ($q) => $q->where('customer_id', $customerId)
                    ->select('id', 'measurement_id', 'order_number', 'status', 'garment_category', 'catalog_item_id', 'service_id', 'due_date', 'created_at')
                    ->with(['catalogItem:id,name', 'service:id,name'])
                    ->latest(),
            ])
            ->orderBy('profile_name')
            ->orderBy('version')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $measurements,
        ]);
    }

    public function index(Store $store, Request $request): JsonResponse
    {
        // Returns every version of every profile — the frontend groups these
        // by profile_name and lets the store owner switch between versions
        // client-side (see MeasurementList's version selector), so history
        // has to be in this same response, not a separate endpoint.
        $query = $store->measurements()->with(['customer:id,name,email', 'service:id,name', 'catalogItem:id,name']);

        if ($request->has('appointment_id')) {
            $query->where('appointment_id', $request->appointment_id);
        } elseif ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    public function store(StoreMeasurementRequest $request, Store $store): JsonResponse
    {
        $data = $request->validated();
        $data['metrics'] = $data['metrics'] ?? []; // photo-only profiles carry no typed values
        // The visit must be this shop's own (and, for a branch-pinned account, this branch's): another shop's or branch's
        // visit is never read for its service / design.
        $pin = BranchPin::of($request->user());
        $appointment = isset($data['appointment_id']) ? $store->appointments()->find($data['appointment_id']) : null;
        if (isset($data['appointment_id']) && (! $appointment || ($pin && $appointment->store_branch_id && (int) $appointment->store_branch_id !== $pin))) {
            return response()->json(['success' => false, 'message' => 'That visit does not belong to your branch.'], 403);
        }
        $data = $this->withPurpose($data, $appointment);
        $measurement = $store->measurements()->create($data);

        return response()->json([
            'success' => true,
            'data' => $measurement->load(self::RELATIONS),
        ], 201);
    }

    public function show(Store $store, Measurement $measurement): JsonResponse
    {
        if ($measurement->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $measurement->load(self::RELATIONS),
        ]);
    }

    public function update(Request $request, Store $store, Measurement $measurement): JsonResponse
    {
        if ($measurement->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // A superseded (past) version is a read-only historical record — you
        // view it, you don't edit it. Only the current version can be saved,
        // which is what actually creates the next version.
        if ($measurement->superseded_at !== null) {
            return response()->json(['success' => false, 'message' => 'This is a past version and cannot be edited. View the current version to make changes.'], 422);
        }

        $validated = $request->validate([
            'source' => 'nullable|in:store_owner,customer',
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')->where('store_id', $store->id)],
            'catalog_item_id' => ['nullable', 'integer', Rule::exists('catalog_items', 'id')->where('store_id', $store->id)],
            'metrics' => 'sometimes|array',
            'measurements' => 'sometimes|array',
            'notes' => 'nullable|string',
            'photo_urls' => 'nullable|array|max:6',
            'photo_urls.*' => 'string|max:2048',
            'status' => 'nullable|in:finalized,pending_fitting',
        ]);

        if (isset($validated['measurements'])) {
            $validated['metrics'] = $validated['measurements'];
            unset($validated['measurements']);
        }

        // Saving an edit never overwrites the current row in place — it closes
        // out this version (superseded_at) and inserts the next one, so a
        // store owner can always look back at what a customer's measurements
        // were at an earlier fitting instead of losing that the moment it's
        // updated.
        $measurement->update(['superseded_at' => now()]);

        $purpose = $this->withPurpose([
            'service_id' => array_key_exists('service_id', $validated) ? $validated['service_id'] : $measurement->service_id,
            'catalog_item_id' => array_key_exists('catalog_item_id', $validated) ? $validated['catalog_item_id'] : $measurement->catalog_item_id,
        ], null);

        $nextVersion = $store->measurements()->create([
            'customer_id' => $measurement->customer_id,
            'appointment_id' => $measurement->appointment_id,
            'service_id' => $purpose['service_id'],
            'catalog_item_id' => $purpose['catalog_item_id'],
            'source' => $validated['source'] ?? $measurement->source,
            'profile_name' => $measurement->profile_name,
            'version' => $measurement->version + 1,
            'metrics' => $validated['metrics'] ?? $measurement->metrics,
            'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $measurement->notes,
            'photo_urls' => array_key_exists('photo_urls', $validated) ? $validated['photo_urls'] : $measurement->photo_urls,
            'status' => $validated['status'] ?? $measurement->status,
        ]);

        return response()->json([
            'success' => true,
            'data' => $nextVersion->load(self::RELATIONS),
        ]);
    }
}
