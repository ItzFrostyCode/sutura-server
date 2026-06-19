<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApproveShopRequest;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Shop::with('owner');
        
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $perPage = $request->input('per_page', 15);
        $shops = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $shops->items(),
            'meta' => [
                'current_page' => $shops->currentPage(),
                'last_page' => $shops->lastPage(),
                'per_page' => $shops->perPage(),
                'total' => $shops->total(),
            ]
        ]);
    }

    public function approve(ApproveShopRequest $request, Shop $shop): JsonResponse
    {
        $shop->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Shop approved successfully.',
            'data' => $shop
        ]);
    }

    public function reject(Request $request, Shop $shop): JsonResponse
    {
        $request->validate(['rejection_reason' => 'required|string']);

        $shop->update([
            'status' => 'rejected',
            'rejection_reason' => $request->rejection_reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Shop rejected.',
            'data' => $shop
        ]);
    }
}
