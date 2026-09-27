<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\Store;
use App\Notifications\ListingModerationNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Post-moderation: listings go live immediately, and the System Admin acts
 * afterward on customer reports. Three escalating steps — warn (listing stays
 * up, owner asked to fix e.g. an image), hide one catalog design, or hide the
 * whole shop. Always manual; there are no automated content checks.
 *
 * Hiding sets the flag the public queries already filter on (is_active /
 * is_hidden) plus admin_hidden_at, which is the lock the owner and
 * subscription renewal can't lift (see StoreController::update,
 * CatalogController::update, SubscriptionController).
 */
class ModerationController extends Controller
{
    public function warnCatalogItem(Request $request, CatalogItem $catalogItem): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:500']);

        $this->record($request, $catalogItem->store, 'listing_warned', CatalogItem::class, $catalogItem->id, $catalogItem->name, $validated['reason']);
        $catalogItem->store?->owner?->notify(new ListingModerationNotification('warned', 'catalog_item', $catalogItem->name, $validated['reason']));

        return response()->json(['success' => true, 'message' => 'The shop owner has been asked to update this design.']);
    }

    public function hideCatalogItem(Request $request, CatalogItem $catalogItem): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:500']);

        $catalogItem->forceFill([
            'is_active' => false,
            'admin_hidden_at' => now(),
            'admin_hidden_reason' => $validated['reason'],
        ])->save();

        $this->record($request, $catalogItem->store, 'listing_hidden', CatalogItem::class, $catalogItem->id, $catalogItem->name, $validated['reason']);
        $catalogItem->store?->owner?->notify(new ListingModerationNotification('hidden', 'catalog_item', $catalogItem->name, $validated['reason']));

        return response()->json(['success' => true, 'message' => 'Catalog design hidden.', 'data' => $catalogItem]);
    }

    public function unhideCatalogItem(Request $request, CatalogItem $catalogItem): JsonResponse
    {
        $catalogItem->forceFill([
            'is_active' => true,
            'admin_hidden_at' => null,
            'admin_hidden_reason' => null,
        ])->save();

        $this->record($request, $catalogItem->store, 'listing_restored', CatalogItem::class, $catalogItem->id, $catalogItem->name);
        $catalogItem->store?->owner?->notify(new ListingModerationNotification('restored', 'catalog_item', $catalogItem->name));

        return response()->json(['success' => true, 'message' => 'Catalog design is visible again.', 'data' => $catalogItem]);
    }

    public function hideStore(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:500']);

        $store->forceFill([
            'is_hidden' => true,
            'admin_hidden_at' => now(),
            'admin_hidden_reason' => $validated['reason'],
        ])->save();

        $this->record($request, $store, 'store_hidden', Store::class, $store->id, $store->name, $validated['reason']);
        $store->owner?->notify(new ListingModerationNotification('hidden', 'store', $store->name, $validated['reason']));

        return response()->json(['success' => true, 'message' => 'Store hidden.', 'data' => $store]);
    }

    public function unhideStore(Request $request, Store $store): JsonResponse
    {
        // Only lifts the admin lock. A store that is also unsubscribed stays
        // hidden until renewal, same as it would without any takedown.
        $subscribed = in_array($store->subscription?->status, ['active', 'trial'], true);

        $store->forceFill([
            'is_hidden' => ! $subscribed,
            'admin_hidden_at' => null,
            'admin_hidden_reason' => null,
        ])->save();

        $this->record($request, $store, 'store_restored', Store::class, $store->id, $store->name);
        $store->owner?->notify(new ListingModerationNotification('restored', 'store', $store->name));

        return response()->json(['success' => true, 'message' => 'Store is visible again.', 'data' => $store]);
    }

    private function record(Request $request, ?Store $store, string $action, string $modelType, int $modelId, string $name, ?string $reason = null): void
    {
        $store?->auditLogs()->create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'model_type' => $modelType,
            'model_id' => $modelId,
            'payload' => [
                'name' => $name,
                'reason' => $reason,
                'description' => 'System Admin moderation: '.str_replace('_', ' ', $action).' — '.$name,
            ],
            'ip_address' => $request->ip(),
        ]);
    }
}
