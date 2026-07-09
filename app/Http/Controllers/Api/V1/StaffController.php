<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\StoreStaffRequest;
use App\Http\Requests\Shop\UpdateStaffRequest;
use App\Models\Shop;
use App\Models\StaffProfile;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class StaffController extends Controller
{
    /**
     * A staff account is either a plain 'staff' or a 'branch_manager' at the
     * platform-role level — never both — so checks elsewhere in the app that
     * do `hasRole('staff') && !hasRole('branch_manager')` stay correct.
     */
    private function syncPlatformRole(User $user, bool $isBranchManager): void
    {
        $targetRole = Role::where('name', $isBranchManager ? 'branch_manager' : 'staff')->first();
        $otherRole = Role::where('name', $isBranchManager ? 'staff' : 'branch_manager')->first();

        if ($otherRole) {
            $user->roles()->detach($otherRole->id);
        }

        if ($targetRole && !$user->roles()->where('roles.id', $targetRole->id)->exists()) {
            $user->roles()->attach($targetRole->id);
        }
    }

    public function index(Shop $shop): JsonResponse
    {
        $staff = $shop->staff()->with(['user:id,name,email,phone,last_seen_at', 'branch:id,name'])->get();
        
        $staff->transform(function($s) {
            $s->active_jobs = \Illuminate\Support\Facades\DB::table('job_order_staff')
                ->where('user_id', $s->user_id)
                ->whereNull('completed_at')
                ->count();
            $s->completed_jobs = \Illuminate\Support\Facades\DB::table('job_order_staff')
                ->where('user_id', $s->user_id)
                ->whereNotNull('completed_at')
                ->count();
            return $s;
        });

        return response()->json([
            'success' => true,
            'data' => $staff
        ]);
    }

    public function show(Shop $shop, StaffProfile $staff): JsonResponse
    {
        if ($staff->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Work-history log: every job this staff was assigned to (assigned vs completed).
        $assignments = \Illuminate\Support\Facades\DB::table('job_order_staff as jos')
            ->join('job_orders as jo', 'jo.id', '=', 'jos.job_order_id')
            ->leftJoin('users as c', 'c.id', '=', 'jo.customer_id')
            ->where('jos.user_id', $staff->user_id)
            ->where('jo.shop_id', $shop->id)
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
                'staff' => $staff->load('user:id,name,email,phone'),
                'total_assigned' => $assignments->count(),
                'total_completed' => $assignments->whereNotNull('completed_at')->count(),
                'active' => $assignments->whereNull('completed_at')->count(),
                'assignments' => $assignments,
            ],
        ]);
    }

    public function store(StoreStaffRequest $request, Shop $shop): JsonResponse
    {
        $existing = User::where('email', $request->email)->first();

        if ($existing && $existing->password_set_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This email already belongs to a registered account.',
                'errors'  => ['email' => ['This email already belongs to a registered account.']],
            ], 422);
        }

        if ($existing) {
            $user = $existing;
            $user->update([
                'name'            => $request->name,
                'password'        => Hash::make($request->password),
                'password_set_at' => now(),
                'phone'           => $request->phone ?? $user->phone,
            ]);
        } else {
            $user = User::create([
                'name'            => $request->name,
                'email'           => $request->email,
                'password'        => Hash::make($request->password),
                'password_set_at' => now(),
                'phone'           => $request->phone,
            ]);
        }

        $isBranchManager = $request->boolean('is_branch_manager');
        $this->syncPlatformRole($user, $isBranchManager);

        $staff = $shop->staff()->create([
            'user_id' => $user->id,
            'role' => $request->role,
            'additional_roles' => $request->additional_roles,
            'specialization' => $request->specialization,
            'hired_at' => $request->hired_at,
            'shop_branch_id' => $request->shop_branch_id,
            'is_branch_manager' => $isBranchManager,
        ]);

        return response()->json([
            'success' => true,
            'data' => $staff->load('user:id,name,email')
        ], 201);
    }

    public function update(UpdateStaffRequest $request, Shop $shop, StaffProfile $staff): JsonResponse
    {
        if ($staff->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Update the associated User
        $user = $staff->user;
        if ($user) {
            $userData = $request->only(['name', 'email', 'phone']);
            if ($request->filled('password')) {
                $userData['password'] = Hash::make($request->password);
            }
            $user->update($userData);
        }

        // Update the StaffProfile
        $staff->update($request->only(['role', 'additional_roles', 'specialization', 'hired_at', 'is_active', 'shop_branch_id', 'is_branch_manager']));

        if ($user && $request->has('is_branch_manager')) {
            $this->syncPlatformRole($user, $staff->is_branch_manager);
        }

        return response()->json([
            'success' => true,
            'data' => $staff->load('user:id,name,email,phone')
        ]);
    }

    public function destroy(Shop $shop, StaffProfile $staff): JsonResponse
    {
        if ($staff->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Delete the associated User model since they were created for this staff account
        if ($staff->user) {
            $staff->user()->delete();
        }

        $staff->delete();

        return response()->json([
            'success' => true,
            'message' => 'Staff member removed.'
        ]);
    }
}
