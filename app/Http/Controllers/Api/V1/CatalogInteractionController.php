<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\CatalogItemReview;
use App\Models\Store;
use App\Models\SupportTicket;
use App\Notifications\CatalogItemReviewReplyNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogInteractionController extends Controller
{
    private const NOT_FOUND_MESSAGE = 'Not found';

    public function incrementViews(Store $store, CatalogItem $catalogItem): JsonResponse
    {
        if ($catalogItem->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => self::NOT_FOUND_MESSAGE], 404);
        }

        $catalogItem->increment('views_count');

        return response()->json(['success' => true, 'views_count' => $catalogItem->views_count]);
    }

    public function toggleSave(Request $request, Store $store, CatalogItem $catalogItem): JsonResponse
    {
        if ($catalogItem->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => self::NOT_FOUND_MESSAGE], 404);
        }

        $user = $request->user();

        $existingSave = $catalogItem->saves()->where('user_id', $user->id)->first();

        if ($existingSave) {
            $existingSave->delete();
            $status = 'unsaved';
        } else {
            $catalogItem->saves()->create(['user_id' => $user->id]);
            $status = 'saved';
        }

        return response()->json([
            'success' => true,
            'status' => $status,
            'saves_count' => $catalogItem->saves()->count(),
        ]);
    }

    // Single source of truth for valid report-reason values — referenced by
    // report()'s validation and the frontend's reason list.
    public const REPORT_REASONS = ['copyright', 'offensive', 'illegal', 'other'];

    /**
     * Report a catalog item for review — Copyright/Offensive/Illegal/Other,
     * with an optional free-text description. Lands as a real SupportTicket
     * (type=product_report) rather than a bespoke reports table, so it
     * reaches System Admin the same way a store owner's ticket does, and
     * shows up in the reporting customer's own My Support Tickets list
     * (SupportTicketController::myTickets/myTicketShow/myTicketReply).
     */
    public function report(Request $request, Store $store, CatalogItem $catalogItem): JsonResponse
    {
        if ($catalogItem->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => self::NOT_FOUND_MESSAGE], 404);
        }

        $validated = $request->validate([
            'reason' => 'required|string|in:'.implode(',', self::REPORT_REASONS),
            'description' => 'nullable|string|max:500',
        ]);

        $reasonLabels = [
            'copyright' => 'Copyright',
            'offensive' => 'Offensive',
            'illegal' => 'Illegal',
            'other' => 'Other',
        ];

        $message = 'Reason: '.$reasonLabels[$validated['reason']];
        if (! empty($validated['description'])) {
            $message .= "\n\n".$validated['description'];
        }

        $ticket = SupportTicket::create([
            'store_id' => $store->id,
            'user_id' => $request->user()->id,
            'subject' => 'Product Report: '.$catalogItem->name,
            'message' => $message,
            'type' => 'product_report',
            'priority' => 'medium',
            'status' => 'open',
        ]);

        return response()->json([
            'success' => true,
            'message' => "Thanks — we've received your report and will look into it.",
            'ticket_id' => $ticket->id,
        ], 201);
    }

    /**
     * Cross-store "my ratings" for the Showroom tab of the customer's Star
     * Ratings page — same customer-scoped, no-role-gate pattern as
     * /my-orders, /my-appointments, /my-measurements. No comment field in
     * the response shape the frontend cares about; comments still exist on
     * the row (and on the owner's own review list) but this app's rating
     * UI is star-only now.
     */
    public function myReviews(Request $request): JsonResponse
    {
        $reviews = CatalogItemReview::where('user_id', $request->user()->id)
            ->with(['catalogItem.images', 'catalogItem.store:id,name,slug'])
            ->latest()
            ->get()
            ->filter(fn (CatalogItemReview $r) => $r->catalogItem !== null)
            ->map(function (CatalogItemReview $r) {
                $item = $r->catalogItem;
                $item->loadCount(['reviews', 'catalogOrders', 'jobOrders']);
                $item->loadAvg('reviews', 'rating');

                return [
                    'rating' => $r->rating,
                    'rated_at' => $r->updated_at,
                    'id' => $item->id,
                    'name' => $item->name,
                    'price' => $item->price,
                    'material' => $item->material,
                    'estimated_days' => $item->estimated_days,
                    'reviews_count' => $item->reviews_count,
                    'reviews_avg_rating' => $item->reviews_avg_rating !== null ? round((float) $item->reviews_avg_rating, 1) : null,
                    'order_count' => $item->catalog_orders_count + $item->job_orders_count,
                    'images' => $item->images->map(fn ($img) => ['image_url' => $img->image_url, 'is_primary' => $img->is_primary])->values(),
                    'store' => $item->store ? ['name' => $item->store->name, 'slug' => $item->store->slug] : null,
                ];
            })
            ->values();

        return response()->json(['success' => true, 'data' => $reviews]);
    }

    public function rate(Request $request, Store $store, CatalogItem $catalogItem): JsonResponse
    {
        if ($catalogItem->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => self::NOT_FOUND_MESSAGE], 404);
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string',
        ]);

        $user = $request->user();

        $review = $catalogItem->reviews()->updateOrCreate(
            ['user_id' => $user->id],
            ['rating' => $validated['rating'], 'comment' => $validated['comment'] ?? null]
        );

        $averageRating = $catalogItem->reviews()->avg('rating');

        return response()->json([
            'success' => true,
            'average_rating' => round($averageRating, 1),
            'reviews_count' => $catalogItem->reviews()->count(),
            'review' => $review,
        ]);
    }

    /**
     * Owner-facing list of every review left on any of this store's catalog
     * items — mirrors StoreReviewController::index, but scoped one level
     * down (per-item, not per-store). Before this, a customer could rate/
     * comment on a specific Barong/gown design and the owner had no page
     * anywhere that surfaced it.
     */
    public function indexForStore(Store $store, Request $request): JsonResponse
    {
        $query = CatalogItemReview::whereHas('catalogItem', fn ($q) => $q->where('store_id', $store->id))
            ->with(['user:id,name,email', 'catalogItem:id,name']);

        if ($request->has('rating')) {
            $query->where('rating', $request->rating);
        }

        return response()->json([
            'success' => true,
            'data' => $query->latest()->paginate($request->input('per_page', 15)),
        ]);
    }

    public function replyToReview(Request $request, Store $store, CatalogItemReview $review): JsonResponse
    {
        if ($review->catalogItem?->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => self::NOT_FOUND_MESSAGE], 404);
        }

        $validated = $request->validate([
            'reply' => 'nullable|string|max:1000',
        ]);

        // Only notify on an actual new/changed non-empty reply — not on
        // every save of an unrelated field, and not when the reply is being
        // cleared back to blank.
        $isNewReply = ! empty($validated['reply']) && $validated['reply'] !== $review->reply;

        $review->update($validated);

        if ($isNewReply && $review->user) {
            $review->user->notify(new CatalogItemReviewReplyNotification($review->fresh(['catalogItem.store'])));
        }

        return response()->json([
            'success' => true,
            'data' => $review->fresh(['user:id,name,email', 'catalogItem:id,name']),
        ]);
    }

    public function destroyReview(Store $store, CatalogItemReview $review): JsonResponse
    {
        if ($review->catalogItem?->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => self::NOT_FOUND_MESSAGE], 404);
        }

        $review->delete();

        return response()->json(['success' => true, 'message' => 'Review deleted successfully']);
    }
}
