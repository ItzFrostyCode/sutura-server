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
        $endDate = $request->query('end_date');

        $branchId = null;
        if ($request->user()->hasRole('branch_manager')) {
            $branchId = $request->user()->staffProfile->shop_branch_id ?? null;
        }

        // Overview Stats
        $jobsQuery = $shop->jobOrders();
        $appointmentsQuery = $shop->appointments();
        
        if ($branchId) {
            $jobsQuery->where('shop_branch_id', $branchId);
            $appointmentsQuery->where('shop_branch_id', $branchId);
        }

        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        if ($startDate && $endDate) {
            // Need to append time to ensure end date is inclusive of that whole day
            $jobsQuery->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
            $appointmentsQuery->whereBetween('scheduled_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        }

        $totalJobs = $jobsQuery->count();
        $completedJobs = (clone $jobsQuery)->where('status', 'completed')->count();
        $totalRevenue = (clone $jobsQuery)->sum('total_amount') - (clone $jobsQuery)->sum('balance');
        $totalBalance = (clone $jobsQuery)->sum('balance');
        
        $upcomingAppointments = $appointmentsQuery
            ->where('status', 'confirmed')
            ->count();
            
        $totalStaff = $shop->staff()->count();
        $totalCustomers = $shop->customers()->count();
        
        $lowStockItems = $shop->inventoryItems()
            ->whereColumn('current_stock', '<=', 'reorder_level')
            ->count();

        // Compute revenue data by week for the current month
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();
        
        $jobsThisMonth = (clone $shop->jobOrders())
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->get();
            
        $currentMonthName = now()->format('M Y');
        $revenueData = [
            ['month' => "Week 1", 'revenue' => 0],
            ['month' => "Week 2", 'revenue' => 0],
            ['month' => "Week 3", 'revenue' => 0],
            ['month' => "Week 4", 'revenue' => 0],
        ];
        
        foreach($jobsThisMonth as $job) {
            $week = ceil($job->created_at->day / 7);
            if ($week > 4) $week = 4; // cap at week 4
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

        return response()->json([
            'success' => true,
            'data' => [
                'total_jobs' => $totalJobs,
                'completed_jobs' => $completedJobs,
                'total_revenue' => $totalRevenue,
                'total_outstanding_balance' => $totalBalance,
                'upcoming_appointments' => $upcomingAppointments,
                'total_appointments' => $shop->appointments()->count(),
                'total_services' => $shop->services()->count(),
                'total_collections' => $shop->catalogItems()->count(),
                'total_branches' => \App\Models\ShopBranch::where('shop_id', $shop->id)->count(),
                'total_staff' => $totalStaff,
                'total_customers' => $totalCustomers,
                'low_stock_items' => $lowStockItems,
                'revenue_data' => $revenueData,
                'recent_jobs' => $recentJobs,
            ]
        ]);
    }
}
