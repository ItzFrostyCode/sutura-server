<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The staff member's Home: the shop-floor numbers the thesis names (Orders in Production,
 * Quality Checks Needed, Pending Fittings) and "My Production Queue". Branch-scoped to the
 * branch the account is pinned to; an unpinned account sees the whole shop. Every number is
 * its own ->count() (never derived from the capped queue list) and nothing here is cached.
 */
class StaffOverviewController extends Controller
{
    private const IN_PRODUCTION = ['pattern_making', 'mass_cutting_printing', 'cutting', 'sewing', 'final_adjustments', 'in_repair'];

    private const QUALITY_CHECK = ['qc_ironing', 'qc_check'];

    private const QUEUE_LIMIT = 8;

    public function show(Request $request, Store $store): JsonResponse
    {
        $user = $request->user();
        $branchId = $user->staffProfile->store_branch_id ?? null;
        $scope = fn ($q) => $q->when($branchId, fn ($qq) => $qq->where('store_branch_id', $branchId));

        $jobs = fn () => $scope($store->jobOrders());
        $fittings = fn () => $scope($store->appointments())
            ->where('appointment_type', 'fitting')
            ->whereIn('status', ['pending', 'confirmed', 'in_progress'])
            ->where('scheduled_at', '>=', now()->startOfDay());

        $mine = DB::table('job_order_staff')->where('user_id', $user->id)->pluck('job_order_id')->all();
        $isMine = fn (JobOrder $j) => (int) $j->assigned_staff_id === (int) $user->id || in_array($j->id, $mine, true);

        $queue = $jobs()->whereIn('status', array_merge(['design'], self::IN_PRODUCTION, self::QUALITY_CHECK, ['ready_for_fitting']))
            ->with(['customer:id,name', 'service:id,name', 'catalogItem:id,name'])
            ->orderBy('due_date')->limit(40)->get()
            ->map(fn (JobOrder $j) => [
                'id' => $j->id,
                'order_number' => $j->order_number,
                'customer' => $j->customer?->name,
                'what' => $j->catalogItem?->name ?? $j->service?->name ?? $j->garment_category,
                'status' => $j->status,
                'due_date' => $j->due_date,
                'is_rush' => (bool) $j->is_rush,
                'is_mine' => $isMine($j),
                // Overdue, or due within two days.
                'urgent' => $j->due_date && $j->due_date->lte(now()->addDays(2)->endOfDay()),
            ])
            // Mine first, then by due date (already ordered).
            ->sortBy(fn ($row) => $row['is_mine'] ? 0 : 1)->values()->take(self::QUEUE_LIMIT);

        return response()->json(['success' => true, 'data' => [
            'orders_in_production' => $jobs()->whereIn('status', self::IN_PRODUCTION)->count(),
            'quality_checks_needed' => $jobs()->whereIn('status', self::QUALITY_CHECK)->count(),
            'pending_fittings' => $fittings()->count(),
            'my_jobs' => $jobs()->whereNotIn('status', ['completed', 'cancelled', 'rejected'])
                ->where(fn ($q) => $q->where('assigned_staff_id', $user->id)->orWhereIn('job_orders.id', $mine))->count(),
            'queue' => $queue,
            'fittings' => $fittings()->with(['customer:id,name', 'jobOrder:id,order_number'])
                ->orderBy('scheduled_at')->limit(5)->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'customer' => $a->customer?->name,
                    'order_number' => $a->jobOrder?->order_number,
                    'scheduled_at' => $a->scheduled_at,
                    'status' => $a->status,
                    'is_mine' => (int) $a->assigned_staff_id === (int) $user->id,
                ]),
        ]]);
    }
}
