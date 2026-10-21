<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\Store;
use App\Support\OrderBatches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Splits a group order into linked orders, one per payer (owner / branch manager only). */
class OrderBatchController extends Controller
{
    public function split(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Job order not found'], 404);
        }
        $userBranch = $request->user()->hasRole('store_owner') ? null : ($request->user()->staffProfile->store_branch_id ?? null);
        if ($userBranch && $jobOrder->store_branch_id && (int) $userBranch !== (int) $jobOrder->store_branch_id) {
            return response()->json(['success' => false, 'message' => 'This job order belongs to a different branch.'], 403);
        }

        $v = $request->validate([
            'groups' => 'required|array|min:1|max:50',
            'groups.*.people' => 'required|array|min:1',
            'groups.*.people.*' => 'integer|min:0',
            'groups.*.paid_by_name' => 'nullable|string|max:120',
            'groups.*.total_amount' => 'nullable|numeric|min:0',
            'groups.*.discount_amount' => 'nullable|numeric|min:0',
        ]);

        $result = OrderBatches::split($store, $jobOrder->fresh(['assignees']), $v['groups'], $request->user(), $request->ip());
        if (! $result['ok']) {
            return response()->json(['success' => false, 'message' => $result['message']], 422);
        }

        return response()->json([
            'success' => true,
            'message' => count($result['children']) === 1 ? 'The order was split into 2 batches.' : 'The order was split into '.(count($result['children']) + 1).' batches.',
            'data' => ['order' => $jobOrder->fresh(), 'children' => collect($result['children'])->map(fn ($c) => ['id' => $c->id, 'order_number' => $c->order_number, 'tracking_code' => $c->tracking_code])->values()],
        ], 201);
    }
}
