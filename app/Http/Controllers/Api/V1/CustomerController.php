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
        
        // Shop-specific notes live on the shop_customers pivot, not the User
        // row — fetched once here (keyed by user id) rather than per-customer,
        // same N+1-avoidance reasoning as the jobOrders/appointments eager loads.
        $notesByCustomerId = $shop->customers()->pluck('shop_customers.notes', 'users.id');

        $customers = User::whereIn('id', $customerIds)
            ->with([
                'jobOrders' => function ($query) use ($shop) {
                    $query->where('shop_id', $shop->id);
                },
                // Eager-loaded once for all customers (already ordered so the
                // first element is the most recent) instead of running a
                // fresh query per customer inside the map() below — avoids an
                // N+1 that scaled with the shop's total customer count.
                'appointments' => function ($query) use ($shop) {
                    $query->where('shop_id', $shop->id)->orderBy('scheduled_at', 'desc');
                },
            ])
            ->get()
            ->map(function ($user) use ($notesByCustomerId) {
                $user->total_spend = $user->jobOrders->sum('total_amount');
                $user->last_appointment = $user->appointments->first();
                // Repeat-customer context for the shop owner's manual discount
                // decision ("this is their 5th order") — not just a display stat.
                $user->active_jobs = $user->jobOrders->whereNotIn('status', ['completed', 'cancelled'])->count();
                $user->completed_jobs = $user->jobOrders->where('status', 'completed')->count();
                $user->shop_notes = $notesByCustomerId[$user->id] ?? null;
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
            'suki_tag' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:2000',
        ]);

        $email = $validated['email'] ?? null;
        if (!$email) {
            $email = 'walkin_' . time() . '_' . \Illuminate\Support\Str::random(4) . self::SUTURA_DOMAIN;
        }

        $user = User::where('email', $email)->first();
        if ($user) {
            // Only overwrite the profile fields when the matched account is an
            // actual customer (or a bare placeholder with no role yet) — email
            // is globally unique, so a match could just as easily be another
            // shop's owner/staff/admin account, and this endpoint must never
            // let one shop's walk-in form silently rewrite a stranger's identity.
            $isBusinessAccount = $user->hasRole('shop_owner') || $user->hasRole('staff')
                || $user->hasRole('branch_manager') || $user->hasRole('admin');

            if (!$isBusinessAccount) {
                $user->update([
                    'name' => $validated['name'],
                    'phone' => $validated['phone'] ?? $user->phone,
                    'suki_tag' => $validated['suki_tag'] ?? $user->suki_tag,
                ]);
            }
        } else {
            $user = User::create([
                'email' => $email,
                'name' => $validated['name'],
                'phone' => $validated['phone'] ?? null,
                'suki_tag' => $validated['suki_tag'] ?? null,
                'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(12)),
            ]);
        }

        // Attach to shop if not already attached — syncWithoutDetaching handles
        // both "attach for the first time" and "already attached, just update
        // the pivot notes" in one call, without touching any other customer's
        // pivot row the way a plain sync() would.
        $shop->customers()->syncWithoutDetaching([
            $user->id => ['notes' => $validated['notes'] ?? null],
        ]);

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
            'suki_tag' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:2000',
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
            'suki_tag' => array_key_exists('suki_tag', $validated) ? $validated['suki_tag'] : $customer->suki_tag,
        ]);

        // Shop-specific — lives on the pivot, not the User row (see
        // Shop::customers()). Preserves the existing note when the request
        // doesn't include the field at all, same "omit = keep as-is" rule
        // already used above for suki_tag.
        if (array_key_exists('notes', $validated)) {
            $shop->customers()->syncWithoutDetaching([
                $customer->id => ['notes' => $validated['notes']],
            ]);
        }

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
