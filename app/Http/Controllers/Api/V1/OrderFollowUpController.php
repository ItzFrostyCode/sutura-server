<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ChecksOrderAccess;
use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\Store;
use App\Notifications\JobStatusUpdatedNotification;
use App\Notifications\NewJobOrderNotification;
use App\Support\FreeFixOrder;
use App\Support\SafeNotify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A free fix: the shop's mistake on a completed order. It becomes a new order linked to the first
 * (follow_up_of_id) that starts in Confirmed at P0: no price talk, nothing to approve or pay.
 * Owner / branch manager only. A customer's request or a repeat order goes through Request as usual.
 */
class OrderFollowUpController extends Controller
{
    use ChecksOrderAccess;

    public function freeFix(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder)) {
            return $denied;
        }
        if (! $this->isMoneyRole($request)) {
            return response()->json(['success' => false, 'message' => 'Only the owner or a branch manager can create a free fix.'], 403);
        }
        if ($jobOrder->status !== 'completed') {
            return response()->json(['success' => false, 'message' => 'A free fix can only follow a completed order.'], 422);
        }
        $v = $request->validate([
            'description' => ['required', 'string', 'max:500'],
            'pieces' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $description = trim($v['description']);
        if ($description === '') {
            return response()->json(['success' => false, 'message' => 'Tell us what needs fixing.', 'errors' => ['description' => ['Required.']]], 422);
        }
        $pieces = (int) ($v['pieces'] ?? $jobOrder->orderedQuantity());
        $user = $request->user();
        $note = "Free fix for {$jobOrder->order_number}: {$description}";

        $fix = DB::transaction(function () use ($request, $store, $jobOrder, $user, $pieces, $note) {
            $fix = FreeFixOrder::create($store, $jobOrder, $user, $note, $pieces);

            $this->logOrderAction($request, $store, $fix, 'job_order_free_fix', [
                'order_number' => $fix->order_number,
                'fix_of' => $jobOrder->order_number,
                'fix_of_id' => $jobOrder->id,
                'pieces' => $pieces,
                'description' => $note,
            ]);

            return $fix;
        });

        $fix->load(['customer:id,name', 'service']);
        SafeNotify::send($fix->customer, new JobStatusUpdatedNotification($fix, 'confirmed', $user));
        SafeNotify::send($store->owner, new NewJobOrderNotification($fix));

        return response()->json(['success' => true, 'message' => 'Free fix order created.', 'data' => $fix], 201);
    }
}
