<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CustomerController extends Controller
{
    public function index(Request $request, Shop $shop): JsonResponse
    {
        if (!$request->user()->hasRole('shop_owner') && !$request->user()->hasRole('branch_manager') && !$request->user()->hasRole('staff')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Get customers from pivot AND job orders
        $jobCustomerIds = $shop->jobOrders()->pluck('customer_id')->toArray();
        $pivotCustomerIds = $shop->customers()->pluck('users.id')->toArray();
        
        $customerIds = collect(array_merge($jobCustomerIds, $pivotCustomerIds))->unique();
        
        $customers = User::whereIn('id', $customerIds)
            ->with(['jobOrders' => function ($query) use ($shop) {
                $query->where('shop_id', $shop->id);
            }])
            ->get()
            ->map(function ($user) {
                $totalSpend = $user->jobOrders->sum('total_amount');
                $activeJobs = $user->jobOrders->whereNotIn('status', ['completed', 'cancelled'])->count();
                $completedJobs = $user->jobOrders->where('status', 'completed')->count();
                
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'profile_picture' => $user->profile_picture,
                    'total_spend' => $totalSpend,
                    'active_jobs' => $activeJobs,
                    'completed_jobs' => $completedJobs,
                    'created_at' => $user->created_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => collect($customers)->sortByDesc('total_spend')->values()
        ]);
    }

    public function store(Request $request, Shop $shop): JsonResponse
    {
        if (!$request->user()->hasRole('shop_owner') && !$request->user()->hasRole('branch_manager') && !$request->user()->hasRole('staff')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'phone' => 'nullable|string|max:20',
        ]);

        $user = User::firstOrCreate(
            ['email' => $validated['email']],
            [
                'name' => $validated['name'],
                'phone' => $validated['phone'],
                'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(12)),
            ]
        );

        // Attach to shop if not already attached
        if (!$shop->customers()->where('user_id', $user->id)->exists()) {
            $shop->customers()->attach($user->id);
        }

        return response()->json([
            'success' => true,
            'data' => $user,
            'message' => 'Customer created successfully.'
        ], 201);
    }

    public function update(Request $request, Shop $shop, User $customer): JsonResponse
    {
        if (!$request->user()->hasRole('shop_owner') && !$request->user()->hasRole('branch_manager') && !$request->user()->hasRole('staff')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Verify customer belongs to shop
        if (!$shop->customers()->where('user_id', $customer->id)->exists() && !$shop->jobOrders()->where('customer_id', $customer->id)->exists()) {
            return response()->json(['message' => 'Customer not found in this shop'], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'phone' => 'nullable|string|max:20',
        ]);

        $customer->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $customer,
            'message' => 'Customer updated successfully.'
        ]);
    }

    public function destroy(Request $request, Shop $shop, User $customer): JsonResponse
    {
        if (!$request->user()->hasRole('shop_owner') && !$request->user()->hasRole('branch_manager') && !$request->user()->hasRole('staff')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Verify customer belongs to shop
        if (!$shop->customers()->where('user_id', $customer->id)->exists()) {
            return response()->json(['message' => 'Customer not found in this shop'], 404);
        }

        // Detach instead of deleting the user, since user might exist in other shops
        $shop->customers()->detach($customer->id);

        return response()->json([
            'success' => true,
            'message' => 'Customer removed successfully.'
        ]);
    }
}
