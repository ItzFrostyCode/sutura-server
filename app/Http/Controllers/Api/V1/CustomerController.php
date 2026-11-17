<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\Store;
use App\Support\BranchScope;
use App\Models\User;
use App\Support\BranchPin;
use App\Support\JobMeasurementSync;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    private const SUTURA_DOMAIN = '@sutura.com';

    private const MSG_NOT_FOUND = 'Customer not found in this store';

    public function index(Request $request, Store $store): JsonResponse
    {
        if (! $request->user()->hasRole('store_owner') && ! $request->user()->hasRole('branch_manager') && ! $request->user()->hasRole('staff')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // The Customers list: someone becomes the shop's customer when an order is made for them, or when the shop adds
        // them on the Customers page. A booking or a consultation alone does not put anyone on it.
        // ?all=1 is for the pickers (schedule a visit, start a consultation, new order): they also need the people who
        // have only booked or visited so far, so the shop can choose them.
        // A branch (picked by the owner with ?branch_id=, or the one a manager/staff account is pinned to) shows only
        // customers with an order (or, for ?all=1, a visit) there. "Add Customer" entries carry no branch: they show in
        // the consolidated view and on the Main Shop only.
        $branchId = BranchScope::resolve($request, $store);
        $inBranch = fn ($q) => $branchId ? $q->where('store_branch_id', $branchId) : $q;
        $mainBranchId = (int) $store->branches()->where('is_main', true)->value('id');
        $onHomeBranch = ! $branchId || $branchId === $mainBranchId;

        $jobCustomerIds = $inBranch($store->jobOrders())->pluck('customer_id')->toArray();
        if ($request->boolean('all')) {
            $others = array_merge(
                $onHomeBranch ? $store->customers()->pluck('users.id')->toArray() : [],
                $inBranch($store->appointments()->whereIn('status', ['confirmed', 'in_progress', 'completed']))->pluck('customer_id')->toArray(),
            );
        } else {
            $others = $onHomeBranch ? $store->customers()->wherePivotNotNull('listed_at')->pluck('users.id')->toArray() : [];
        }

        $customerIds = collect(array_merge($jobCustomerIds, $others))->filter()->unique();
        // "Customer no. 3" means the shop's third customer across the whole shop (not the branch being viewed); the user
        // id is shared with every shop, owner and staff account.
        $shopNumbers = collect(array_merge(
            $store->jobOrders()->pluck('customer_id')->toArray(),
            $store->customers()->wherePivotNotNull('listed_at')->pluck('users.id')->toArray(),
        ))->filter()->unique()->sort()->values()->flip()->map(fn ($i) => $i + 1);

        // Search only narrows this shop's own customers (name, email or number); it never pulls in other accounts.
        $search = trim((string) $request->input('search', ''));

        // "My customers": the ones with an appointment assigned to me or a job I'm on.
        if ($request->boolean('mine')) {
            $me = $request->user()->id;
            $mine = $store->appointments()->where('assigned_staff_id', $me)->pluck('customer_id')
                ->merge($store->jobOrders()->where(function ($q) use ($me) {
                    $q->where('assigned_staff_id', $me)
                        ->orWhereIn('job_orders.id', \Illuminate\Support\Facades\DB::table('job_order_staff')->where('user_id', $me)->select('job_order_id'));
                })->pluck('customer_id'));
            $customerIds = $customerIds->intersect($mine->unique())->values();
        }

        // Store-specific notes live on the store_customers pivot, not the User
        // row — fetched once here (keyed by user id) rather than per-customer,
        // same N+1-avoidance reasoning as the jobOrders/appointments eager loads.
        $notesByCustomerId = $store->customers()->pluck('store_customers.notes', 'users.id');
        $trustedIds = $store->customers()->wherePivot('is_trusted', true)->pluck('users.id')->flip();

        $customersQuery = User::whereIn('id', $customerIds);
        if ($search !== '') {
            $customersQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // A customer belongs to the whole shop, but a branch view only shows that branch's orders and visits for them
        // (and the totals worked out from those), never another branch's.
        $customers = $customersQuery
            ->with([
                'jobOrders' => function ($query) use ($store, $inBranch) {
                    $inBranch($query->where('store_id', $store->id));
                },
                // Eager-loaded once for all customers (already ordered so the
                // first element is the most recent) instead of running a
                // fresh query per customer inside the map() below — avoids an
                // N+1 that scaled with the store's total customer count.
                'appointments' => function ($query) use ($store, $branchId) {
                    $query->where('store_id', $store->id)->orderBy('scheduled_at', 'desc')
                        ->when($branchId, fn ($q) => $q->where(fn ($w) => $w->where('store_branch_id', $branchId)->orWhereNull('store_branch_id')));
                },
            ])
            ->get()
            ->map(function ($user) use ($notesByCustomerId, $shopNumbers, $trustedIds) {
                $user->total_spend = $user->jobOrders->sum(fn ($j) => (float) $j->total_amount - (float) $j->balance - (float) $j->discount_amount);
                $user->last_appointment = $user->appointments->first();
                $user->active_jobs = $user->jobOrders->whereNotIn('status', ['completed', 'cancelled', 'rejected'])->count();
                $user->completed_jobs = $user->jobOrders->where('status', 'completed')->count();
                $user->store_notes = $notesByCustomerId[$user->id] ?? null;
                $user->is_trusted = $trustedIds->has($user->id);
                $user->shop_number = $shopNumbers[$user->id] ?? null;
                $user->is_walk_in = ! self::cameOnline($user->jobOrders, $user->appointments);
                $user->intake_channel = $user->is_walk_in ? 'walk_in' : 'online';

                return $user;
            });

        $sorted = collect($customers)->sortByDesc('total_spend')->values();
        // Plain staff see the customers and their orders without what was spent or paid.
        if (! JobOrder::mayRevealMoney($request->user())) {
            $sorted->each(function ($c) use ($request) {
                $c->total_spend = null;
                $c->jobOrders->each(fn (JobOrder $j) => $j->seenBy($request->user()));
            });
        }

        return response()->json([
            'success' => true,
            'data' => $sorted,
        ]);
    }

    public function show(Request $request, Store $store, User $customer): JsonResponse
    {
        if (! $request->user()->hasRole('store_owner') && ! $request->user()->hasRole('branch_manager') && ! $request->user()->hasRole('staff')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Verify customer belongs to store
        if (! $this->isCustomerOfStore($store, $customer)) {
            return response()->json(['message' => self::MSG_NOT_FOUND], 404);
        }

        $storeNotes = $store->customers()->where('user_id', $customer->id)->value('store_customers.notes');

        // Backfill first: a job order that still carries measurements without a linked profile gets
        // one, so the detail screen's measurement list never misses the customer's own measurements.
        JobMeasurementSync::syncForCustomer($store, $customer->id);

        $measurements = $store->measurements()
            ->where('customer_id', $customer->id)
            ->with('customer:id,name,email,contact_email')
            ->latest()
            ->get();

        $pin = BranchPin::of($request->user());
        $jobs = $store->jobOrders()
            ->where('customer_id', $customer->id)
            ->when($pin, fn ($q) => $q->where('store_branch_id', $pin))
            ->with(['service', 'assignedStaff:id,name', 'staffStages', 'payments.recordedBy:id,name', 'branch:id,name'])
            ->latest()
            ->get();

        $appointments = $store->appointments()
            ->where('customer_id', $customer->id)
            ->when($pin, fn ($q) => $q->where(fn ($w) => $w->where('store_branch_id', $pin)->orWhereNull('store_branch_id')))
            ->with(['service:id,name,base_price', 'branch:id,name', 'assignedStaff:id,name', 'jobOrder:id,order_number'])
            ->orderBy('scheduled_at', 'desc')
            ->get();

        $customerData = $customer->toArray();
        $customerData['store_notes'] = $storeNotes;
        $customerData['is_trusted'] = $store->isTrustedCustomer($customer->id);
        $customerData['shop_number'] = $this->shopNumberOf($store, $customer->id);
        $customerData['is_walk_in'] = ! self::cameOnline($jobs, $appointments);
        $customerData['intake_channel'] = $customerData['is_walk_in'] ? 'walk_in' : 'online';
        $customerData['total_spend'] = JobOrder::mayRevealMoney($request->user())
            ? (float) $jobs->sum(fn ($j) => (float) $j->total_amount - (float) $j->balance - (float) $j->discount_amount)
            : null;
        $customerData['active_jobs'] = $jobs->whereNotIn('status', ['completed', 'cancelled'])->count();
        $customerData['completed_jobs'] = $jobs->where('status', 'completed')->count();
        $customerData['no_show_count'] = $appointments->where('status', 'no_show')->count();
        $customerData['last_appointment'] = $appointments->first();
        // Plain staff see this customer's orders without amounts or payments.
        $jobs->each(fn (JobOrder $j) => $j->seenBy($request->user()));

        return response()->json([
            'success' => true,
            'data' => [
                'customer' => $customerData,
                'measurements' => $measurements,
                'jobs' => $jobs,
                'appointments' => $appointments,
            ],
        ]);
    }

    /**
     * One customer's measurement history (every version of every profile), for the Client Book's
     * detail screen. Backfills first, like show() above.
     */
    public function measurements(Request $request, Store $store, User $customer): JsonResponse
    {
        if (! $request->user()->hasRole('store_owner') && ! $request->user()->hasRole('branch_manager') && ! $request->user()->hasRole('staff')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Same "belongs to this store" test as show() and update(): no other shop's customer is reachable here.
        if (! $this->isCustomerOfStore($store, $customer)) {
            return response()->json(['message' => self::MSG_NOT_FOUND], 404);
        }

        JobMeasurementSync::syncForCustomer($store, $customer->id);

        $measurements = $store->measurements()
            ->where('customer_id', $customer->id)
            ->with('customer:id,name,email')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $measurements,
        ]);
    }

    public function store(Request $request, Store $store): JsonResponse
    {
        if (! $request->user()->hasRole('store_owner') && ! $request->user()->hasRole('branch_manager') && ! $request->user()->hasRole('staff')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // A walk-in is registered with a name, a mobile number and a gender; the email is optional and normally left out.
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'phone' => ['required', 'string', 'max:20', $this->mobileRule()],
            'gender' => 'nullable|in:male,female',
            'suki_tag' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:2000',
        ]);
        $rawPhone = $validated['phone'];
        $validated['phone'] = PhoneNumber::normalize($rawPhone);

        $rawEmail = ! empty($validated['email']) ? trim($validated['email']) : null;
        $rawPhone = ! empty($validated['phone']) ? trim($validated['phone']) : null;
        $digitsPhone = $rawPhone ? preg_replace('/[^0-9]/', '', $rawPhone) : null;
        if ($digitsPhone && str_starts_with($digitsPhone, '63') && strlen($digitsPhone) === 12) {
            $digitsPhone = '0'.substr($digitsPhone, 2);
        }

        // The email and number staff type for a walk-in are only where order
        // updates go — not a login. So:
        //  - Re-use a record only when it is clearly the same person already in
        //    THIS shop's customer list: same contact number, same name, and no
        //    conflicting email. Never link to another shop's customer, or to
        //    whichever account happens to own the email (a family member sharing
        //    a Gmail used to get the walk-in's orders in their own account).
        //  - Otherwise create a new walk-in: a private placeholder login, with the
        //    typed email saved as the notification email (contact_email).
        $matchedUser = null;
        if ($digitsPhone) {
            $shopCustomerIds = collect()
                ->merge($store->customers()->pluck('users.id'))
                ->merge($store->jobOrders()->pluck('customer_id'))
                ->merge($store->appointments()->pluck('customer_id'))
                ->filter()
                ->unique();
            $existingByPhone = User::whereIn('id', $shopCustomerIds)
                ->whereIn('phone', [$validated['phone'], $rawPhone])
                ->first();

            // "Clearly the same person": same name (or the record has none yet) and no conflicting
            // email — and never a business account whose personal number happens to be in this
            // shop's customer set (that would let one shop's walk-in form rewrite a stranger).
            if ($existingByPhone) {
                $nameMatches = empty($existingByPhone->name)
                    || Str::lower(trim((string) $existingByPhone->name)) === Str::lower(trim($validated['name']));
                $existingEmail = $existingByPhone->notificationEmail();
                $emailConflicts = $rawEmail && $existingEmail && Str::lower($rawEmail) !== Str::lower($existingEmail);
                $isBusinessAccount = $existingByPhone->hasRole('store_owner') || $existingByPhone->hasRole('staff')
                    || $existingByPhone->hasRole('branch_manager') || $existingByPhone->hasRole('admin');

                if ($nameMatches && ! $emailConflicts && ! $isBusinessAccount) {
                    $matchedUser = $existingByPhone;
                }
            }
        }

        if ($matchedUser) {
            $user = $matchedUser;
            $updates = [
                'name' => $validated['name'],
                'phone' => $validated['phone'] ?? $user->phone,
                'gender' => $validated['gender'] ?? $user->gender,
                'suki_tag' => $validated['suki_tag'] ?? $user->suki_tag,
            ];
            // The record keeps its login email; the typed one only fills the notification slot when empty.
            if ($rawEmail && ! $user->notificationEmail()) {
                $updates['contact_email'] = $rawEmail;
            }
            $user->update($updates);
        } else {
            $user = User::create([
                'email' => 'walkin_'.time().'_'.Str::random(6).self::SUTURA_DOMAIN,
                'contact_email' => $rawEmail,
                'name' => $validated['name'],
                'phone' => PhoneNumber::normalize($validated['phone']),
                'gender' => $validated['gender'] ?? null,
                'suki_tag' => $validated['suki_tag'] ?? null,
                'password' => Hash::make(Str::random(32)),
            ]);
        }

        // Attach to store if not already attached — syncWithoutDetaching handles
        // both "attach for the first time" and "already attached, just update
        // the pivot notes" in one call, without touching any other customer's
        // pivot row the way a plain sync() would.
        // Added on the Customers page: they are on the list now. Added from a visit or an order form: they are someone the
        // shop can pick, and join the list when an order is made for them.
        $pivot = ['notes' => $validated['notes'] ?? null];
        if ($request->boolean('add_to_list')) {
            $pivot['listed_at'] = now();
        }
        $store->customers()->syncWithoutDetaching([$user->id => $pivot]);

        $userJobs = $user->jobOrders()->where('store_id', $store->id)->get();
        $hasWalkInJobs = $userJobs->where('intake_channel', 'walk_in')->isNotEmpty();
        $hasOnlineJobs = $userJobs->where('intake_channel', 'online')->isNotEmpty();
        $isSyntheticWalkIn = Str::startsWith($user->email, 'walkin_');

        $user->is_walk_in = $isSyntheticWalkIn || ($hasWalkInJobs && ! $hasOnlineJobs);
        $user->intake_channel = $user->is_walk_in ? 'walk_in' : 'online';
        $user->active_jobs = $userJobs->whereNotIn('status', ['completed', 'cancelled', 'rejected'])->count();
        $user->completed_jobs = $userJobs->where('status', 'completed')->count();
        $user->total_spend = (float) $userJobs->sum(fn ($j) => (float) $j->total_amount - (float) $j->balance - (float) $j->discount_amount);

        return response()->json([
            'success' => true,
            'data' => $user,
            'message' => $matchedUser ? 'Existing customer matched and linked successfully.' : 'Customer created successfully.',
        ], $matchedUser ? 200 : 201);
    }

    public function update(Request $request, Store $store, User $customer): JsonResponse
    {
        if (! $request->user()->hasRole('store_owner') && ! $request->user()->hasRole('branch_manager') && ! $request->user()->hasRole('staff')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Verify customer belongs to store
        if (! $this->isCustomerOfStore($store, $customer)) {
            return response()->json(['message' => self::MSG_NOT_FOUND], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'phone' => ['required', 'string', 'max:20', $this->mobileRule()],
            'gender' => 'nullable|in:male,female',
            'suki_tag' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:2000',
        ]);

        // A walk-in (placeholder login) has no login to change: the email field
        // is only where their order updates go, so it edits contact_email and
        // may be shared with another person.
        if (Str::startsWith((string) $customer->email, 'walkin_')) {
            $customer->update([
                'name' => $validated['name'],
                'contact_email' => ! empty($validated['email']) ? trim($validated['email']) : null,
                'phone' => $validated['phone'] ?? null,
                'suki_tag' => array_key_exists('suki_tag', $validated) ? $validated['suki_tag'] : $customer->suki_tag,
            ]);
            if (array_key_exists('notes', $validated)) {
                $store->customers()->syncWithoutDetaching([$customer->id => ['notes' => $validated['notes']]]);
            }
            $customer->store_notes = $store->customers()->where('user_id', $customer->id)->value('store_customers.notes');

            return response()->json(['success' => true, 'data' => $customer, 'message' => 'Customer updated successfully.']);
        }

        // An empty email on the edit form means "leave it as-is" — the form
        // already pre-fills this field with the customer's real email when
        // they have one (see CustomerFormModal), so a blank submission here
        // must never manufacture a fresh walkin_ placeholder and silently
        // overwrite a real, working login email. Only generate one if the
        // customer genuinely has no email on file at all.
        $email = $validated['email'] ?? null;
        if (! $email) {
            $email = $customer->email
                ?: ('walkin_'.time().'_'.Str::random(4).self::SUTURA_DOMAIN);
        }

        // Setting this record's email straight from input used to hit the
        // users.email unique constraint at the DB level with no warning if
        // it collided with a different user (business account or otherwise)
        // — a generic 500 instead of a message explaining why.
        if ($email !== $customer->email && User::where('email', $email)->where('id', '!=', $customer->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This email is already in use by another account.',
                'errors' => ['email' => ['This email is already in use by another account.']],
            ], 422);
        }

        $customer->update([
            'name' => $validated['name'],
            'email' => $email,
            'phone' => PhoneNumber::normalize($validated['phone']),
            'gender' => array_key_exists('gender', $validated) ? $validated['gender'] : $customer->gender,
            'suki_tag' => array_key_exists('suki_tag', $validated) ? $validated['suki_tag'] : $customer->suki_tag,
        ]);

        // Store-specific — lives on the pivot, not the User row (see
        // Store::customers()). Preserves the existing note when the request
        // doesn't include the field at all, same "omit = keep as-is" rule
        // already used above for suki_tag.
        if (array_key_exists('notes', $validated)) {
            $store->customers()->syncWithoutDetaching([
                $customer->id => ['notes' => $validated['notes']],
            ]);
        }

        $customer->store_notes = $store->customers()->where('user_id', $customer->id)->value('store_customers.notes');

        return response()->json([
            'success' => true,
            'data' => $customer,
            'message' => 'Customer updated successfully.',
        ]);
    }

    /**
     * Mark or unmark a customer as trusted by this shop (owner / branch manager only). New orders for a trusted
     * customer start with no downpayment; the balance is still collected before the garments are handed over.
     */
    public function setTrusted(Request $request, Store $store, User $customer): JsonResponse
    {
        if (! $request->user()->hasRole('store_owner') && ! $request->user()->hasRole('branch_manager')) {
            return response()->json(['message' => 'Only the owner or a branch manager can mark a trusted customer.'], 403);
        }
        if (! $this->isCustomerOfStore($store, $customer)) {
            return response()->json(['message' => self::MSG_NOT_FOUND], 404);
        }

        $trusted = (bool) $request->validate(['trusted' => 'required|boolean'])['trusted'];
        $store->customers()->syncWithoutDetaching([
            $customer->id => ['is_trusted' => $trusted, 'trusted_by' => $trusted ? $request->user()->id : null, 'trusted_at' => $trusted ? now() : null],
        ]);
        $store->auditLogs()->create([
            'user_id' => $request->user()->id,
            'action' => $trusted ? 'customer_marked_trusted' : 'customer_trust_removed',
            'model_type' => User::class,
            'model_id' => $customer->id,
            'payload' => ['customer' => $customer->name],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => $trusted ? 'Marked as a trusted customer.' : 'No longer marked as trusted.',
            'data' => ['is_trusted' => $trusted],
        ]);
    }

    public function destroy(Request $request, Store $store, User $customer): JsonResponse
    {
        if (! $request->user()->hasRole('store_owner') && ! $request->user()->hasRole('branch_manager')) {
            return response()->json(['message' => 'Only the owner or a branch manager can remove a customer.'], 403);
        }

        // Same "belongs to this store" test as show() and update(): a customer whose profile opens can be edited and removed.
        if (! $this->isCustomerOfStore($store, $customer)) {
            return response()->json(['message' => self::MSG_NOT_FOUND], 404);
        }

        // A customer with orders stays in the Client Book (the list is built from the shop's orders too), so say so
        // instead of pretending to remove them. Only someone with no orders can be taken off the book.
        $orders = $store->jobOrders()->where('customer_id', $customer->id)->count();
        if ($orders > 0) {
            return response()->json([
                'success' => false,
                'message' => "This customer has {$orders} order".($orders === 1 ? '' : 's').' on record, so they stay in your Client Book. Only customers with no orders can be removed.',
            ], 422);
        }

        // Detach, not delete: the same person may be a customer of other shops.
        $store->customers()->detach($customer->id);

        return response()->json([
            'success' => true,
            'message' => 'Customer removed successfully.',
        ]);
    }

    /**
     * "Online" in the Client Book means the customer reached the shop online: they have an online appointment or an
     * online order. Everyone else — added at the counter, even with an email — is a walk-in.
     *
     * @param  \Illuminate\Support\Collection<int, mixed>  $jobs
     * @param  \Illuminate\Support\Collection<int, mixed>  $appointments
     */
    private static function cameOnline($jobs, $appointments): bool
    {
        return $jobs->where('intake_channel', 'online')->isNotEmpty() || $appointments->where('intake_channel', 'online')->isNotEmpty();
    }

    /** On this shop's book: added by the shop, or has an order or an approved/completed appointment here. */
    private function isCustomerOfStore(Store $store, User $customer): bool
    {
        return $store->customers()->where('user_id', $customer->id)->exists()
            || $store->jobOrders()->where('customer_id', $customer->id)->exists()
            || $store->appointments()->where('customer_id', $customer->id)->whereIn('status', ['confirmed', 'in_progress', 'completed'])->exists();
    }

    /** A Philippine mobile number (0917 123 4567, +63 917 123 4567 …), so "097" or a landline is turned away. */
    private function mobileRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (PhoneNumber::normalize((string) $value) === null) {
                $digits = preg_replace('/\D+/', '', (string) $value);
                if (str_starts_with($digits, '09') && strlen($digits) > 11) {
                    $fail("Mobile number has ".strlen($digits)." digits. Philippine mobile numbers must be exactly 11 digits (e.g. 0977 704 4683).");
                } elseif (str_starts_with($digits, '09') && strlen($digits) < 11) {
                    $fail("Mobile number has only ".strlen($digits)." digits. Philippine mobile numbers must be exactly 11 digits.");
                } else {
                    $fail("Enter an 11-digit Philippine mobile number starting with 09 (e.g. 0977 704 4683).");
                }
            }
        };
    }

    /** The customer's place among this shop's customers (1 = the first), counting everyone the index lists. */
    private function shopNumberOf(Store $store, int $customerId): ?int
    {
        $ids = collect()
            ->merge($store->customers()->pluck('users.id'))
            ->merge($store->jobOrders()->pluck('customer_id'))
            ->merge($store->appointments()->whereIn('status', ['confirmed', 'in_progress', 'completed'])->pluck('customer_id'))
            ->filter()->unique()->sort()->values();
        $position = $ids->search($customerId);

        return $position === false ? null : $position + 1;
    }
}
