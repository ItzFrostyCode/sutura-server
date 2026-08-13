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
    /**
     * start_date/end_date used to be read straight off the query string and
     * handed to Carbon::parse()/whereBetween() unvalidated — a malformed
     * value (e.g. a garbled URL edit, not just malicious input) crashed the
     * endpoint with an uncaught Carbon\Exceptions\InvalidFormatException,
     * leaking a full stack trace with server file paths in the response.
     */
    private function validateDateRange(\Illuminate\Http\Request $request): array
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date'   => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        return [$validated['start_date'] ?? null, $validated['end_date'] ?? null];
    }

    public function branchComparison(\Illuminate\Http\Request $request, Shop $shop): JsonResponse
    {
        [$startDate, $endDate] = $this->validateDateRange($request);

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
            // Walk-in/RTW orders now carry branch attribution too (previously
            // the only order type this comparison couldn't see at all).
            $catalogOrdersQuery = \App\Models\CatalogOrder::where('shop_id', $shop->id)
                ->when($branchId, fn ($q) => $q->where('shop_branch_id', $branchId), fn ($q) => $q->whereNull('shop_branch_id'));

            $scopeToRange($jobsQuery, 'created_at');
            $scopeToRange($appointmentsQuery, 'scheduled_at');
            $scopeToRange($catalogOrdersQuery, 'created_at');

            $totalJobs     = $jobsQuery->count();
            $completedJobs = (clone $jobsQuery)->where('status', 'completed')->count();

            $rejectedAmount = (float) \App\Models\Payment::whereNotNull('rejected_at')
                ->whereIn('job_order_id', (clone $jobsQuery)->pluck('id'))
                ->sum('amount');

            $forfeitedIds = (clone $jobsQuery)
                ->where('status', 'cancelled')
                ->where('cancellation_reason', 'forfeited_deposit_abandoned')
                ->pluck('id');
            $forfeitedAmount = (float) \App\Models\Payment::whereIn('job_order_id', $forfeitedIds)
                ->whereNull('rejected_at')
                ->sum('amount');

            return [
                'branch_id'                 => $branchId,
                'branch_name'               => $name,
                'is_main'                   => $isMain,
                'total_jobs'                => $totalJobs,
                'completed_jobs'            => $completedJobs,
                'completion_rate'           => $totalJobs > 0 ? round(($completedJobs / $totalJobs) * 100, 1) : 0,
                // applyDiscount reduces balance directly, not total_amount —
                // subtracting discount_amount too keeps this an actual
                // "money earned" figure instead of counting a discount as
                // if it were collected revenue.
                'total_revenue'             => (clone $jobsQuery)->sum('total_amount') - (clone $jobsQuery)->sum('balance') - (clone $jobsQuery)->sum('discount_amount'),
                'total_outstanding_balance' => (clone $jobsQuery)->sum('balance'),
                'total_appointments'        => $appointmentsQuery->count(),
                'total_walkin_orders'       => $catalogOrdersQuery->count(),
                'total_staff'               => $branchId ? \App\Models\StaffProfile::where('shop_branch_id', $branchId)->count() : 0,
                'rejected_payments_amount'  => $rejectedAmount,
                'forfeited_deposit_amount'  => $forfeitedAmount,
            ];
        };

        $branches = $shop->branches()->orderByDesc('is_main')->get();
        $rows = $branches->map(fn ($branch) => $buildRow($branch->id, $branch->name, (bool) $branch->is_main))->values();

        // Jobs/appointments never tagged to a branch (legacy data, or a
        // single-branch shop) still need to be visible somewhere, not silently
        // dropped from the comparison.
        $unassigned = $buildRow(null, 'Unassigned', false);
        if ($unassigned['total_jobs'] > 0 || $unassigned['total_appointments'] > 0 || $unassigned['total_walkin_orders'] > 0) {
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
        [$startDate, $endDate] = $this->validateDateRange($request);
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

            // Average Final Adjustment rounds across this staff member's jobs
            // — a real quality signal (which tailor's work needs redoing most
            // often), possible now that adjustment_count is actually tracked
            // (see JobOrderController@update) but was never connected to a
            // per-staff view before.
            $avgAdjustments = $totalJobs > 0
                ? round((float) (clone $jobsQuery)->avg('adjustment_count'), 1)
                : 0;

            return [
                'staff_id'            => $profile->user_id,
                'name'                => $profile->user?->name,
                'role'                => $profile->role,
                'total_jobs'          => $totalJobs,
                'completed_jobs'      => $completedJobs,
                'completion_rate'     => $totalJobs > 0 ? round(($completedJobs / $totalJobs) * 100, 1) : 0,
                // Same discount fix as the shop-wide/branch revenue figures —
                // a completed job's total_amount alone still includes any
                // discount, overstating what this staff member's work
                // actually brought in. Also subtract balance, not just
                // discount_amount: a completed job's balance is normally 0,
                // but rejectPayment() can reopen it after the fact (a fraud
                // catch on an already-completed job), and this figure must
                // not keep counting that reversed amount as earned revenue —
                // same formula as every other revenue figure in this file.
                'total_revenue'       => (float) (clone $jobsQuery)->where('status', 'completed')->sum('total_amount')
                    - (float) (clone $jobsQuery)->where('status', 'completed')->sum('balance')
                    - (float) (clone $jobsQuery)->where('status', 'completed')->sum('discount_amount'),
                'avg_adjustments'     => $avgAdjustments,
            ];
        })->sortByDesc('completed_jobs')->values();

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    public function index(\Illuminate\Http\Request $request, Shop $shop): JsonResponse
    {
        [$startDate, $endDate] = $this->validateDateRange($request);

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
        // Same discount-vs-collected-cash fix as branchComparison — a
        // discount reduces balance directly, never total_amount, so it has
        // to be subtracted separately or it inflates this top-line KPI.
        $totalRevenue  = (clone $jobsQuery)->sum('total_amount') - (clone $jobsQuery)->sum('balance') - (clone $jobsQuery)->sum('discount_amount');
        $totalBalance  = (clone $jobsQuery)->sum('balance');

        // Booking conversion rate — job_order_id (not the 'outcome' column)
        // is the reliable signal: outcome only gets set when an owner goes
        // through the "Complete Appointment" modal, but a job is just as
        // often created straight from an appointment via the separate
        // Job Creation form's own appointment_id link (now also stamping
        // outcome, see JobOrderController@store, but job_order_id has
        // always been the ground truth regardless of which path was used).
        // cancelled/no_show appointments were never going to convert, so
        // they're excluded from the denominator rather than counted as a
        // conversion failure.
        $convertibleAppointments = (clone $appointmentsQuery)->whereNotIn('status', ['cancelled', 'no_show'])->count();
        $convertedAppointments = (clone $appointmentsQuery)->whereNotIn('status', ['cancelled', 'no_show'])->whereNotNull('job_order_id')->count();
        $bookingConversionRate = $convertibleAppointments > 0
            ? round(($convertedAppointments / $convertibleAppointments) * 100, 1)
            : 0;

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

        // Orders by garment category — descriptive breakdown of what the shop
        // is actually being asked to make (barong vs. gown vs. alterations,
        // etc.), the literal core of a "tailoring" report. Reports previously
        // had no view of this at all, only revenue/status/branch/staff
        // breakdowns — nothing tied to the garment itself. Purely descriptive
        // counts/sums over the same date-range/branch scope as every other
        // KPI here, not predictive — stays within the thesis's descriptive-
        // reporting scope (no forecasting).
        $garmentBreakdown = (clone $jobsQuery)
            ->whereNotNull('garment_category')
            ->select(
                'garment_category',
                \Illuminate\Support\Facades\DB::raw('COUNT(*) as count'),
                \Illuminate\Support\Facades\DB::raw('SUM(total_amount) as revenue')
            )
            ->groupBy('garment_category')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'garment_category' => $row->garment_category,
                'count' => (int) $row->count,
                'revenue' => (float) $row->revenue,
            ])
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
            
        // Bucket labels used to be hard-coded "Week 1"-"Week 4" regardless of
        // the selected range — correct-looking for the "This Month" default,
        // but nonsensical once a period selector actually varies the range
        // (see the dashboard's Business Performance chart): a full year
        // split into 4 buckets isn't "Week 1", it's roughly a quarter. Label
        // each bucket by its own real start date instead, which reads
        // correctly no matter how wide the range is.
        $bucketSeconds = $rangeSeconds / 4;
        $revenueData = [];
        for ($i = 0; $i < 4; $i++) {
            $bucketStart = $rangeStart->copy()->addSeconds((int) round($i * $bucketSeconds));
            // 'date' (ISO) alongside the display 'month' label — the frontend's
            // "vs previous period" trend badge needs to know which buckets are
            // still in the future (e.g. viewing "This Month" on the 8th, the
            // Aug 16/24 buckets haven't happened yet). Comparing against a
            // not-yet-elapsed bucket always reads as revenue "dropping to
            // zero," which is just time passing, not a real decline.
            $revenueData[] = ['month' => $bucketStart->format('M j'), 'date' => $bucketStart->toDateString(), 'revenue' => 0];
        }
        
        foreach($jobsThisMonth as $job) {
            $elapsed = abs($rangeStart->diffInSeconds($job->created_at));
            $bucket = (int) floor(($elapsed / $rangeSeconds) * 4);
            $bucket = max(0, min(3, $bucket));
            // Same discount-vs-collected-cash fix as total_revenue/branchComparison
            // above — applyDiscount reduces balance directly, not total_amount,
            // so this trend chart was counting discounts as revenue too.
            $revenue = floatval($job->total_amount) - floatval($job->balance) - floatval($job->discount_amount ?? 0);
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

        // Overdue: active jobs past due_date. Excludes on_hold/rejected alongside
        // completed/cancelled — a job the owner intentionally paused, or one that
        // never started because it was declined, isn't "overdue," it's a
        // different state entirely.
        $overdueJobs = $branchJobs()
            ->whereNotIn('status', ['completed', 'cancelled', 'on_hold', 'rejected'])
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

        // Average order value (from completed jobs) — net of any discount,
        // same reasoning as total_revenue above; avg() can't express
        // "average of (total_amount - discount_amount)" directly, so this
        // sums both and divides rather than averaging the raw column.
        $avgOrderValue = $completedJobs > 0
            ? round(
                ($branchJobs()->where('status', 'completed')->sum('total_amount')
                    - $branchJobs()->where('status', 'completed')->sum('discount_amount'))
                / $completedJobs,
                2
            )
            : 0;

        // Average actual turnaround (days from creation to completion), for
        // jobs completed within the selected range — a real operational
        // signal ("are we actually hitting our own promised delivery
        // windows") that nothing on Reports tracked before. No dedicated
        // completed_at column exists on job_orders (only staffStages has
        // per-stage completion timestamps, a different thing), so this uses
        // updated_at as the completion marker — an approximation, since a
        // completed job could theoretically be touched again afterward, but
        // reasonable given most completed jobs aren't edited further.
        // Computed in PHP rather than a raw DATEDIFF() — that's MySQL-only
        // syntax (Postgres has no DATEDIFF at all, SQLite doesn't either),
        // and this project has a real Postgres migration planned. Same
        // portability class as the LIKE case-sensitivity fix elsewhere in
        // this file — confirmed live: this exact query 500'd on SQLite
        // before switching to a plain Carbon diff.
        $completedTurnarounds = (clone $jobsQuery)->where('status', 'completed')->get(['created_at', 'updated_at']);
        $avgTurnaroundDays = $completedTurnarounds->isNotEmpty()
            ? round($completedTurnarounds->avg(fn ($job) => $job->created_at->diffInDays($job->updated_at)), 1)
            : null;

        // Outstanding balances ledger — who owes how much, not just the aggregate
        // total, so the owner can actually chase specific unpaid accounts.
        $outstandingBalances = $branchJobs()
            ->where('balance', '>', 0)
            ->whereNotIn('status', ['cancelled'])
            ->with('customer:id,name,phone')
            ->orderByDesc('balance')
            ->take(20)
            ->get(['id', 'order_number', 'customer_id', 'total_amount', 'balance', 'discount_amount', 'due_date', 'status'])
            ->map(fn ($job) => [
                'id' => $job->id,
                'order_number' => $job->order_number,
                'customer' => $job->customer ? ['id' => $job->customer->id, 'name' => $job->customer->name, 'phone' => $job->customer->phone] : null,
                'total_amount' => (float) $job->total_amount,
                'balance' => (float) $job->balance,
                // applyDiscount reduces balance directly, not total_amount —
                // without this, the Payments list's "Paid: ₱X" figure
                // (total_amount - balance) silently counted a discount as
                // if it were cash the customer actually handed over.
                'discount_amount' => (float) ($job->discount_amount ?? 0),
                'due_date' => $job->due_date,
                'status' => $job->status,
            ]);

        // Completed jobs still owing a balance — "the garment's already been
        // handed over, the customer just hasn't fully paid yet." The Home
        // dashboard's Balance Collection alert used to derive this itself
        // from its own capped (per_page=200) jobs fetch, which silently
        // missed older completed-unpaid jobs on any shop past ~200 total
        // historical job orders — same undercounting shape as the
        // notification badge bug. total_count is unbounded (a real COUNT
        // query, not capped by the list below) so the alert's own count
        // never falls out of sync with what take(20) can actually list.
        $completedUnpaidQuery = $branchJobs()
            ->where('status', 'completed')
            ->where('payment_status', '!=', 'paid')
            ->where('balance', '>', 0);
        $completedUnpaidJobsCount = (clone $completedUnpaidQuery)->count();
        $completedUnpaidJobs = (clone $completedUnpaidQuery)
            ->with('customer:id,name,phone')
            ->orderByDesc('balance')
            ->take(20)
            ->get(['id', 'order_number', 'customer_id', 'total_amount', 'balance', 'discount_amount', 'due_date', 'status', 'payment_status'])
            ->map(fn ($job) => [
                'id' => $job->id,
                'order_number' => $job->order_number,
                'customer' => $job->customer ? ['id' => $job->customer->id, 'name' => $job->customer->name, 'phone' => $job->customer->phone] : null,
                'total_amount' => (float) $job->total_amount,
                'balance' => (float) $job->balance,
                'discount_amount' => (float) ($job->discount_amount ?? 0),
                'due_date' => $job->due_date,
                'status' => $job->status,
                'payment_status' => $job->payment_status,
            ]);

        // Active jobs with zero downpayment collected — same undercounting
        // shape as completed_unpaid_jobs above, same fix: a real, unbounded
        // count instead of the Home dashboard re-deriving this from its own
        // capped (per_page=200) jobs fetch. "Active" mirrors the frontend's
        // own exclusion set (dashboard/page.tsx's activeStatuses) — anything
        // that isn't completed/cancelled/rejected/on_hold.
        $pendingDpQuery = $branchJobs()
            ->whereNotIn('status', ['completed', 'cancelled', 'rejected', 'on_hold'])
            ->where('payment_status', 'unpaid')
            ->where('total_amount', '>', 0);
        $pendingDpJobsCount = (clone $pendingDpQuery)->count();
        $pendingDpJobsList = (clone $pendingDpQuery)
            ->with('customer:id,name,phone')
            ->latest()
            ->take(20)
            ->get(['id', 'order_number', 'customer_id', 'total_amount', 'balance', 'discount_amount', 'due_date', 'status', 'payment_status'])
            ->map(fn ($job) => [
                'id' => $job->id,
                'order_number' => $job->order_number,
                'customer' => $job->customer ? ['id' => $job->customer->id, 'name' => $job->customer->name, 'phone' => $job->customer->phone] : null,
                'total_amount' => (float) $job->total_amount,
                'balance' => (float) $job->balance,
                'discount_amount' => (float) ($job->discount_amount ?? 0),
                'due_date' => $job->due_date,
                'status' => $job->status,
                'payment_status' => $job->payment_status,
            ]);

        // Due Today / Due This Week — same undercounting shape as the other
        // Home-dashboard alerts above, and arguably the highest-stakes one
        // to get right: a long-lead-time order (e.g. a bridal gown booked
        // months ahead of the wedding date) can easily have been *created*
        // long before the capped (per_page=200) allJobs fetch's recency
        // window, even though its due_date is genuinely today or this week.
        $dueJobsActiveStatuses = ['pending', 'design', 'pattern_making', 'mass_cutting_printing', 'cutting', 'sewing', 'ready_for_fitting', 'final_adjustments', 'qc_ironing', 'ready_for_pickup'];
        $dueTodayQuery = $branchJobs()
            ->whereIn('status', $dueJobsActiveStatuses)
            ->whereDate('due_date', $today);
        $dueTodayCount = (clone $dueTodayQuery)->count();
        $dueThisWeekQuery = $branchJobs()
            ->whereIn('status', $dueJobsActiveStatuses)
            ->whereDate('due_date', '>', $today)
            ->whereDate('due_date', '<=', now()->addDays(7)->toDateString());
        $dueThisWeekCount = (clone $dueThisWeekQuery)->count();
        $dueJobFields = ['id', 'order_number', 'customer_id', 'total_amount', 'balance', 'discount_amount', 'due_date', 'status', 'payment_status'];
        $mapDueJob = fn ($job) => [
            'id' => $job->id,
            'order_number' => $job->order_number,
            'customer' => $job->customer ? ['id' => $job->customer->id, 'name' => $job->customer->name, 'phone' => $job->customer->phone] : null,
            'total_amount' => (float) $job->total_amount,
            'balance' => (float) $job->balance,
            'discount_amount' => (float) ($job->discount_amount ?? 0),
            'due_date' => $job->due_date,
            'status' => $job->status,
            'payment_status' => $job->payment_status,
        ];
        $dueTodayJobs = (clone $dueTodayQuery)->with('customer:id,name,phone')->take(20)->get($dueJobFields)->map($mapDueJob);
        $dueThisWeekJobs = (clone $dueThisWeekQuery)->with('customer:id,name,phone')->orderBy('due_date')->take(20)->get($dueJobFields)->map($mapDueJob);

        // Unclaimed pickups — garments that reached ready_for_pickup and have
        // sat there 14+ days. Real tailoring shops lose rack space and end up
        // forfeiting deposits on items customers simply never come back for
        // (cancellation_reason=forfeited_deposit_abandoned already tracks that
        // outcome after the fact); this surfaces the warning *before* the
        // owner gives up on it, so they can actually follow up first.
        $unclaimedPickups = $branchJobs()
            ->where('status', 'ready_for_pickup')
            ->whereNotNull('ready_for_pickup_at')
            ->where('ready_for_pickup_at', '<=', now()->subDays(14))
            ->with('customer:id,name,phone')
            ->orderBy('ready_for_pickup_at')
            ->take(20)
            ->get(['id', 'order_number', 'customer_id', 'total_amount', 'balance', 'ready_for_pickup_at'])
            ->map(fn ($job) => [
                'id' => $job->id,
                'order_number' => $job->order_number,
                'customer' => $job->customer ? ['id' => $job->customer->id, 'name' => $job->customer->name, 'phone' => $job->customer->phone] : null,
                'total_amount' => (float) $job->total_amount,
                'balance' => (float) $job->balance,
                'ready_for_pickup_at' => $job->ready_for_pickup_at,
                // Carbon 3's diffInDays defaults to a signed result (not
                // absolute like Carbon 2) — a past timestamp came back
                // negative here (verified live: -21 instead of 21) until
                // wrapped in abs().
                'days_waiting' => abs((int) now()->diffInDays($job->ready_for_pickup_at)),
            ]);

        // Jobs on hold 7+ days — on_hold is correctly excluded from the
        // overdue_jobs KPI (the owner paused it deliberately, it isn't
        // "late"), but that also meant a held job had zero aging visibility
        // anywhere at all. Shorter threshold than unclaimed pickups (7 vs
        // 14 days) — a paused job is more likely to be a genuinely
        // forgotten one than a customer just running late to collect.
        $jobsOnHold = $branchJobs()
            ->where('status', 'on_hold')
            ->whereNotNull('held_at')
            ->where('held_at', '<=', now()->subDays(7))
            ->with('customer:id,name,phone')
            ->orderBy('held_at')
            ->take(20)
            ->get(['id', 'order_number', 'customer_id', 'hold_reason', 'held_at'])
            ->map(fn ($job) => [
                'id' => $job->id,
                'order_number' => $job->order_number,
                'customer' => $job->customer ? ['id' => $job->customer->id, 'name' => $job->customer->name, 'phone' => $job->customer->phone] : null,
                'hold_reason' => $job->hold_reason,
                'held_at' => $job->held_at,
                'days_held' => abs((int) now()->diffInDays($job->held_at)),
            ]);

        // Rejected-payments and forfeited-deposit loss figures — branch-scoped
        // like every other KPI on this endpoint, derived at read time rather
        // than stored, so they can never drift out of sync with the
        // underlying Payment/cancellation_reason data.
        $rejectedPaymentsQuery = \App\Models\Payment::whereNotNull('rejected_at')
            ->whereHas('jobOrder', function ($q) use ($shop, $branchId) {
                $q->where('shop_id', $shop->id);
                if ($branchId) {
                    $q->where('shop_branch_id', $branchId);
                }
            });
        $rejectedPaymentsCount  = (clone $rejectedPaymentsQuery)->count();
        $rejectedPaymentsAmount = (float) (clone $rejectedPaymentsQuery)->sum('amount');

        $forfeitedJobIds = $branchJobs()
            ->where('status', 'cancelled')
            ->where('cancellation_reason', 'forfeited_deposit_abandoned')
            ->pluck('id');
        $forfeitedDepositCount  = $forfeitedJobIds->count();
        $forfeitedDepositAmount = (float) \App\Models\Payment::whereIn('job_order_id', $forfeitedJobIds)
            ->whereNull('rejected_at')
            ->sum('amount');

        // Customer Satisfaction — shop-level reviews only, not branch-scoped
        // (ShopReview has no shop_branch_id) and not date-range-scoped (a
        // rating average is a standing reputation figure, not a period
        // metric). Reports previously had zero visibility into ratings at
        // all — an owner had to go count them manually on the Reviews page.
        $reviewStats = $shop->reviews()->selectRaw('AVG(rating) as avg_rating, COUNT(*) as total')->first();

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
                'booking_conversion_rate'    => $bookingConversionRate,
                'total_appointments'         => $shop->appointments()->count(),
                'total_services'             => $shop->services()->count(),
                'total_collections'          => \App\Models\CatalogOrder::where('shop_id', $shop->id)->count(),
                'total_branches'             => \App\Models\ShopBranch::where('shop_id', $shop->id)->count(),
                'total_staff'                => $totalStaff,
                'total_customers'            => $totalCustomers,
                'revenue_data'               => $revenueData,
                'jobs_by_status'             => $jobsByStatus,
                'garment_breakdown'          => $garmentBreakdown,
                'recent_jobs'                => $recentJobs,
                // ── New KPIs ──────────────────────────────────────────────────
                'overdue_jobs'               => $overdueJobs,
                'pending_deposit_jobs'       => $pendingDepositJobs,
                'ready_for_pickup_jobs'      => $readyForPickupJobs,
                'rush_jobs_active'           => $rushJobsActive,
                'today_revenue'              => $todayRevenue,
                'completion_rate'            => $completionRate,
                'avg_order_value'            => $avgOrderValue,
                'avg_turnaround_days'        => $avgTurnaroundDays,
                'today_appointments'         => $todayAppointments,
                'outstanding_balances'       => $outstandingBalances,
                'completed_unpaid_jobs'       => $completedUnpaidJobs,
                'completed_unpaid_jobs_count' => $completedUnpaidJobsCount,
                'pending_dp_jobs_list'        => $pendingDpJobsList,
                'pending_dp_jobs_list_count'  => $pendingDpJobsCount,
                'due_today_jobs'              => $dueTodayJobs,
                'due_today_jobs_count'        => $dueTodayCount,
                'due_this_week_jobs'          => $dueThisWeekJobs,
                'due_this_week_jobs_count'    => $dueThisWeekCount,
                'unclaimed_pickups'          => $unclaimedPickups,
                'jobs_on_hold'                => $jobsOnHold,
                'rejected_payments_count'    => $rejectedPaymentsCount,
                'rejected_payments_amount'   => $rejectedPaymentsAmount,
                'forfeited_deposit_count'    => $forfeitedDepositCount,
                'forfeited_deposit_amount'  => $forfeitedDepositAmount,
                'avg_rating'                 => $reviewStats->avg_rating ? round((float) $reviewStats->avg_rating, 1) : null,
                'total_reviews'              => (int) ($reviewStats->total ?? 0),
            ]
        ]);
    }
}
