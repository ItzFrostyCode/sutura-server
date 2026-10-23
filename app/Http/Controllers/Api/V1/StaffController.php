<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreStaffRequest;
use App\Http\Requests\Store\UpdateStaffRequest;
use App\Models\StaffProfile;
use App\Models\Store;
use App\Support\ShopAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StaffController extends Controller
{
    /**
     * A branch manager can create/edit/deactivate staff — that's routine
     * headcount management, not something that should always have to route
     * through the owner — but strictly within their own branch, and never
     * touching the branch-manager tier itself: promoting someone to
     * (or editing/removing an existing) branch manager stays owner-only,
     * same as the store owner never being editable through this controller.
     * Returns a 403 JsonResponse to short-circuit on, or null to proceed.
     */
    private function staffManagerCrudDenied(Request $request, int $targetBranchId, bool $wantsBranchManager, ?StaffProfile $existingTarget = null): ?JsonResponse
    {
        $user = $request->user();
        if ($user->hasRole('store_owner')) {
            return null;
        }

        if ($wantsBranchManager) {
            return response()->json(['success' => false, 'message' => 'Only the shop owner can promote a staff member to branch manager.'], 403);
        }

        if ($existingTarget && $existingTarget->is_branch_manager) {
            return response()->json(['success' => false, 'message' => 'Only the shop owner can edit or remove another branch manager.'], 403);
        }

        $callerBranchId = $user->staffProfile?->store_branch_id ?? null;
        if (! $callerBranchId || (int) $callerBranchId !== $targetBranchId) {
            return response()->json(['success' => false, 'message' => 'You can only manage staff within your own branch.'], 403);
        }

        return null;
    }

    /**
     * A branch manager may not point a submitted branch at anyone else's branch (new staff, or moving a staff member).
     * Only the owner chooses a branch, and this controller never applies a submitted branch or manager flag anyway.
     */
    private function ownBranchOnlyDenied(Request $request, int $ownBranchId): ?JsonResponse
    {
        if ($request->user()->hasRole('store_owner') || ! $request->filled('store_branch_id')) {
            return null;
        }
        if ((int) $request->input('store_branch_id') !== $ownBranchId) {
            return response()->json(['success' => false, 'message' => 'You can only manage staff within your own branch.'], 403);
        }

        return null;
    }

    public function index(Request $request, Store $store): JsonResponse
    {
        // A branch manager or staff account pinned to a branch only ever sees
        // that branch's own roster — matches the same per-action branch check
        // already enforced on job orders (JobOrderController::branchAccessDenied)
        // and its list-scoping (JobOrderController::index). Previously this
        // returned every branch's staff regardless of who was asking. Filtered
        // after the cache read/write below (not baked into the cache key) so
        // store()/update()/destroy()'s single forget() call still invalidates
        // every caller's view correctly.
        $user = $request->user();
        $scopedBranchId = ! $user->hasRole('store_owner') ? ($user->staffProfile?->store_branch_id ?? null) : null;

        $cacheKey = "store_staff_{$store->id}";
        $cached = app()->environment('testing') ? null : Cache::driver('file')->get($cacheKey);
        if (is_array($cached) && isset($cached['data']) && is_array($cached['data'])) {
            $data = $scopedBranchId
                ? array_values(array_filter($cached['data'], fn ($s) => (int) ($s['store_branch_id'] ?? 0) === (int) $scopedBranchId))
                : $cached['data'];

            return response()->json(['success' => true, 'data' => $data]);
        }

        $staff = $store->staff()->with(['user:id,name,email,phone,gender,last_seen_at,profile_picture', 'branch:id,name'])->get();

        // One grouped query for all staff instead of 2 queries per staff
        // member — previously scaled linearly with the store's headcount.
        $userIds = $staff->pluck('user_id');
        // COUNT(DISTINCT job_order_id), not a raw row SUM — job_order_staff
        // has one row per production STAGE, and a staff member is routinely
        // assigned to several stages of the same job order (e.g. design +
        // pattern_making + cutting, all still incomplete). A raw row count
        // over-reports "active jobs" for exactly that staff member: verified
        // live, one staff member with 2 open stage-rows on the same job
        // order showed "2 active jobs" for what was really only 1 actual
        // job. The whole point of this number is "how many distinct jobs is
        // this person on right now," not "how many stage-assignments."
        $counts = DB::table('job_order_staff')
            ->whereIn('user_id', $userIds)
            ->selectRaw('
                user_id,
                COUNT(DISTINCT CASE WHEN completed_at IS NULL THEN job_order_id END) as active_jobs,
                COUNT(DISTINCT CASE WHEN completed_at IS NOT NULL THEN job_order_id END) as completed_jobs
            ')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $staff->transform(function ($s) use ($counts) {
            $row = $counts->get($s->user_id);
            $s->active_jobs = (int) ($row->active_jobs ?? 0);
            $s->completed_jobs = (int) ($row->completed_jobs ?? 0);

            return $s;
        });

        $fullData = $staff->values()->toArray();
        $payload = ['success' => true, 'data' => $fullData];

        if (! app()->environment('testing')) {
            Cache::driver('file')->put($cacheKey, $payload, 30);
        }

        $data = $scopedBranchId
            ? array_values(array_filter($fullData, fn ($s) => (int) ($s['store_branch_id'] ?? 0) === (int) $scopedBranchId))
            : $fullData;

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function show(Request $request, Store $store, StaffProfile $staff): JsonResponse
    {
        if ($staff->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $user = $request->user();
        $scopedBranchId = ! $user->hasRole('store_owner') ? ($user->staffProfile?->store_branch_id ?? null) : null;
        if ($scopedBranchId && $staff->store_branch_id && (int) $scopedBranchId !== (int) $staff->store_branch_id) {
            return response()->json(['success' => false, 'message' => 'This staff member belongs to a different branch.'], 403);
        }

        // Work-history log: every job this staff was assigned to (assigned vs completed).
        $assignments = DB::table('job_order_staff as jos')
            ->join('job_orders as jo', 'jo.id', '=', 'jos.job_order_id')
            ->leftJoin('users as c', 'c.id', '=', 'jo.customer_id')
            ->where('jos.user_id', $staff->user_id)
            ->where('jo.store_id', $store->id)
            ->orderByDesc('jos.assigned_at')
            ->get([
                'jos.job_order_id',
                'jo.order_number',
                'jo.status as job_status',
                'jos.stage',
                'jos.assigned_at',
                'jos.completed_at',
                'c.name as customer_name',
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'staff' => $staff->load(['user:id,name,email,phone,gender,last_seen_at,profile_picture', 'branch:id,name']),
                // total_assigned/total_completed intentionally stay as raw
                // stage-assignment counts — they correspond 1:1 with the
                // per-stage `assignments` log rendered below. 'active' is
                // the one figure meant to answer "how many distinct jobs,"
                // same question the Staff List's Active Jobs column
                // answers — so it needs the same distinct-job-order fix
                // (see StaffController::index), or the two "Active" numbers
                // for the same person would silently disagree.
                'total_assigned' => $assignments->count(),
                'total_completed' => $assignments->whereNotNull('completed_at')->count(),
                'active' => $assignments->whereNull('completed_at')->pluck('job_order_id')->unique()->count(),
                'assignments' => $assignments,
            ],
        ]);
    }

    public function store(StoreStaffRequest $request, Store $store): JsonResponse
    {
        // The platform role is always plain staff here — managers are made from Store Branches (Add Branch / Assign a
        // manager), never through this form, and a submitted role / manager flag is never applied.
        // The branch is decided here too: a branch manager adds staff to their own branch; the owner picks one of the
        // shop's branches (required when the shop has 2+, automatic with 1).
        if ($request->user()->hasRole('store_owner')) {
            $branches = $store->branches()->orderByDesc('is_main')->orderBy('id')->get();
            if ($branches->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'Add a branch first, then add staff to it.'], 422);
            }
            if ($branches->count() > 1 && ! $request->filled('store_branch_id')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pick the branch this staff member works in.',
                    'errors' => ['store_branch_id' => ['Pick the branch this staff member works in.']],
                ], 422);
            }
            $branch = $branches->count() === 1 ? $branches->first() : $branches->firstWhere('id', (int) $request->input('store_branch_id'));
        } else {
            $branch = $store->branches()->find((int) ($request->user()->staffProfile?->store_branch_id ?? 0));
        }
        if (! $branch) {
            return response()->json(['success' => false, 'message' => 'You can only manage staff within your own branch.'], 403);
        }

        // A branch manager can only create plain staff in their own branch: a submitted manager flag or other branch is refused.
        if ($denied = $this->staffManagerCrudDenied($request, (int) $branch->id, $request->boolean('is_branch_manager'))) {
            return $denied;
        }
        if ($denied = $this->ownBranchOnlyDenied($request, (int) $branch->id)) {
            return $denied;
        }

        // The plan's staff limit counts branch managers too.
        if ($message = ShopAccounts::staffLimitMessage($store)) {
            return response()->json(['success' => false, 'message' => $message], 403);
        }

        // Any account with this email (customer, owner, staff, admin) blocks it: hiring never converts an account.
        if (ShopAccounts::emailTaken($request->email)) {
            return ShopAccounts::emailTakenResponse();
        }

        $staff = DB::transaction(fn () => ShopAccounts::create($store, $branch, $request->validated(), false));

        return response()->json([
            'success' => true,
            'data' => $staff->load(['user:id,name,email,phone,gender,last_seen_at,profile_picture', 'branch:id,name']),
        ], 201);
    }

    public function update(UpdateStaffRequest $request, Store $store, StaffProfile $staff): JsonResponse
    {
        if ($staff->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $targetBranchId = (int) $staff->store_branch_id;
        if ($denied = $this->staffManagerCrudDenied($request, $targetBranchId, $request->boolean('is_branch_manager'), $staff)) {
            return $denied;
        }
        if ($denied = $this->ownBranchOnlyDenied($request, $targetBranchId)) {
            return $denied;
        }

        // Update the associated User
        $user = $staff->user;
        if ($user) {
            $userData = $request->only(['name', 'email', 'phone', 'gender']);
            if ($request->filled('password')) {
                $userData['password'] = Hash::make($request->password);
            }
            $user->update($userData);
        }

        // Update the StaffProfile
        $staff->update($request->only(['role', 'additional_roles', 'specialization', 'hired_at', 'is_active', 'bio', 'is_available']));

        Cache::driver('file')->forget("store_staff_{$store->id}");

        return response()->json([
            'success' => true,
            'data' => $staff->load(['user:id,name,email,phone,gender,last_seen_at,profile_picture', 'branch:id,name']),
        ]);
    }

    public function destroy(Request $request, Store $store, StaffProfile $staff): JsonResponse
    {
        if ($staff->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($denied = $this->staffManagerCrudDenied($request, (int) $staff->store_branch_id, false, $staff)) {
            return $denied;
        }

        // Only remove the StaffProfile — the roster entry — and leave the
        // underlying User row alone. Deleting the User here used to cascade
        // into every historical record that references them by user id
        // (JobOrder::assignedStaff, Payment::recordedBy/rejectedBy,
        // AuditLog::user), silently blanking out "who did this" across the
        // store's entire history the moment a staff member was offboarded —
        // a routine action, not a reason to lose past attribution.
        //
        // Logged before delete() (the StaffProfile itself, not the User row,
        // so name/email still need to be captured now) — removing a staff
        // member is exactly the kind of action the owner needs to see in
        // the Audit Log for a branch_manager's own actions, and previously
        // there was no record it happened at all.
        $store->auditLogs()->create([
            'user_id' => $request->user()->id,
            'action' => 'staff_removed',
            'model_type' => StaffProfile::class,
            'model_id' => $staff->id,
            'payload' => [
                'name' => $staff->user?->name,
                'role' => $staff->role,
            ],
            'ip_address' => $request->ip(),
        ]);

        $staff->delete();

        Cache::driver('file')->forget("store_staff_{$store->id}");

        return response()->json([
            'success' => true,
            'message' => 'Staff member removed.',
        ]);
    }
}
