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
    public function index(Shop $shop): JsonResponse
    {
        $staff = $shop->staff()->with('user:id,name,email,phone,last_seen_at')->get();
        
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
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
        ]);

        $role = Role::where('name', 'staff')->first();
        if ($role) {
            $user->roles()->attach($role->id);
        }

        $staff = $shop->staff()->create([
            'user_id' => $user->id,
            'role' => $request->role,
            'specialization' => $request->specialization,
            'hired_at' => $request->hired_at,
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
        $staff->update($request->only(['role', 'specialization', 'hired_at', 'is_active']));

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
