<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    public function index(Shop $shop): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $shop->coupons()->latest()->get(),
        ]);
    }

    public function store(Request $request, Shop $shop): JsonResponse
    {
        $validated = $this->validatePayload($request, $shop);
        $validated['code'] = strtoupper($validated['code']);

        $coupon = $shop->coupons()->create($validated);

        return response()->json(['success' => true, 'data' => $coupon], 201);
    }

    public function update(Request $request, Shop $shop, Coupon $coupon): JsonResponse
    {
        if ($coupon->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $validated = $this->validatePayload($request, $shop, $coupon->id);
        $validated['code'] = strtoupper($validated['code']);

        $coupon->update($validated);

        return response()->json(['success' => true, 'data' => $coupon->fresh()]);
    }

    public function destroy(Shop $shop, Coupon $coupon): JsonResponse
    {
        if ($coupon->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $coupon->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Checks a code against the shop's coupons and previews the discount.
     * Reused by both the public booking wizard (catalog checkout) and the
     * owner's Job Order form (service checkout) — validating a code doesn't
     * require confirming who's asking, just whether the code itself works.
     */
    public function validateCode(Request $request, Shop $shop): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'context' => ['required', 'in:catalog,services'],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $coupon = $shop->coupons()->where('code', strtoupper($validated['code']))->first();

        if (!$coupon || !$coupon->isValidFor($validated['context'])) {
            return response()->json([
                'success' => false,
                'message' => 'This coupon code is invalid or no longer available.',
            ], 422);
        }

        $discount = $coupon->computeDiscount((float) $validated['amount']);

        return response()->json([
            'success' => true,
            'data' => [
                'coupon_id' => $coupon->id,
                'code' => $coupon->code,
                'discount_amount' => $discount,
                'new_total' => round((float) $validated['amount'] - $discount, 2),
            ],
        ]);
    }

    private function validatePayload(Request $request, Shop $shop, ?int $ignoreId = null): array
    {
        return $request->validate([
            'code' => [
                'required', 'string', 'max:50', 'alpha_dash',
                Rule::unique('coupons', 'code')->where('shop_id', $shop->id)->ignore($ignoreId),
            ],
            'discount_type' => ['required', 'in:percent,fixed'],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'applies_to' => ['required', 'in:all,catalog,services'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
