<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends Controller
{
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
        $totalCustomers = $shop->customers()->count();

        $lowStockItems = $shop->inventoryItems()
            ->whereColumn('current_stock', '<=', 'reorder_level')
            ->count();

        // Jobs by status breakdown — used for pie chart in Reports page
        $jobsByStatus = (clone $jobsQuery)
            ->select('status', \Illuminate\Support\Facades\DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => ['status' => $row->status, 'count' => (int) $row->count])
            ->values()
            ->toArray();

        // Compute revenue data by week for the current month
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();
        
        $jobsThisMonth = (clone $shop->jobOrders())
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->get();
            
        $revenueData = [
            ['month' => "Week 1", 'revenue' => 0],
            ['month' => "Week 2", 'revenue' => 0],
            ['month' => "Week 3", 'revenue' => 0],
            ['month' => "Week 4", 'revenue' => 0],
        ];
        
        foreach($jobsThisMonth as $job) {
            $week = ceil($job->created_at->day / 7);
            if ($week > 4) {
                $week = 4; // cap at week 4
            }
            $revenue = floatval($job->total_amount) - floatval($job->balance);
            if ($revenue > 0) {
                $revenueData[$week - 1]['revenue'] += $revenue;
            }
        }

        $recentJobs = (clone $shop->jobOrders())
            ->with('customer')
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        // ── New KPI Metrics ─────────────────────────────────────────────────────
        $today = now()->toDateString();

        // Overdue: active jobs past due_date
        $overdueJobs = (clone $shop->jobOrders())
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today)
            ->count();

        // Pending deposit: jobs with unpaid payment status, not cancelled
        $pendingDepositJobs = (clone $shop->jobOrders())
            ->where('payment_status', 'unpaid')
            ->whereNotIn('status', ['cancelled'])
            ->count();

        // Ready for pickup: walk-in orders at ready_for_pickup status
        $readyForPickupJobs = (clone $shop->jobOrders())
            ->where('status', 'ready_for_pickup')
            ->count();

        // Rush jobs currently active
        $rushJobsActive = (clone $shop->jobOrders())
            ->where('is_rush', true)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();

        // Today's revenue: sum of payments created today
        $todayRevenue = \App\Models\Payment::whereHas('jobOrder', function ($q) use ($shop) {
            $q->where('shop_id', $shop->id);
        })->whereDate('created_at', $today)->sum('amount');

        // Completion rate
        $completionRate = $totalJobs > 0 ? round(($completedJobs / $totalJobs) * 100, 1) : 0;

        // Average order value (from completed jobs)
        $avgOrderValue = $completedJobs > 0
            ? round((clone $shop->jobOrders())->where('status', 'completed')->avg('total_amount'), 2)
            : 0;

        // Today's appointments
        $todayAppointments = $shop->appointments()
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
                'low_stock_items'            => $lowStockItems,
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
            ]
        ]);
    }
}
