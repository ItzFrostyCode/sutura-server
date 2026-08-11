<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use Illuminate\Http\JsonResponse;

/**
 * Backend-only for now, no consuming page yet (see the loop's memory note
 * requesting a follow-up frontend task) — lets a customer who doesn't want
 * to create an account still check their order status by code alone,
 * mirroring how courier/delivery tracking pages work. No auth: the
 * tracking_code itself (a random 8-char string, not the job order id) is
 * the credential — same trust model as a GCash/JNT tracking number.
 */
class JobOrderTrackingController extends Controller
{
    public function show(string $trackingCode): JsonResponse
    {
        $jobOrder = JobOrder::where('tracking_code', strtoupper($trackingCode))
            ->with(['shop:id,name,slug,logo_path', 'service:id,name'])
            ->first();

        if (!$jobOrder) {
            return response()->json([
                'success' => false,
                'message' => 'No order found for that tracking code. Double-check the code and try again.',
            ], 404);
        }

        // Deliberately narrow — only what a customer needs to answer "ano
        // ang ginatahi / saan na ang order ko / magkano na ang nabayad"
        // (the exact three questions this project's own UX principles name).
        // No customer PII beyond what they'd already know, no staff
        // assignments, no internal notes, no other customers' data — the
        // code has no login behind it, so the response has to be safe to
        // hand to anyone who has the code, same as a courier tracking page.
        return response()->json([
            'success' => true,
            'data' => [
                'order_number'     => $jobOrder->order_number,
                'status'           => $jobOrder->status,
                'garment_category' => $jobOrder->garment_category,
                'service_name'     => $jobOrder->service?->name,
                'is_rush'          => (bool) $jobOrder->is_rush,
                'due_date'         => $jobOrder->due_date,
                'total_amount'     => (float) $jobOrder->total_amount,
                'balance'          => (float) $jobOrder->balance,
                'payment_status'   => $jobOrder->payment_status,
                'created_at'       => $jobOrder->created_at,
                'progress_photos'  => $jobOrder->progress_photos,
                'shop'             => $jobOrder->shop ? [
                    'name' => $jobOrder->shop->name,
                    'slug' => $jobOrder->shop->slug,
                    'logo_path' => $jobOrder->shop->logo_path,
                ] : null,
            ],
        ]);
    }
}
