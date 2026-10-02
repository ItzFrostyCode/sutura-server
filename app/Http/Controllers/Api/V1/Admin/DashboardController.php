<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreApplication;
use App\Models\StoreSubscription;
use App\Models\SupportTicket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * System Admin overview + subscription report (sutura2's dashboard and
 * SubscriptionReportView). Every figure is its own uncapped query.
 */
class DashboardController extends Controller
{
    public function overview(): JsonResponse
    {
        $roleCounts = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->selectRaw('roles.name, COUNT(DISTINCT role_user.user_id) as total')
            ->groupBy('roles.name')
            ->pluck('total', 'name');

        return response()->json([
            'success' => true,
            'data' => [
                'users' => [
                    'total' => User::count(),
                    'customers' => (int) ($roleCounts['customer'] ?? 0),
                    'store_owners' => (int) ($roleCounts['store_owner'] ?? 0),
                    'suspended' => User::whereNotNull('suspended_at')->count(),
                ],
                'stores' => Store::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
                'active_subscriptions' => $this->activeSubscriptions()->count(),
                'pending_branches' => \App\Models\StoreBranch::where('verification_status', 'pending')->whereHas('store', fn ($q) => $q->where('status', 'approved'))->count(),
                'open_tickets' => SupportTicket::whereIn('status', ['open', 'in_progress'])->count(),
                'recent_applications' => Store::with('owner:id,name,email,contact_email')
                    ->where('status', 'pending')
                    ->oldest()
                    ->take(5)
                    ->get(['id', 'name', 'slug', 'owner_id', 'city', 'created_at']),
            ],
        ]);
    }

    public function subscriptionReport(): JsonResponse
    {
        $byPlan = $this->activeSubscriptions()
            ->join('subscription_plans', 'subscription_plans.id', '=', 'store_subscriptions.plan_id')
            ->selectRaw('subscription_plans.id, subscription_plans.name, subscription_plans.price_monthly, COUNT(*) as stores')
            ->groupBy('subscription_plans.id', 'subscription_plans.name', 'subscription_plans.price_monthly')
            ->orderBy('subscription_plans.price_monthly')
            ->get()
            ->map(fn ($row) => [
                'plan' => $row->name,
                'stores' => (int) $row->stores,
                // List price × active stores — an estimate, since yearly
                // subscribers actually pay the discounted yearly rate.
                'estimated_mrr' => round((float) $row->price_monthly * (int) $row->stores, 2),
            ]);

        $start = Carbon::now()->startOfMonth()->subMonths(5);
        $started = StoreSubscription::where('starts_at', '>=', $start)->get(['starts_at'])
            ->groupBy(fn ($s) => $s->starts_at->format('Y-m'));
        // Decision timestamps, not updated_at — any profile edit bumps that.
        $approvedByMonth = Store::where('approved_at', '>=', $start)->get(['approved_at'])
            ->groupBy(fn ($s) => $s->approved_at->format('Y-m'));
        $rejectedByMonth = StoreApplication::where('reviewed_at', '>=', $start)
            ->whereHas('store', fn ($q) => $q->where('status', 'rejected'))
            ->get(['reviewed_at'])
            ->groupBy(fn ($a) => $a->reviewed_at->format('Y-m'));

        $months = collect(range(0, 5))->map(function (int $i) use ($start, $started, $approvedByMonth, $rejectedByMonth) {
            $key = $start->copy()->addMonths($i)->format('Y-m');

            return [
                'label' => $start->copy()->addMonths($i)->format('M Y'),
                'new_subscriptions' => $started->get($key, collect())->count(),
                'approved' => $approvedByMonth->get($key, collect())->count(),
                'rejected' => $rejectedByMonth->get($key, collect())->count(),
            ];
        });

        $statusCounts = Store::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $approved = (int) ($statusCounts['approved'] ?? 0);
        $rejected = (int) ($statusCounts['rejected'] ?? 0);

        return response()->json([
            'success' => true,
            'data' => [
                'by_plan' => $byPlan,
                'estimated_mrr' => round($byPlan->sum('estimated_mrr'), 2),
                'months' => $months,
                'approval_rate' => $approved + $rejected > 0 ? round($approved / ($approved + $rejected) * 100, 1) : null,
                'generated_at' => now(),
            ],
        ]);
    }

    private function activeSubscriptions()
    {
        return StoreSubscription::query()
            ->whereIn('store_subscriptions.status', ['active', 'trial'])
            ->where(fn ($q) => $q->whereNull('store_subscriptions.ends_at')->orWhere('store_subscriptions.ends_at', '>', now()));
    }
}
