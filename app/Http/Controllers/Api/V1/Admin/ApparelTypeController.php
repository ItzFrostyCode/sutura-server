<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\CustomApparelType;
use App\Models\Store;
use App\Notifications\StoreActivityNotification;
use App\Support\AdminAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Validate apparel categories": typed-in apparel names wait here. A design
 * using one stays off the public storefront until the name is approved; an
 * approved name is reused instantly by every shop, a rejected one is blocked.
 */
class ApparelTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->input('status', 'pending');

        $types = CustomApparelType::query()
            ->with(['requestedByStore:id,name,slug', 'reviewer:id,name'])
            ->withCount('catalogItems as designs_count')
            ->when(in_array($status, CustomApparelType::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->orderBy($status === 'pending' ? 'created_at' : 'reviewed_at', $status === 'pending' ? 'asc' : 'desc')
            ->paginate(20);

        $types->getCollection()->transform(fn (CustomApparelType $type) => [
            'id' => $type->id,
            'label' => $type->label,
            'status' => $type->status,
            'rejection_reason' => $type->rejection_reason,
            'designs_count' => $type->designs_count,
            'store' => $type->requestedByStore ? ['id' => $type->requestedByStore->id, 'name' => $type->requestedByStore->name, 'slug' => $type->requestedByStore->slug] : null,
            'reviewer' => $type->reviewer?->name,
            'reviewed_at' => $type->reviewed_at,
            'created_at' => $type->created_at,
            // A few of the designs using the name, so the admin judges it in context.
            'samples' => $type->catalogItems()->with(['images', 'store:id,name'])->latest()->take(3)->get()
                ->map(fn (CatalogItem $item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'store' => $item->store?->name,
                    'image_url' => ($item->images->firstWhere('is_primary', true) ?? $item->images->first())?->image_url,
                ]),
        ]);

        $counts = CustomApparelType::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json([
            'success' => true,
            'data' => $types->items(),
            'counts' => collect(CustomApparelType::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
            'meta' => [
                'current_page' => $types->currentPage(),
                'last_page' => $types->lastPage(),
                'total' => $types->total(),
            ],
        ]);
    }

    public function approve(Request $request, CustomApparelType $apparelType): JsonResponse
    {
        $apparelType->update(['status' => 'approved', 'rejection_reason' => null, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

        AdminAudit::record($request, 'apparel_type_approved', CustomApparelType::class, $apparelType->id, $apparelType->label, $apparelType->requested_by_store_id);
        $this->tellShops($apparelType, 'apparel_type_approved', 'Apparel name approved',
            "“{$apparelType->label}” was approved. Your designs using it are now visible to customers.");

        return response()->json(['success' => true, 'message' => "“{$apparelType->label}” approved.", 'data' => ['id' => $apparelType->id, 'status' => 'approved']]);
    }

    public function reject(Request $request, CustomApparelType $apparelType): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        $apparelType->update(['status' => 'rejected', 'rejection_reason' => $data['reason'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

        AdminAudit::record($request, 'apparel_type_rejected', CustomApparelType::class, $apparelType->id, $apparelType->label, $apparelType->requested_by_store_id, $data['reason']);
        $this->tellShops($apparelType, 'apparel_type_rejected', 'Apparel name not approved',
            "“{$apparelType->label}” was not approved: {$data['reason']} Designs using it stay hidden until you pick another apparel type.");

        return response()->json(['success' => true, 'message' => "“{$apparelType->label}” rejected.", 'data' => ['id' => $apparelType->id, 'status' => 'rejected']]);
    }

    /** Every shop with a design using the name hears the decision, not only the one that first typed it. */
    private function tellShops(CustomApparelType $type, string $action, string $title, string $message): void
    {
        $storeIds = $type->catalogItems()->distinct()->pluck('store_id')->push($type->requested_by_store_id)->filter()->unique();

        Store::with('owner')->whereIn('id', $storeIds)->get()->each(function (Store $store) use ($type, $action, $title, $message) {
            $store->owner?->notify(new StoreActivityNotification($action, $title, $message, '/dashboard/catalog', ['apparel_type_id' => $type->id]));
        });
    }
}
