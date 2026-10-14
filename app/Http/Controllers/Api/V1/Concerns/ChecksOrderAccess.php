<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\JobOrder;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared guard for the ordering-flow controllers: the order must belong to the {store} in the URL,
 * and a branch manager / staff pinned to a branch may only touch that branch's orders. (The
 * CheckRole middleware has already proven the user belongs to the store.)
 */
trait ChecksOrderAccess
{
    protected function orderAccessDenied(Request $request, Store $store, JobOrder $job): ?JsonResponse
    {
        if ($job->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Job order not found.'], 404);
        }

        $user = $request->user();
        if (! $user->hasRole('store_owner')) {
            $branch = $user->staffProfile->store_branch_id ?? null;
            if ($branch && $job->store_branch_id && (int) $branch !== (int) $job->store_branch_id) {
                return response()->json(['success' => false, 'message' => 'This job order belongs to a different branch.'], 403);
            }
        }

        return null;
    }

    protected function isMoneyRole(Request $request): bool
    {
        return $request->user()->hasRole('store_owner') || $request->user()->hasRole('branch_manager');
    }

    /** The owner or an active staff member of this shop. */
    protected function isStoreMember(Store $store, int $userId): bool
    {
        return \App\Models\User::where('id', $userId)->where(function ($q) use ($store) {
            $q->where('id', $store->owner_id)->orWhereHas('staffProfile', fn ($s) => $s->where('store_id', $store->id));
        })->exists();
    }

    protected function logOrderAction(Request $request, Store $store, JobOrder $job, string $action, array $payload = []): void
    {
        $store->auditLogs()->create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'model_type' => JobOrder::class,
            'model_id' => $job->id,
            'payload' => $payload,
            'ip_address' => $request->ip(),
        ]);
    }
}
