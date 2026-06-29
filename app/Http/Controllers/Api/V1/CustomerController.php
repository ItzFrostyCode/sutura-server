<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CustomerController extends Controller
{
    private const SUTURA_DOMAIN = '@sutura.com';

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
                $user->total_spend = $user->jobOrders->sum('total_amount');
                $user->last_appointment = $user->appointments()
                    ->orderBy('scheduled_at', 'desc')
                    ->first();
                return $user;
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
            'email' => 'nullable|email',
            'phone' => 'required|string|max:20',
        ]);

        $email = $validated['email'] ?? null;
        if (!$email) {
            $email = 'walkin_' . time() . '_' . \Illuminate\Support\Str::random(4) . self::SUTURA_DOMAIN;
        }

        $user = User::where('email', $email)->first();
        if ($user) {
            $user->update([
                'name' => $validated['name'],
                'phone' => $validated['phone'] ?? $user->phone,
            ]);
        } else {
            $user = User::create([
                'email' => $email,
                'name' => $validated['name'],
                'phone' => $validated['phone'] ?? null,
                'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(12)),
            ]);
        }

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
            'email' => 'nullable|email',
            'phone' => 'required|string|max:20',
        ]);

        $email = $validated['email'] ?? null;
        if (!$email) {
            if ($customer->email && str_starts_with($customer->email, 'walkin_') && str_ends_with($customer->email, self::SUTURA_DOMAIN)) {
                $email = $customer->email;
            } else {
                $email = 'walkin_' . time() . '_' . \Illuminate\Support\Str::random(4) . self::SUTURA_DOMAIN;
            }
        }

        $customer->update([
            'name' => $validated['name'],
            'email' => $email,
            'phone' => $validated['phone'] ?? null,
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
