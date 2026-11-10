<?php

namespace App\Http\Middleware;

use App\Models\JobOrder;
use App\Models\Store;
use App\Support\BranchPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard `branch.permission:<key>` for a delegable action on one order. The order's branch decides; a Branch
 * Manager needs the Owner's grant for that branch (DSD-02 WD-19).
 */
class RequireBranchPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $store = $request->route('store');
        $job = $request->route('jobOrder');
        if (! $store instanceof Store) {
            $store = Store::find($store);
        }
        if ($job !== null && ! $job instanceof JobOrder) {
            $job = JobOrder::find($job);
        }
        $user = $request->user();
        if (! $store || ! $user || ! BranchPermissions::allows($user, $store, $job?->store_branch_id ? (int) $job->store_branch_id : null, $permission)) {
            [$number, $label] = BranchPermissions::ALL[$permission];

            return response()->json([
                'success' => false,
                'message' => "This needs the owner's permission \"{$label}\" for your branch.",
                'code' => 'permission_required',
                'permission' => $permission,
                'permission_number' => $number,
            ], 403);
        }

        return $next($request);
    }
}
