<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends Controller
{
    /**
     * Per-branch performance breakdown — an owner-level strategic view (which
     * location is actually profitable), so it is deliberately not exposed to
     * branch managers the way the regular branch-scoped analytics are.
     */
    public function branchComparison(\Illuminate\Http\Request $request, Shop $shop): JsonResponse
    {
        $startDate = $request->query('start_date');
        $endDate   = $request->query('end_date');

        $scopeToRange = function ($query, string $dateColumn) use ($startDate, $endDate) {
            if ($startDate && $endDate) {
                $query->whereBetween($dateColumn, [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
            }
            return $query;
        };

        $buildRow = function (?int $branchId, string $name, bool $isMain) use ($shop, $scopeToRange) {
            $jobsQuery = $branchId
                ? $shop->jobOrders()->where('shop_branch_id', $branchId)
                : $shop->jobOrders()->whereNull('shop_branch_id');
            $appointmentsQuery = $branchId
                ? $shop->appointments()->where('shop_branch_id', $branchId)
                : $shop->appointments()->whereNull('shop_branch_id');

            $scopeToRange($jobsQuery, 'created_at');
            $scopeToRange($appointmentsQuery, 'scheduled_at');

            $totalJobs     = $jobsQuery->count();
            $completedJobs = (clone $jobsQuery)->where('status', 'completed')->count();

            return [
                'branch_id'                 => $branchId,
                'branch_name'               => $name,
                'is_main'                   => $isMain,
                'total_jobs'                => $totalJobs,
                'completed_jobs'            => $completedJobs,
                'completion_rate'           => $totalJobs > 0 ? round(($completedJobs / $totalJobs) * 100, 1) : 0,
                'total_revenue'             => (clone $jobsQuery)->sum('total_amount') - (clone $jobsQuery)->sum('balance'),
                'total_outstanding_balance' => (clone $jobsQuery)->sum('balance'),
                'total_appointments'        => $appointmentsQuery->count(),
                'total_staff'               => $branchId ? \App\Models\StaffProfile::where('shop_branch_id', $branchId)->count() : 0,
            ];
        };

        $branches = $shop->branches()->orderByDesc('is_main')->get();
        $rows = $branches->map(fn ($branch) => $buildRow($branch->id, $branch->name, (bool) $branch->is_main))->values();

        // Jobs/appointments never tagged to a branch (legacy data, or a
        // single-branch shop) still need to be visible somewhere, not silently
        // dropped from the comparison.
        $unassigned = $buildRow(null, 'Unassigned', false);
        if ($unassigned['total_jobs'] > 0 || $unassigned['total_appointments'] > 0) {
            $rows->push($unassigned);
        }

        return response()->json([
            'success' => true,
            'data' => $rows->values(),
        ]);
    }

    /**
     * Per-staff productivity breakdown — an owner-level strategic view (which
     * staff member is completing/carrying the most work), so like
     * branchComparison() it is deliberately not exposed to branch managers.
     */
    public function staffProductivity(\Illuminate\Http\Request $request, Shop $shop): JsonResponse
    {
        $startDate = $request->query('start_date');
        $endDate   = $request->query('end_date');
        $branchId  = $request->filled('branch_id') ? $request->branch_id : null;

        $scopeToRange = function ($query) use ($startDate, $endDate) {
            if ($startDate && $endDate) {
                $query->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
            }
            return $query;
        };

        $staffQuery = $shop->staff()->with('user:id,name')->where('is_active', true);
        if ($branchId) {
            $staffQuery->where('shop_branch_id', $branchId);
        }

        $rows = $staffQuery->get()->map(function (\App\Models\StaffProfile $profile) use ($shop, $scopeToRange) {
            // A job's single assigned_staff_id only reflects whichever
            // production stage was assigned first — a staff member working a
            // later stage (e.g. sewing, when someone else did cutting first)
            // would otherwise never show up here despite doing real work on
            // the job, so also credit jobs where they're assigned to ANY
            // stage via the Multi-Stage Staff Assignment pivot.
            $jobsQuery = $shop->jobOrders()->where(function ($q) use ($profile) {
                $q->where('assigned_staff_id', $profile->user_id)
                  ->orWhereHas('staffStages', function ($sq) use ($profile) {
                      // staffStages is a belongsToMany(User::class, ...), so the
                      // related model's own key is `id`, not `user_id` — the
                      // pivot's user_id is what the join already matches on.
                      // Qualified with the table name since both job_orders
                      // and users have an `id` column, which is otherwise
                      // ambiguous inside this EXISTS subquery.
                      $sq->where('users.id', $profile->user_id);
                  });
            });
            $scopeToRange($jobsQuery);

            $totalJobs     = $jobsQuery->count();
            $completedJobs = (clone $jobsQuery)->where('status', 'completed')->count();

            return [
                'staff_id'        => $profile->user_id,
                'name'            => $profile->user?->name,
                'role'            => $profile->role,
                'total_jobs'      => $totalJobs,
                'completed_jobs'  => $completedJobs,
                'completion_rate' => $totalJobs > 0 ? round(($completedJobs / $totalJobs) * 100, 1) : 0,
                'total_revenue'   => (float) (clone $jobsQuery)->where('status', 'completed')->sum('total_amount'),
            ];
        })->sortByDesc('completed_jobs')->values();

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    public function index(\Illuminate\Http\Request $request, Shop $shop): JsonResponse
    {
        $startDate = $request->query('start_date');
        $endDate   = $request->query('end_date');

        $branchId = null;
        if ($request->user()->hasRole('branch_manager')) {
            $branchId = $request->user()->staffProfile->shop_branch_id ?? null;
        } elseif ($request->filled('branch_id')) {
            $branchId = $request->branch_id;
        }

        // Branch-scoped base queries for the triage KPIs. Fresh builder each call and
        // NOT date-filtered — these are "current state" metrics (overdue/pending/etc.),
        // but they must still respect the selected branch.
        $branchJobs = fn () => $branchId
            ? $shop->jobOrders()->where('shop_branch_id', $branchId)
            : $shop->jobOrders();
        $branchAppointments = fn () => $branchId
            ? $shop->appointments()->where('shop_branch_id', $branchId)
            : $shop->appointments();

        // Overview Stats
        $jobsQuery        = $shop->jobOrders();
        $appointmentsQuery = $shop->appointments();

        if ($branchId) {
            $jobsQuery->where('shop_branch_id', $branchId);
            $appointmentsQuery->where('shop_branch_id', $branchId);
        }

        if ($startDate && $endDate) {
            // Need to append time to ensure end date is inclusive of that whole day
            $jobsQuery->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
            $appointmentsQuery->whereBetween('scheduled_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        }

        $totalJobs     = $jobsQuery->count();
        $completedJobs = (clone $jobsQuery)->where('status', 'completed')->count();
        $totalRevenue  = (clone $jobsQuery)->sum('total_amount') - (clone $jobsQuery)->sum('balance');
        $totalBalance  = (clone $jobsQuery)->sum('balance');

        $upcomingAppointments = $appointmentsQuery
            ->where('status', 'confirmed')
            ->count();

        $totalStaff     = $shop->staff()->count();
        // The shop_customers pivot is only populated by the CRM's own "Add
        // Customer" form — a customer who came in via a job order, walk-in
        // creation, or public appointment booking never gets attached to it,
        // so counting the pivot alone showed 0 for shops whose customers all
        // arrived through those other paths. Same derivation CustomerController@index
        // already uses for the actual Customers list, extended to also count
        // appointment-only customers who don't have a job order yet.
        $totalCustomers = collect()
            ->merge($shop->customers()->pluck('users.id'))
            ->merge($shop->jobOrders()->pluck('customer_id'))
            ->merge($shop->appointments()->pluck('customer_id'))
            ->filter()
            ->unique()
            ->count();

        // Jobs by status breakdown — used for pie chart in Reports page
        $jobsByStatus = (clone $jobsQuery)
            ->select('status', \Illuminate\Support\Facades\DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => ['status' => $row->status, 'count' => (int) $row->count])
            ->values()
            ->toArray();

        // Compute revenue data split into 4 buckets across the selected range
        // (defaults to the current month), branch-scoped.
        $rangeStart = $startDate ? \Illuminate\Support\Carbon::parse($startDate)->startOfDay() : now()->startOfMonth();
        $rangeEnd   = $endDate ? \Illuminate\Support\Carbon::parse($endDate)->endOfDay() : now()->endOfMonth();
        $rangeSeconds = max(1, abs($rangeStart->diffInSeconds($rangeEnd)));
        
        $jobsThisMonth = $branchJobs()
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->get();
            
        $revenueData = [
            ['month' => "Week 1", 'revenue' => 0],
            ['month' => "Week 2", 'revenue' => 0],
            ['month' => "Week 3", 'revenue' => 0],
            ['month' => "Week 4", 'revenue' => 0],
        ];
        
        foreach($jobsThisMonth as $job) {
            $elapsed = abs($rangeStart->diffInSeconds($job->created_at));
            $bucket = (int) floor(($elapsed / $rangeSeconds) * 4);
            $bucket = max(0, min(3, $bucket));
            $revenue = floatval($job->total_amount) - floatval($job->balance);
            if ($revenue > 0) {
                $revenueData[$bucket]['revenue'] += $revenue;
            }
        }

        $recentJobs = $branchJobs()
            ->with('customer')
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        // ── New KPI Metrics ─────────────────────────────────────────────────────
        $today = now()->toDateString();

        // Overdue: active jobs past due_date
        $overdueJobs = $branchJobs()
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today)
            ->count();

        // Pending deposit: jobs with unpaid payment status, not cancelled
        $pendingDepositJobs = $branchJobs()
            ->where('payment_status', 'unpaid')
            ->whereNotIn('status', ['cancelled'])
            ->count();

        // Ready for pickup: walk-in orders at ready_for_pickup status
        $readyForPickupJobs = $branchJobs()
            ->where('status', 'ready_for_pickup')
            ->count();

        // Rush jobs currently active
        $rushJobsActive = $branchJobs()
            ->where('is_rush', true)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();

        // Today's revenue: sum of payments created today
        $todayRevenue = \App\Models\Payment::whereHas('jobOrder', function ($q) use ($shop, $branchId) {
            $q->where('shop_id', $shop->id);
            if ($branchId) {
                $q->where('shop_branch_id', $branchId);
            }
        })->whereDate('created_at', $today)->sum('amount');

        // Completion rate
        $completionRate = $totalJobs > 0 ? round(($completedJobs / $totalJobs) * 100, 1) : 0;

        // Average order value (from completed jobs)
        $avgOrderValue = $completedJobs > 0
            ? round($branchJobs()->where('status', 'completed')->avg('total_amount'), 2)
            : 0;

        // Outstanding balances ledger — who owes how much, not just the aggregate
        // total, so the owner can actually chase specific unpaid accounts.
        $outstandingBalances = $branchJobs()
            ->where('balance', '>', 0)
            ->whereNotIn('status', ['cancelled'])
            ->with('customer:id,name,phone')
            ->orderByDesc('balance')
            ->take(20)
            ->get(['id', 'order_number', 'customer_id', 'total_amount', 'balance', 'due_date', 'status'])
            ->map(fn ($job) => [
                'id' => $job->id,
                'order_number' => $job->order_number,
                'customer' => $job->customer ? ['id' => $job->customer->id, 'name' => $job->customer->name, 'phone' => $job->customer->phone] : null,
                'total_amount' => (float) $job->total_amount,
                'balance' => (float) $job->balance,
                'due_date' => $job->due_date,
                'status' => $job->status,
            ]);

        // Today's appointments
        $todayAppointments = $branchAppointments()
            ->with(['customer:id,name', 'service:id,name'])
            ->whereDate('scheduled_at', $today)
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn($a) => [
                'id'               => $a->id,
                'scheduled_at'     => $a->scheduled_at,
                'appointment_type' => $a->appointment_type,
                'status'           => $a->status,
                'customer'         => $a->customer,
                'service'          => $a->service,
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'total_jobs'                 => $totalJobs,
                'completed_jobs'             => $completedJobs,
                'total_revenue'              => $totalRevenue,
                'total_outstanding_balance'  => $totalBalance,
                'upcoming_appointments'      => $upcomingAppointments,
                'total_appointments'         => $shop->appointments()->count(),
                'total_services'             => $shop->services()->count(),
                'total_collections'          => $shop->catalogItems()->count(),
                'total_branches'             => \App\Models\ShopBranch::where('shop_id', $shop->id)->count(),
                'total_staff'                => $totalStaff,
                'total_customers'            => $totalCustomers,
                'revenue_data'               => $revenueData,
                'jobs_by_status'             => $jobsByStatus,
                'recent_jobs'                => $recentJobs,
                // ── New KPIs ──────────────────────────────────────────────────
                'overdue_jobs'               => $overdueJobs,
                'pending_deposit_jobs'       => $pendingDepositJobs,
                'ready_for_pickup_jobs'      => $readyForPickupJobs,
                'rush_jobs_active'           => $rushJobsActive,
                'today_revenue'              => $todayRevenue,
                'completion_rate'            => $completionRate,
                'avg_order_value'            => $avgOrderValue,
                'today_appointments'         => $todayAppointments,
                'outstanding_balances'       => $outstandingBalances,
            ]
        ]);
    }
}
