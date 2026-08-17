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
    private const MSG_NOT_FOUND = 'Customer not found in this shop';

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
                $hasWalkInJobs = $user->jobOrders->where('intake_channel', 'walk_in')->isNotEmpty();
                $hasWalkInAppts = $user->appointments->where('intake_channel', 'walk_in')->isNotEmpty();
                $isSyntheticWalkIn = \Illuminate\Support\Str::startsWith($user->email, 'walkin_');

                $user->total_spend = $user->jobOrders->sum(fn ($j) => (float) $j->total_amount - (float) $j->balance - (float) $j->discount_amount);
                $user->last_appointment = $user->appointments->first();
                $user->active_jobs = $user->jobOrders->whereNotIn('status', ['completed', 'cancelled'])->count();
                $user->completed_jobs = $user->jobOrders->where('status', 'completed')->count();
                $user->shop_notes = $notesByCustomerId[$user->id] ?? null;
                $user->is_walk_in = $isSyntheticWalkIn || $hasWalkInJobs || $hasWalkInAppts || $user->suki_tag === 'walk_in_retail';
                $user->intake_channel = $user->is_walk_in ? 'walk_in' : 'online';
                return $user;
            });

        return response()->json([
            'success' => true,
            'data' => collect($customers)->sortByDesc('total_spend')->values()
        ]);
    }

    public function show(Request $request, Shop $shop, User $customer): JsonResponse
    {
        if (!$request->user()->hasRole('shop_owner') && !$request->user()->hasRole('branch_manager') && !$request->user()->hasRole('staff')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Verify customer belongs to shop
        $isCustomerOfShop = $shop->customers()->where('user_id', $customer->id)->exists()
            || $shop->jobOrders()->where('customer_id', $customer->id)->exists()
            || $shop->appointments()->where('customer_id', $customer->id)->exists();

        if (!$isCustomerOfShop) {
            return response()->json(['message' => self::MSG_NOT_FOUND], 404);
        }

        $shopNotes = $shop->customers()->where('user_id', $customer->id)->value('shop_customers.notes');

        $measurements = $shop->measurements()
            ->where('customer_id', $customer->id)
            ->with('customer:id,name,email')
            ->latest()
            ->get();

        $jobs = $shop->jobOrders()
            ->where('customer_id', $customer->id)
            ->with(['service', 'assignedStaff:id,name', 'staffStages', 'payments.recordedBy:id,name', 'branch:id,name'])
            ->latest()
            ->get();

        $appointments = $shop->appointments()
            ->where('customer_id', $customer->id)
            ->with(['service:id,name,base_price', 'branch:id,name', 'assignedStaff:id,name', 'jobOrder:id,order_number'])
            ->orderBy('scheduled_at', 'desc')
            ->get();

        $hasWalkInJobs = $jobs->where('intake_channel', 'walk_in')->isNotEmpty();
        $hasWalkInAppts = $appointments->where('intake_channel', 'walk_in')->isNotEmpty();
        $isSyntheticWalkIn = \Illuminate\Support\Str::startsWith($customer->email, 'walkin_');

        $customerData = $customer->toArray();
        $customerData['shop_notes'] = $shopNotes;
        $customerData['is_walk_in'] = $isSyntheticWalkIn || $hasWalkInJobs || $hasWalkInAppts || $customer->suki_tag === 'walk_in_retail';
        $customerData['intake_channel'] = $customerData['is_walk_in'] ? 'walk_in' : 'online';
        $customerData['total_spend'] = (float) $jobs->sum(fn ($j) => (float) $j->total_amount - (float) $j->balance - (float) $j->discount_amount);
        $customerData['active_jobs'] = $jobs->whereNotIn('status', ['completed', 'cancelled'])->count();
        $customerData['completed_jobs'] = $jobs->where('status', 'completed')->count();
        $customerData['no_show_count'] = $appointments->where('status', 'no_show')->count();
        $customerData['last_appointment'] = $appointments->first();

        return response()->json([
            'success' => true,
            'data' => [
                'customer'     => $customerData,
                'measurements' => $measurements,
                'jobs'         => $jobs,
                'appointments' => $appointments,
            ]
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

        // Walk-in customers routinely have no email at all — the shop's
        // whole relationship with them is by name and phone number, exactly
        // per the interview research this system is built on. Without an
        // email to match on, every walk-in submission used to mint a brand
        // new synthetic walkin_ account even when the same person (same
        // phone) had already walked in before, fragmenting their job
        // history across multiple "customers" and silently breaking the
        // repeat-customer discount signal (JobOrderController checks job
        // count per customer_id). Scoped to this shop specifically — a
        // phone match against a stranger at a different shop must never
        // merge unrelated customer bases.
        if (!$email) {
            // Same "who counts as this shop's customer" definition index()
            // already uses (CRM pivot ∪ job orders ∪ appointments) — a
            // phone match has to check all three, not just the CRM pivot,
            // or a walk-in whose only prior contact was a job order (no CRM
            // entry) still wouldn't be found.
            $existingCustomerIds = collect()
                ->merge($shop->customers()->pluck('users.id'))
                ->merge($shop->jobOrders()->pluck('customer_id'))
                ->merge($shop->appointments()->pluck('customer_id'))
                ->filter()
                ->unique();
            $existingByPhone = User::whereIn('id', $existingCustomerIds)
                ->where('phone', $validated['phone'])
                ->first();
            if ($existingByPhone) {
                $email = $existingByPhone->email;
            }
        }

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

            // The original guard here only stopped the profile from being
            // overwritten — it still fell through to attach this business
            // account to shop_customers below, so a shop owner (or their own
            // staff) typing their own email into the walk-in form ended up
            // listed as a customer of their own shop. Reject the whole
            // operation instead of partially proceeding.
            if ($isBusinessAccount) {
                return response()->json([
                    'success' => false,
                    'message' => 'This email belongs to a staff or owner account and cannot be added as a customer.',
                    'errors'  => ['email' => ['This email belongs to a staff or owner account and cannot be added as a customer.']],
                ], 422);
            }

            $user->update([
                'name' => $validated['name'],
                'phone' => $validated['phone'] ?? $user->phone,
                'suki_tag' => $validated['suki_tag'] ?? $user->suki_tag,
            ]);
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
            return response()->json(['message' => self::MSG_NOT_FOUND], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'required|string|max:20',
            'suki_tag' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:2000',
        ]);

        // An empty email on the edit form means "leave it as-is" — the form
        // already pre-fills this field with the customer's real email when
        // they have one (see CustomerFormModal), so a blank submission here
        // must never manufacture a fresh walkin_ placeholder and silently
        // overwrite a real, working login email. Only generate one if the
        // customer genuinely has no email on file at all.
        $email = $validated['email'] ?? null;
        if (!$email) {
            $email = $customer->email
                ?: ('walkin_' . time() . '_' . \Illuminate\Support\Str::random(4) . self::SUTURA_DOMAIN);
        }

        // Setting this record's email straight from input used to hit the
        // users.email unique constraint at the DB level with no warning if
        // it collided with a different user (business account or otherwise)
        // — a generic 500 instead of a message explaining why.
        if ($email !== $customer->email && User::where('email', $email)->where('id', '!=', $customer->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This email is already in use by another account.',
                'errors'  => ['email' => ['This email is already in use by another account.']],
            ], 422);
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

        $customer->shop_notes = $shop->customers()->where('user_id', $customer->id)->value('shop_customers.notes');

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

        // Verify customer belongs to shop — mirrors update()'s check exactly.
        // index() lists a customer if they appear EITHER in the shop_customers
        // pivot OR in this shop's job_orders, but this check only looked at
        // the pivot. A customer who arrived via public booking/self-registration/
        // job creation (Isabelle, Michael, Carlo in this shop's real data) never
        // gets a pivot row at all, so clicking Delete on a customer clearly
        // visible in the list 404'd with "Customer not found in this shop."
        if (!$shop->customers()->where('user_id', $customer->id)->exists() && !$shop->jobOrders()->where('customer_id', $customer->id)->exists()) {
            return response()->json(['message' => self::MSG_NOT_FOUND], 404);
        }

        // Detach instead of deleting the user, since user might exist in other
        // shops. A customer with no pivot row (only reachable via job_orders)
        // has nothing to detach — that's expected, not an error: their real
        // order history keeps them in the list regardless, the same way a
        // completed job order can't be erased from view either.
        $shop->customers()->detach($customer->id);

        return response()->json([
            'success' => true,
            'message' => 'Customer removed successfully.'
        ]);
    }
}
