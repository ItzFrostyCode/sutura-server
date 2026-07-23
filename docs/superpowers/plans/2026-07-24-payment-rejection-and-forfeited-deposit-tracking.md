# Payment Rejection & Forfeited-Deposit Tracking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a shop_owner/branch_manager reject a specific JobOrder payment found to be fraudulent after the fact (reversing the balance even on completed jobs), categorize job-order cancellations (including a distinct "forfeited deposit, customer went dark" reason), and surface both as cross-branch loss figures in Reports.

**Architecture:** Backend (Laravel, `sutura-server`): two new nullable-column migrations, a new `POST .../payments/{payment}/reject` endpoint gated the same as every other JobOrder money action (`role:shop_owner,branch_manager`), a `cancellation_reason` validated alongside the existing status-update route, and two new derived (not stored) figures added to the existing Analytics endpoints. Frontend (Next.js, `sutura-client`): a "reject" action tucked into a kebab menu on each payment row, a modal intercepting the "Cancelled" option in the existing status dropdown, and two new KPI tiles/table columns blended into the existing Reports components.

**Tech Stack:** Laravel 11 (PHPUnit, Eloquent), Next.js/React/TypeScript (no frontend test runner present — `sutura-client/package.json` has no Jest/Vitest/RTL, so frontend tasks verify via `tsc`/`next build` plus manual browser check, not automated tests).

## Global Constraints

- Every money-touching JobOrder action in this codebase is gated `role:shop_owner,branch_manager` (never plain `staff`) — the new reject endpoint follows this exactly, per `sutura-server/CLAUDE.md`'s documented role boundary ("staff... cannot... see owner-only financials").
- No refund/payment-gateway logic — SUTURA tracks payment/deposit status only, per approved thesis scope. Rejecting only ever corrects internal ledger fields (`balance`, `payment_status`), never triggers an actual money movement.
- `AnalyticsController::branchComparison()` stays owner-only — unchanged access, per its existing design comment ("an owner-level strategic view... deliberately not exposed to branch managers").
- No new customer-facing notification content — the existing generic `JobStatusUpdatedNotification` on any `cancelled` transition is left as-is; no notification anywhere should say "rejected as fake" or "forfeited deposit" in customer-visible text.
- Spec reference: `sutura-server/docs/superpowers/specs/2026-07-24-payment-rejection-and-forfeited-deposit-tracking-design.md`.

---

## Task 1: Migrations — payment rejection fields + job_order cancellation reason

**Files:**
- Create: `database/migrations/2026_07_24_090000_add_rejection_fields_to_payments_table.php`
- Create: `database/migrations/2026_07_24_090100_add_cancellation_reason_to_job_orders_table.php`

**Interfaces:**
- Produces: `payments.rejected_at` (nullable timestamp), `payments.rejected_reason` (nullable text), `payments.rejected_by` (nullable FK to `users.id`); `job_orders.cancellation_reason` (nullable string). Every later task depends on these columns existing.

- [ ] **Step 1: Write the payments migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['rejected_by']);
            $table->dropColumn(['rejected_at', 'rejected_reason', 'rejected_by']);
        });
    }
};
```

Save as `database/migrations/2026_07_24_090000_add_rejection_fields_to_payments_table.php`.

- [ ] **Step 2: Write the job_orders migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('cancellation_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('cancellation_reason');
        });
    }
};
```

Save as `database/migrations/2026_07_24_090100_add_cancellation_reason_to_job_orders_table.php`.

- [ ] **Step 3: Run the migrations**

Run: `cd sutura-server && php artisan migrate`
Expected: both new migrations listed as `Migrating` then `Migrated`, no errors.

- [ ] **Step 4: Verify columns exist**

Run: `php artisan tinker --execute="dd(Schema::hasColumn('payments','rejected_at'), Schema::hasColumn('payments','rejected_by'), Schema::hasColumn('job_orders','cancellation_reason'));"`
Expected: three `true` values printed.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_24_090000_add_rejection_fields_to_payments_table.php database/migrations/2026_07_24_090100_add_cancellation_reason_to_job_orders_table.php
git commit -m "feat: add rejection fields to payments and cancellation_reason to job_orders"
```

---

## Task 2: Cancellation reason — model + validation

**Files:**
- Modify: `app/Models/JobOrder.php`
- Modify: `app/Http/Requests/Shop/UpdateJobOrderRequest.php`
- Test: `tests/Feature/Api/V1/JobOrderTest.php`

**Interfaces:**
- Consumes: `job_orders.cancellation_reason` column (Task 1).
- Produces: `JobOrder::CANCELLATION_REASONS` (array constant) — Task 9's frontend dropdown must list the same four values in the same order: `customer_request`, `shop_unable_to_fulfill`, `forfeited_deposit_abandoned`, `other`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Api/V1/JobOrderTest.php` (inside the `JobOrderTest` class, alongside the existing test methods):

```php
    public function test_cancelling_job_order_requires_a_reason()
    {
        $jobOrder = \App\Models\JobOrder::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'order_number' => 'JO-2026-9001',
            'total_amount' => 5000,
            'balance' => 5000,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)->putJson("/api/v1/shops/{$this->shop->id}/jobs/{$jobOrder->id}", [
            'status' => 'cancelled',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors('cancellation_reason');
    }

    public function test_cancelling_job_order_with_forfeited_deposit_reason_persists()
    {
        $jobOrder = \App\Models\JobOrder::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'order_number' => 'JO-2026-9002',
            'total_amount' => 5000,
            'balance' => 2500,
            'payment_status' => 'partial',
            'status' => 'cutting',
        ]);

        $response = $this->actingAs($this->user)->putJson("/api/v1/shops/{$this->shop->id}/jobs/{$jobOrder->id}", [
            'status' => 'cancelled',
            'cancellation_reason' => 'forfeited_deposit_abandoned',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('job_orders', [
            'id' => $jobOrder->id,
            'status' => 'cancelled',
            'cancellation_reason' => 'forfeited_deposit_abandoned',
        ]);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=test_cancelling_job_order`
Expected: `test_cancelling_job_order_requires_a_reason` FAILs (no validation exists yet, so status 200 instead of 422); `test_cancelling_job_order_with_forfeited_deposit_reason_persists` FAILs on `assertDatabaseHas` (column not in `$fillable` yet, so it's silently dropped by `update()`).

- [ ] **Step 3: Add the constant and fillable field to JobOrder**

In `app/Models/JobOrder.php`, add the constant near the other constants (after `MATERIAL_SOURCES`, before `protected $fillable`):

```php
    /**
     * Categorizes why a job order was cancelled. `forfeited_deposit_abandoned`
     * is the one with real financial-reporting consequences (see
     * AnalyticsController) — the customer went uncontactable after fabric was
     * already cut, and per shop policy the deposit already collected is kept,
     * not refunded. The other three carry no reversal or reporting logic.
     */
    public const CANCELLATION_REASONS = [
        'customer_request', 'shop_unable_to_fulfill', 'forfeited_deposit_abandoned', 'other',
    ];
```

Then add `'cancellation_reason'` to the existing `protected $fillable` array (alongside `'discount_amount', 'rejection_reason',`):

```php
        'discount_amount', 'rejection_reason', 'cancellation_reason',
```

- [ ] **Step 4: Add validation to UpdateJobOrderRequest**

In `app/Http/Requests/Shop/UpdateJobOrderRequest.php`, add this rule to the `rules()` array (right after the `'status' => ['sometimes', Rule::in(JobOrder::STATUSES)],` line):

```php
            'cancellation_reason' => ['nullable', 'string', Rule::in(JobOrder::CANCELLATION_REASONS), 'required_if:status,cancelled'],
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=test_cancelling_job_order`
Expected: both PASS.

- [ ] **Step 6: Run the full JobOrder test file to check for regressions**

Run: `php artisan test tests/Feature/Api/V1/JobOrderTest.php`
Expected: all tests PASS (existing tests don't set `status: cancelled`, so `required_if` doesn't affect them).

- [ ] **Step 7: Commit**

```bash
git add app/Models/JobOrder.php app/Http/Requests/Shop/UpdateJobOrderRequest.php tests/Feature/Api/V1/JobOrderTest.php
git commit -m "feat: require a cancellation_reason when cancelling a job order"
```

---

## Task 3: Payment rejection endpoint

**Files:**
- Modify: `app/Models/Payment.php`
- Create: `app/Notifications/PaymentRejectedNotification.php`
- Modify: `app/Http/Controllers/Api/V1/JobOrderController.php`
- Modify: `routes/api.php:122` (insert new route line)
- Test: `tests/Feature/Api/V1/JobOrderTest.php`

**Interfaces:**
- Consumes: `payments.rejected_at`/`rejected_reason`/`rejected_by` columns (Task 1); `JobOrderController::branchAccessDenied()` (existing private method).
- Produces: `JobOrderController::rejectPayment(Request $request, Shop $shop, JobOrder $jobOrder, Payment $payment): JsonResponse`, reachable at `POST /api/v1/shops/{shop}/jobs/{jobOrder}/payments/{payment}/reject` (matches the URL shape of every other job route in this file, e.g. `updatePayment`'s `/jobs/{jobOrder}/payments/{payment}`). `PaymentRejectedNotification(JobOrder $jobOrder, Payment $payment, User $rejectedBy)`.

- [ ] **Step 1: Write the first failing test — reversal on a partially-paid job**

Add to `tests/Feature/Api/V1/JobOrderTest.php`:

```php
    public function test_owner_can_reject_a_payment_and_balance_is_reversed()
    {
        $jobOrder = \App\Models\JobOrder::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'order_number' => 'JO-2026-9010',
            'total_amount' => 5000,
            'balance' => 3000,
            'payment_status' => 'partial',
            'status' => 'cutting',
        ]);

        $payment = $jobOrder->payments()->create([
            'amount' => 2000,
            'payment_method' => 'gcash',
            'recorded_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/shops/{$this->shop->id}/jobs/{$jobOrder->id}/payments/{$payment->id}/reject",
            ['reason' => 'GCash reference number does not match our transaction history.']
        );

        $response->assertStatus(200)->assertJsonPath('success', true);

        $this->assertDatabaseHas('job_orders', [
            'id' => $jobOrder->id,
            'balance' => 5000.00,
            'payment_status' => 'unpaid',
        ]);

        $payment->refresh();
        $this->assertNotNull($payment->rejected_at);
        $this->assertEquals('GCash reference number does not match our transaction history.', $payment->rejected_reason);
        $this->assertEquals($this->user->id, $payment->rejected_by);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_owner_can_reject_a_payment_and_balance_is_reversed`
Expected: FAIL — route doesn't exist yet (404).

- [ ] **Step 3: Add rejection fields to the Payment model**

In `app/Models/Payment.php`, replace the whole file:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'job_order_id',
        'amount',
        'payment_method',
        'reference',
        'recorded_by',
        'notes',
        'receipt_path',
        'rejected_at',
        'rejected_reason',
        'rejected_by',
    ];

    protected $casts = [
        'rejected_at' => 'datetime',
    ];

    public function jobOrder()
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
```

- [ ] **Step 4: Create the notification class**

Create `app/Notifications/PaymentRejectedNotification.php`:

```php
<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\User;

class PaymentRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public JobOrder $jobOrder;
    public Payment $payment;
    public User $rejectedBy;

    public function __construct(JobOrder $jobOrder, Payment $payment, User $rejectedBy)
    {
        $this->jobOrder   = $jobOrder;
        $this->payment    = $payment;
        $this->rejectedBy = $rejectedBy;
    }

    /**
     * Delivery channels — database only (in-app notification).
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Database payload.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type'         => 'payment_rejected',
            'title'        => 'Payment Rejected',
            'message'      => '₱' . number_format((float) $this->payment->amount, 2) . ' payment on order ' . $this->jobOrder->order_number . ' was rejected by ' . $this->rejectedBy->name . '.',
            'action_url'   => '/dashboard/jobs/' . $this->jobOrder->id,
            'job_order_id' => $this->jobOrder->id,
            'order_number' => $this->jobOrder->order_number,
            'payment_id'   => $this->payment->id,
            'amount'       => (float) $this->payment->amount,
            'rejected_by'  => $this->rejectedBy->name,
        ];
    }
}
```

- [ ] **Step 5: Add the minimal rejectPayment() method to JobOrderController**

In `app/Http/Controllers/Api/V1/JobOrderController.php`, add this new public method (place it after `updatePayment()`, before `assignStaff()`):

```php
    /**
     * Reject a specific payment discovered to be fraudulent/bad after the
     * fact (e.g. a fake GCash receipt caught during a later review — staff
     * couldn't have known at the time). Reverses the balance/payment_status
     * regardless of the job's current production status: the "no balance,
     * no claim" rule in update() only blocks *advancing to* completed with a
     * balance owed, it doesn't forbid a completed job from later having a
     * balance again — that state becoming possible is exactly how a fraud
     * discovered after the garment was already handed over becomes visible.
     */
    public function rejectPayment(Request $request, Shop $shop, JobOrder $jobOrder, Payment $payment): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id || $payment->job_order_id !== $jobOrder->id) {
            return response()->json(['success' => false, 'message' => 'Payment not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        \Illuminate\Support\Facades\DB::transaction(function () use ($jobOrder, $payment, $validated, $request) {
            $locked = JobOrder::where('id', $jobOrder->id)->lockForUpdate()->firstOrFail();

            $newBalance = round((float) $locked->balance + (float) $payment->amount, 2);
            $newPaymentStatus = $newBalance <= 0 ? 'paid' : 'partial';

            $locked->update([
                'balance' => $newBalance,
                'payment_status' => $newPaymentStatus,
            ]);

            $payment->update([
                'rejected_at' => now(),
                'rejected_reason' => $validated['reason'],
                'rejected_by' => $request->user()->id,
            ]);

            $shop = $locked->shop;
            $shop->auditLogs()->create([
                'user_id' => $request->user()->id,
                'action' => 'payment_rejected',
                'model_type' => Payment::class,
                'model_id' => $payment->id,
                'payload' => [
                    'job_order_id' => $locked->id,
                    'amount' => (float) $payment->amount,
                    'reason' => $validated['reason'],
                ],
                'ip_address' => $request->ip(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Payment rejected.',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff', 'payments.recordedBy:id,name', 'staffStages'])
        ]);
    }
```

- [ ] **Step 6: Add the route**

In `routes/api.php`, find this line (inside the `Route::middleware('role:shop_owner,branch_manager')->group(function () {` block):

```php
                Route::post('/jobs/{jobOrder}/discount', [JobOrderController::class, 'applyDiscount']);
```

Add this line directly after it:

```php
                Route::post('/jobs/{jobOrder}/payments/{payment}/reject', [JobOrderController::class, 'rejectPayment']);
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=test_owner_can_reject_a_payment_and_balance_is_reversed`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Models/Payment.php app/Notifications/PaymentRejectedNotification.php app/Http/Controllers/Api/V1/JobOrderController.php routes/api.php tests/Feature/Api/V1/JobOrderTest.php
git commit -m "feat: add payment rejection endpoint with balance reversal"
```

- [ ] **Step 9: Write the completed-job test**

Add to `tests/Feature/Api/V1/JobOrderTest.php`:

```php
    public function test_rejecting_a_payment_on_an_already_completed_job_still_reverses_balance()
    {
        $jobOrder = \App\Models\JobOrder::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'order_number' => 'JO-2026-9011',
            'total_amount' => 5000,
            'balance' => 0,
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);

        $payment = $jobOrder->payments()->create([
            'amount' => 5000,
            'payment_method' => 'bank_transfer',
            'recorded_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/shops/{$this->shop->id}/jobs/{$jobOrder->id}/payments/{$payment->id}/reject",
            ['reason' => 'Bank transfer receipt was a reused screenshot from a different customer.']
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('job_orders', [
            'id' => $jobOrder->id,
            'status' => 'completed',
            'balance' => 5000.00,
            'payment_status' => 'partial',
        ]);
    }
```

Note: `payment_status` is `partial` here, not `unpaid`, because the current minimal implementation sets `partial` whenever `newBalance > 0` — this test locks in that this job (which only ever had this one, now-rejected, payment) still reads `partial` rather than `unpaid`. This gap gets fixed in Step 12.

- [ ] **Step 10: Run test to verify it passes as-is**

Run: `php artisan test --filter=test_rejecting_a_payment_on_an_already_completed_job_still_reverses_balance`
Expected: PASS — the current implementation doesn't special-case job status at all, so no new code is needed for this scenario.

- [ ] **Step 11: Write the idempotency test**

Add to `tests/Feature/Api/V1/JobOrderTest.php`:

```php
    public function test_cannot_reject_an_already_rejected_payment()
    {
        $jobOrder = \App\Models\JobOrder::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'order_number' => 'JO-2026-9012',
            'total_amount' => 5000,
            'balance' => 5000,
            'status' => 'pending',
        ]);

        $payment = $jobOrder->payments()->create([
            'amount' => 1000,
            'payment_method' => 'cash',
            'recorded_by' => $this->user->id,
            'rejected_at' => now(),
            'rejected_reason' => 'Already flagged once.',
            'rejected_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/shops/{$this->shop->id}/jobs/{$jobOrder->id}/payments/{$payment->id}/reject",
            ['reason' => 'Trying again.']
        );

        $response->assertStatus(422);
    }
```

- [ ] **Step 12: Run test to verify it fails, then add the guard and fix payment_status recomputation**

Run: `php artisan test --filter=test_cannot_reject_an_already_rejected_payment`
Expected: FAIL — nothing currently blocks re-rejecting, so this returns 200 instead of 422.

In `app/Http/Controllers/Api/V1/JobOrderController.php`, in `rejectPayment()`, add the idempotency check right after the existing 404 check, and fix `payment_status` to correctly fall back to `unpaid` when no other confirmed payment remains:

```php
        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        if ($payment->rejected_at !== null) {
            return response()->json(['success' => false, 'message' => 'This payment has already been rejected.'], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        \Illuminate\Support\Facades\DB::transaction(function () use ($jobOrder, $payment, $validated, $request) {
            $locked = JobOrder::where('id', $jobOrder->id)->lockForUpdate()->firstOrFail();

            $newBalance = round((float) $locked->balance + (float) $payment->amount, 2);
            $hasOtherConfirmedPayments = $locked->payments()
                ->whereNull('rejected_at')
                ->where('id', '!=', $payment->id)
                ->exists();
            $newPaymentStatus = $newBalance <= 0
                ? 'paid'
                : ($hasOtherConfirmedPayments ? 'partial' : 'unpaid');

            $locked->update([
                'balance' => $newBalance,
                'payment_status' => $newPaymentStatus,
            ]);
```

(The rest of the transaction body — stamping the payment, writing the audit log — stays unchanged.)

- [ ] **Step 13: Update the completed-job test's expectation**

In `test_rejecting_a_payment_on_an_already_completed_job_still_reverses_balance` (Step 9), change the last assertion's `'payment_status' => 'partial',` to `'payment_status' => 'unpaid',` — with the fixed recomputation logic, a job whose only payment was just rejected now correctly reads `unpaid`.

- [ ] **Step 14: Run both tests to verify they pass**

Run: `php artisan test --filter=test_cannot_reject_an_already_rejected_payment`
Run: `php artisan test --filter=test_rejecting_a_payment_on_an_already_completed_job_still_reverses_balance`
Expected: both PASS.

- [ ] **Step 15: Commit**

```bash
git add app/Http/Controllers/Api/V1/JobOrderController.php tests/Feature/Api/V1/JobOrderTest.php
git commit -m "fix: prevent re-rejecting a payment and correct payment_status fallback to unpaid"
```

- [ ] **Step 16: Write the role-gate test**

Add to `tests/Feature/Api/V1/JobOrderTest.php` (add `use App\Models\Role;` is already imported at the top of this file):

```php
    public function test_staff_cannot_reject_a_payment()
    {
        $staffRole = Role::firstOrCreate(['name' => 'staff'], ['description' => 'Staff']);
        $staffUser = User::factory()->create();
        $staffUser->roles()->attach($staffRole);
        StaffProfile::create([
            'shop_id' => $this->shop->id,
            'user_id' => $staffUser->id,
            'role' => 'tailor',
        ]);

        $jobOrder = \App\Models\JobOrder::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'order_number' => 'JO-2026-9013',
            'total_amount' => 5000,
            'balance' => 3000,
            'status' => 'cutting',
        ]);
        $payment = $jobOrder->payments()->create([
            'amount' => 2000,
            'payment_method' => 'cash',
            'recorded_by' => $this->user->id,
        ]);

        $response = $this->actingAs($staffUser)->postJson(
            "/api/v1/shops/{$this->shop->id}/jobs/{$jobOrder->id}/payments/{$payment->id}/reject",
            ['reason' => 'test']
        );

        $response->assertStatus(403);
    }
```

Note: `Role::firstOrCreate` (not `Role::create`) — a `staff` role may already have been created by an earlier test in the same run depending on execution order; `firstOrCreate` avoids a unique-constraint failure either way.

- [ ] **Step 17: Run test to verify it already passes**

Run: `php artisan test --filter=test_staff_cannot_reject_a_payment`
Expected: PASS immediately — the route lives inside the `role:shop_owner,branch_manager` middleware group (Step 6), which already returns 403 for any other role before the controller method ever runs. No implementation change needed; this test locks the behavior in against future regression.

- [ ] **Step 18: Write the audit log test**

Add to `tests/Feature/Api/V1/JobOrderTest.php`:

```php
    public function test_rejecting_a_payment_writes_an_audit_log_entry()
    {
        $jobOrder = \App\Models\JobOrder::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'order_number' => 'JO-2026-9014',
            'total_amount' => 5000,
            'balance' => 3000,
            'status' => 'cutting',
        ]);
        $payment = $jobOrder->payments()->create([
            'amount' => 2000,
            'payment_method' => 'cash',
            'recorded_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)->postJson(
            "/api/v1/shops/{$this->shop->id}/jobs/{$jobOrder->id}/payments/{$payment->id}/reject",
            ['reason' => 'Fake receipt.']
        );

        $this->assertDatabaseHas('audit_logs', [
            'shop_id' => $this->shop->id,
            'user_id' => $this->user->id,
            'action' => 'payment_rejected',
            'model_type' => \App\Models\Payment::class,
            'model_id' => $payment->id,
        ]);
    }
```

- [ ] **Step 19: Run test to verify it already passes**

Run: `php artisan test --filter=test_rejecting_a_payment_writes_an_audit_log_entry`
Expected: PASS immediately (the audit log write was already part of Step 5's implementation). Locks the behavior in.

- [ ] **Step 20: Write the branch_manager-notifies-owner test**

Add to `tests/Feature/Api/V1/JobOrderTest.php`, and add `use Illuminate\Support\Facades\Notification;` and `use App\Models\ShopBranch;` to the file's imports:

```php
    public function test_branch_manager_rejecting_a_payment_notifies_the_shop_owner()
    {
        Notification::fake();

        $branch = ShopBranch::create([
            'shop_id' => $this->shop->id,
            'name' => 'Matina Branch',
            'address' => 'Matina, Davao City',
            'city' => 'Davao City',
        ]);
        $managerRole = Role::firstOrCreate(['name' => 'branch_manager'], ['description' => 'Branch Manager']);
        $manager = User::factory()->create();
        $manager->roles()->attach($managerRole);
        StaffProfile::create([
            'shop_id' => $this->shop->id,
            'shop_branch_id' => $branch->id,
            'user_id' => $manager->id,
            'role' => 'head_tailor',
            'is_branch_manager' => true,
        ]);

        $jobOrder = \App\Models\JobOrder::create([
            'shop_id' => $this->shop->id,
            'shop_branch_id' => $branch->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'order_number' => 'JO-2026-9015',
            'total_amount' => 5000,
            'balance' => 3000,
            'status' => 'cutting',
        ]);
        $payment = $jobOrder->payments()->create([
            'amount' => 2000,
            'payment_method' => 'cash',
            'recorded_by' => $manager->id,
        ]);

        $response = $this->actingAs($manager)->postJson(
            "/api/v1/shops/{$this->shop->id}/jobs/{$jobOrder->id}/payments/{$payment->id}/reject",
            ['reason' => 'Cash count came up short at closing.']
        );

        $response->assertStatus(200);
        Notification::assertSentTo($this->user, \App\Notifications\PaymentRejectedNotification::class);
    }
```

- [ ] **Step 21: Run test to verify it fails**

Run: `php artisan test --filter=test_branch_manager_rejecting_a_payment_notifies_the_shop_owner`
Expected: FAIL — `Notification::assertSentTo` finds nothing, since `rejectPayment()` doesn't send any notification yet.

- [ ] **Step 22: Add the notify-owner call**

In `app/Http/Controllers/Api/V1/JobOrderController.php`, inside `rejectPayment()`'s transaction closure, add this right after the `auditLogs()->create([...])` call (still inside the `use (...)` closure, so also add `$request` — it's already in the `use` list):

```php
            if ($request->user()->hasRole('branch_manager')) {
                $shop->owner?->notify(new \App\Notifications\PaymentRejectedNotification($locked, $payment, $request->user()));
            }
```

- [ ] **Step 23: Run test to verify it passes**

Run: `php artisan test --filter=test_branch_manager_rejecting_a_payment_notifies_the_shop_owner`
Expected: PASS.

- [ ] **Step 24: Run the full JobOrder test file**

Run: `php artisan test tests/Feature/Api/V1/JobOrderTest.php`
Expected: all tests PASS (no regressions in the pre-existing tests).

- [ ] **Step 25: Commit**

```bash
git add app/Http/Controllers/Api/V1/JobOrderController.php tests/Feature/Api/V1/JobOrderTest.php
git commit -m "feat: notify shop owner when a branch manager rejects a payment"
```

---

## Task 4: Analytics — rejected-payments and forfeited-deposit figures

**Files:**
- Modify: `app/Http/Controllers/Api/V1/AnalyticsController.php`
- Test: `tests/Feature/Api/V1/AnalyticsTest.php`

**Interfaces:**
- Consumes: `payments.rejected_at` (Task 1/3); `job_orders.cancellation_reason` (Task 1/2).
- Produces: `index()` response gains `rejected_payments_count` (int), `rejected_payments_amount` (float), `forfeited_deposit_count` (int), `forfeited_deposit_amount` (float). `branchComparison()`'s per-row array gains `rejected_payments_amount` (float) and `forfeited_deposit_amount` (float) — same key names as `index()`, since Task 9's frontend table reads both from the same field names.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Api/V1/AnalyticsTest.php`:

```php
    public function test_branch_comparison_and_kpis_report_rejected_and_forfeited_amounts(): void
    {
        // Branch A: a completed job with a payment later rejected as fraudulent.
        $completedJob = JobOrder::create([
            'order_number' => 'JO-' . Str::random(10),
            'shop_id' => $this->shop->id,
            'shop_branch_id' => $this->branchA->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'status' => 'completed',
            'payment_status' => 'unpaid',
            'total_amount' => 5000,
            'balance' => 5000,
        ]);
        $completedJob->payments()->create([
            'amount' => 5000,
            'payment_method' => 'gcash',
            'rejected_at' => now(),
            'rejected_reason' => 'Fake receipt',
            'rejected_by' => $this->user->id,
        ]);

        // Branch B: a cancelled job with a forfeited deposit already collected.
        $forfeitedJob = JobOrder::create([
            'order_number' => 'JO-' . Str::random(10),
            'shop_id' => $this->shop->id,
            'shop_branch_id' => $this->branchB->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'status' => 'cancelled',
            'cancellation_reason' => 'forfeited_deposit_abandoned',
            'payment_status' => 'partial',
            'total_amount' => 8000,
            'balance' => 5000,
        ]);
        $forfeitedJob->payments()->create([
            'amount' => 3000,
            'payment_method' => 'cash',
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/shops/{$this->shop->id}/analytics?branch_id={$this->branchA->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.rejected_payments_amount', 5000.0)
            ->assertJsonPath('data.forfeited_deposit_amount', 0.0);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/shops/{$this->shop->id}/analytics/branches");

        $response->assertStatus(200);
        $rows = collect($response->json('data'));
        $branchARow = $rows->firstWhere('branch_id', $this->branchA->id);
        $branchBRow = $rows->firstWhere('branch_id', $this->branchB->id);

        $this->assertEquals(5000.0, $branchARow['rejected_payments_amount']);
        $this->assertEquals(3000.0, $branchBRow['forfeited_deposit_amount']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_branch_comparison_and_kpis_report_rejected_and_forfeited_amounts`
Expected: FAIL — `assertJsonPath`/array key lookups find nothing, since neither field exists in either response yet.

- [ ] **Step 3: Add the figures to index()**

In `app/Http/Controllers/Api/V1/AnalyticsController.php`, inside `index()`, add this block right after the existing `$outstandingBalances = ...` block (before `// Today's appointments`):

```php
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
```

Then add these four keys to the returned `data` array (after `'outstanding_balances' => $outstandingBalances,`):

```php
                'rejected_payments_count'    => $rejectedPaymentsCount,
                'rejected_payments_amount'   => $rejectedPaymentsAmount,
                'forfeited_deposit_count'    => $forfeitedDepositCount,
                'forfeited_deposit_amount'  => $forfeitedDepositAmount,
```

- [ ] **Step 4: Add the figures to branchComparison()**

In the same file, inside `branchComparison()`'s `$buildRow` closure, add this right before the closure's `return [...]` statement:

```php
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
```

Then add these two keys to the closure's returned array (after `'total_staff' => ...`):

```php
                'rejected_payments_amount'  => $rejectedAmount,
                'forfeited_deposit_amount'  => $forfeitedAmount,
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=test_branch_comparison_and_kpis_report_rejected_and_forfeited_amounts`
Expected: PASS.

- [ ] **Step 6: Run the full Analytics test file**

Run: `php artisan test tests/Feature/Api/V1/AnalyticsTest.php`
Expected: all tests PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/V1/AnalyticsController.php tests/Feature/Api/V1/AnalyticsTest.php
git commit -m "feat: report rejected-payment and forfeited-deposit amounts in analytics"
```

---

## Task 5: Frontend types — Payment rejection fields + Job.cancellation_reason

**Files:**
- Modify: `sutura-client/src/components/jobs/jobTypes.ts`

**Interfaces:**
- Produces: `Payment.rejected_at`, `Payment.rejected_reason`, `Payment.rejected_by`; `Job.cancellation_reason`. Tasks 6-8 depend on these fields existing on the types.

- [ ] **Step 1: Add fields to the Payment interface**

In `sutura-client/src/components/jobs/jobTypes.ts`, modify the `Payment` interface (add three fields after `recorded_by?: { name: string; id: number };`):

```typescript
export interface Payment {
  id: number;
  amount: string | number;
  payment_method: string;
  reference?: string | null;
  receipt_path?: string | null;
  created_at: string;
  notes?: string;
  recorded_by?: { name: string; id: number };
  rejected_at?: string | null;
  rejected_reason?: string | null;
  rejected_by?: { name: string; id: number } | null;
}
```

- [ ] **Step 2: Add cancellation_reason to the Job interface**

In the same file, add one field to `Job` (right after `rejection_reason?: string | null;`):

```typescript
  rejection_reason?: string | null;
  cancellation_reason?: string | null;
```

- [ ] **Step 3: Verify with the TypeScript compiler**

Run: `cd sutura-client && npx tsc --noEmit`
Expected: no new errors (these are additive optional fields, so nothing currently consuming `Payment`/`Job` breaks).

- [ ] **Step 4: Commit**

```bash
git add src/components/jobs/jobTypes.ts
git commit -m "feat: add payment rejection and cancellation_reason fields to frontend types"
```

---

## Task 6: useJobDetail — reject-payment handler + cancellation reason wiring

**Files:**
- Modify: `sutura-client/src/components/jobs/useJobDetail.ts`

**Interfaces:**
- Consumes: `Payment`/`Job` types (Task 5); existing `POST /shops/{shop}/jobs/{jobId}/payments/{paymentId}/reject` endpoint (Task 3).
- Produces: `handleRejectPayment(paymentId: number, reason: string): Promise<void>`; `cancellationReason: string`, `setCancellationReason: (value: string) => void` (new state). Task 7 consumes `handleRejectPayment`; Task 8 consumes `cancellationReason`/`setCancellationReason`.

- [ ] **Step 1: Add cancellationReason state**

In `sutura-client/src/components/jobs/useJobDetail.ts`, add this state declaration right after `const [status, setStatus] = useState('');`:

```typescript
  const [cancellationReason, setCancellationReason] = useState('');
```

- [ ] **Step 2: Include cancellation_reason in the handleUpdate payload**

In `handleUpdate()`, modify the `api.put(...)` call to include the new field:

```typescript
      await api.put(`/shops/${shop.id}/jobs/${jobId}`, {
        status,
        payment_status: paymentStatus,
        balance: Number.parseFloat(balance),
        notes,
        is_outsourced: isOutsourced,
        partner_shop_name: isOutsourced ? partnerShopName : null,
        outsourcing_cost: isOutsourced && outsourcingCost ? Number.parseFloat(outsourcingCost) : null,
        completion_photo_url: completionPhotoUrl || null,
        cancellation_reason: status === 'cancelled' ? cancellationReason : undefined,
      });
```

- [ ] **Step 3: Add handleRejectPayment**

Add this new function right after `handleUpdatePayment` (before `handleDelete`):

```typescript
  // Marks a specific payment as fake/bad, discovered after the fact —
  // reverses balance/payment_status server-side even if the job is already
  // completed. See JobOrderController::rejectPayment.
  const handleRejectPayment = async (paymentId: number, reason: string) => {
    if (!shop || !job) return;
    setSaving(true);
    try {
      await api.post(`/shops/${shop.id}/jobs/${job.id}/payments/${paymentId}/reject`, {
        reason,
      });
      const res = await api.get(`/shops/${shop.id}/jobs/${job.id}`);
      const updatedJob = res.data.data;
      setJob(updatedJob);
      setBalance(updatedJob.balance);
      setPaymentStatus(updatedJob.payment_status);
      toast.success('Payment rejected — balance updated.');
    } catch (err: unknown) {
      const error = err as { response?: { data?: { message?: string } } };
      toast.error(error.response?.data?.message || 'Failed to reject payment.');
      throw err;
    } finally {
      setSaving(false);
    }
  };
```

- [ ] **Step 4: Export the new state and handler**

Find the hook's return statement (where `handleChargePayment, handleApplyDiscount, handleUpdatePayment,` are listed) and add the new exports:

```typescript
    handleChargePayment,
    handleApplyDiscount,
    handleUpdatePayment,
    handleRejectPayment,
    cancellationReason,
    setCancellationReason,
```

- [ ] **Step 5: Verify with the TypeScript compiler**

Run: `cd sutura-client && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/components/jobs/useJobDetail.ts
git commit -m "feat: add handleRejectPayment and cancellationReason state to useJobDetail"
```

---

## Task 7: JobFinancialsCard — reject action + rejected-row rendering

**Files:**
- Modify: `sutura-client/src/components/jobs/JobFinancialsCard.tsx`
- Modify: `sutura-client/src/app/dashboard/jobs/[id]/page.tsx`

**Interfaces:**
- Consumes: `handleRejectPayment` (Task 6); `Payment.rejected_at`/`rejected_reason` (Task 5).
- Produces: `JobFinancialsCard` gains a required `onRejectPayment: (paymentId: number, reason: string) => Promise<void>` prop.

- [ ] **Step 1: Wire the new prop through from the page**

In `sutura-client/src/app/dashboard/jobs/[id]/page.tsx`, find where `handleRejectPayment` is destructured from `useJobDetail` (add it next to the existing `handleUpdatePayment,` destructure at line 49), and find the `<JobFinancialsCard ... onUpdatePayment={handleUpdatePayment} />` usage (around line 429) — add a new prop:

```tsx
              onUpdatePayment={handleUpdatePayment}
              onRejectPayment={handleRejectPayment}
```

- [ ] **Step 2: Add the prop and confirmation state to JobFinancialsCard**

In `sutura-client/src/components/jobs/JobFinancialsCard.tsx`:

Add to the imports (the `lucide-react` import line):

```typescript
import { CreditCard, Banknote, Smartphone, ChevronDown, ChevronUp, Pencil, X, Check, Printer, Tag, MoreVertical, Flag } from 'lucide-react';
```

Add to `JobFinancialsCardProps`:

```typescript
  readonly onRejectPayment: (paymentId: number, reason: string) => Promise<void>;
```

Add new state (alongside the existing `editingPaymentId` state):

```typescript
  const [menuOpenPaymentId, setMenuOpenPaymentId] = useState<number | null>(null);
  const [rejectingPaymentId, setRejectingPaymentId] = useState<number | null>(null);
  const [rejectReason, setRejectReason] = useState('');
  const [submittingReject, setSubmittingReject] = useState(false);

  const handleSubmitReject = async (paymentId: number) => {
    if (!rejectReason.trim()) return;
    setSubmittingReject(true);
    try {
      await onRejectPayment(paymentId, rejectReason.trim());
      setRejectingPaymentId(null);
      setRejectReason('');
    } catch {
      // handled by parent
    } finally {
      setSubmittingReject(false);
    }
  };
```

- [ ] **Step 3: Replace the payment row's action icons with a kebab menu**

In the Payment Ledger section, find this block:

```tsx
                      {editingPaymentId !== payment.id && !jobIsCompleted && (
                        <button
                          type="button"
                          onClick={() => startEditingPayment(payment)}
                          title="Edit method/reference/notes (amount is locked)"
                          className="text-[#A8A19A] hover:text-[#9A8073] transition-colors"
                        >
                          <Pencil size={12} />
                        </button>
                      )}
```

Replace it with a kebab menu that holds both Edit and Reject (nothing destructive sits one accidental tap away — this was the reviewed and approved placement):

```tsx
                      {!payment.rejected_at && (
                        <div className="relative">
                          <button
                            type="button"
                            onClick={() => setMenuOpenPaymentId(p => (p === payment.id ? null : payment.id))}
                            title="More actions"
                            className="text-[#A8A19A] hover:text-[#9A8073] transition-colors"
                          >
                            <MoreVertical size={13} />
                          </button>
                          {menuOpenPaymentId === payment.id && (
                            <div className="absolute right-0 top-5 bg-white border border-[#EBE6E0] rounded-lg shadow-lg min-w-[160px] z-10 overflow-hidden">
                              {!jobIsCompleted && (
                                <button
                                  type="button"
                                  onClick={() => { startEditingPayment(payment); setMenuOpenPaymentId(null); }}
                                  className="w-full text-left px-3 py-2 text-xs text-[#524A44] hover:bg-[#FAF6F3] flex items-center gap-1.5"
                                >
                                  <Pencil size={12} /> Edit details
                                </button>
                              )}
                              <button
                                type="button"
                                onClick={() => { setRejectingPaymentId(payment.id); setMenuOpenPaymentId(null); }}
                                className="w-full text-left px-3 py-2 text-xs text-rose-700 hover:bg-rose-50 flex items-center gap-1.5"
                              >
                                <Flag size={12} /> Reject payment…
                              </button>
                            </div>
                          )}
                        </div>
                      )}
```

- [ ] **Step 4: Add the reject-reason inline form and the rejected-state rendering**

Find the payment row's closing structure — the block starting `{editingPaymentId === payment.id ? (` and ending with its matching `)}`. Add a new conditional branch right after that whole block (still inside the `<div key={payment.id} ...>` wrapper, before its closing `</div>`):

```tsx
                  {rejectingPaymentId === payment.id && (
                    <div className="space-y-1.5 mt-2 pt-2 border-t border-rose-200">
                      <input
                        type="text"
                        value={rejectReason}
                        onChange={e => setRejectReason(e.target.value)}
                        placeholder="Reason — e.g. GCash reference doesn't match our records"
                        className="w-full px-2 py-1.5 bg-white border border-rose-200 rounded-lg text-xs text-[#2D2A26] focus:outline-none focus:border-rose-400"
                      />
                      <div className="flex justify-end gap-2 pt-0.5">
                        <button
                          type="button"
                          onClick={() => { setRejectingPaymentId(null); setRejectReason(''); }}
                          className="px-2 py-1 rounded-lg text-[10px] font-medium text-[#827A73] hover:text-[#2D2A26]"
                        >
                          Cancel
                        </button>
                        <button
                          type="button"
                          disabled={submittingReject || !rejectReason.trim()}
                          onClick={() => handleSubmitReject(payment.id)}
                          className="px-2.5 py-1 rounded-lg text-[10px] font-semibold bg-rose-600 hover:bg-rose-700 text-white disabled:opacity-50"
                        >
                          {submittingReject ? 'Rejecting…' : 'Confirm Reject'}
                        </button>
                      </div>
                    </div>
                  )}

                  {payment.rejected_at && (
                    <div className="mt-2 pt-2 border-t border-rose-200 text-[10px] text-rose-600 space-y-0.5">
                      <p className="font-semibold">
                        Rejected {new Date(payment.rejected_at).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })}
                        {payment.rejected_by && ` by ${payment.rejected_by.name}`}
                      </p>
                      {payment.rejected_reason && <p className="italic">{payment.rejected_reason}</p>}
                    </div>
                  )}
```

- [ ] **Step 5: Show the amount/method struck through once rejected**

Find the payment row's method label and amount display:

```tsx
                      <span className="text-[10px] font-semibold uppercase tracking-wider text-[#827A73]">
                        {METHOD_LABELS[payment.payment_method] ?? payment.payment_method}
                      </span>
```

and

```tsx
                      <span className="text-sm font-bold text-[#2D2A26]">
                        ₱{Number.parseFloat(String(payment.amount)).toFixed(2)}
                      </span>
```

Replace both with conditional styling plus a "Rejected" badge:

```tsx
                      <span className={`text-[10px] font-semibold uppercase tracking-wider ${payment.rejected_at ? 'line-through text-[#A8A19A]' : 'text-[#827A73]'}`}>
                        {METHOD_LABELS[payment.payment_method] ?? payment.payment_method}
                      </span>
                      {payment.rejected_at && (
                        <span className="text-[9px] font-bold uppercase tracking-wider bg-rose-100 text-rose-600 border border-rose-200 px-1.5 py-0.5 rounded-full">
                          Rejected
                        </span>
                      )}
```

and

```tsx
                      <span className={`text-sm font-bold ${payment.rejected_at ? 'line-through text-[#A8A19A]' : 'text-[#2D2A26]'}`}>
                        ₱{Number.parseFloat(String(payment.amount)).toFixed(2)}
                      </span>
```

- [ ] **Step 6: Verify with the TypeScript compiler**

Run: `cd sutura-client && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 7: Manually verify in the browser**

Run: `cd sutura-client && npm run dev`, and in a separate terminal `cd sutura-server && php artisan serve`.

Open a job order detail page that has at least one logged payment. Confirm:
1. Each payment row shows a "⋮" icon instead of separate Edit/Reject icons.
2. Clicking it shows "Edit details" (if job isn't completed) and "Reject payment…".
3. Clicking "Reject payment…" shows the reason input; submitting with an empty reason is disabled; submitting with a reason updates the Balance Due figure at the top of the card and shows the row struck through with a "Rejected" badge and the reason text.

- [ ] **Step 8: Commit**

```bash
git add src/components/jobs/JobFinancialsCard.tsx "src/app/dashboard/jobs/[id]/page.tsx"
git commit -m "feat: add reject-payment action and rejected-state rendering to JobFinancialsCard"
```

---

## Task 8: Cancellation reason modal

**Files:**
- Create: `sutura-client/src/components/jobs/CancellationReasonModal.tsx`
- Modify: `sutura-client/src/components/jobs/JobProductionTimeline.tsx`
- Modify: `sutura-client/src/app/dashboard/jobs/[id]/page.tsx`

**Interfaces:**
- Consumes: `Modal` component (`@/components/Modal`, existing — props `isOpen`, `onClose`, `title`, `children`, `maxWidth?`); `cancellationReason`/`setCancellationReason` (Task 6).
- Produces: `CancellationReasonModal` component with props `isOpen: boolean`, `onClose: () => void`, `onConfirm: (reason: string) => void`, `collectedAmount: number`.

- [ ] **Step 1: Create CancellationReasonModal**

Create `sutura-client/src/components/jobs/CancellationReasonModal.tsx`:

```tsx
import React, { useState } from 'react';
import Modal from '@/components/Modal';

interface CancellationReasonModalProps {
  readonly isOpen: boolean;
  readonly onClose: () => void;
  readonly onConfirm: (reason: string) => void;
  readonly collectedAmount: number;
}

const REASONS: { value: string; title: string; description: string; flagged?: boolean }[] = [
  {
    value: 'customer_request',
    title: 'Customer requested cancellation',
    description: 'No production started, or customer changed their mind early — refund/waive at your discretion, outside the system.',
  },
  {
    value: 'shop_unable_to_fulfill',
    title: 'Shop unable to fulfill',
    description: 'Material shortage, scheduling conflict, or similar shop-side reason.',
  },
  {
    value: 'forfeited_deposit_abandoned',
    title: 'Forfeited deposit — customer went uncontactable',
    description: 'Fabric was already cut, customer never returned. Per shop policy, the deposit already collected is kept, not refunded. This shows up separately in cross-branch loss reporting.',
    flagged: true,
  },
  {
    value: 'other',
    title: 'Other',
    description: "Free-text reason for anything that doesn't fit above.",
  },
];

export default function CancellationReasonModal({ isOpen, onClose, onConfirm, collectedAmount }: CancellationReasonModalProps) {
  const [selected, setSelected] = useState('customer_request');
  const [otherText, setOtherText] = useState('');

  const handleConfirm = () => {
    onConfirm(selected);
    setSelected('customer_request');
    setOtherText('');
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="🚫 Cancel Job Order">
      <div className="space-y-3">
        {collectedAmount > 0 && (
          <div className="bg-red-50 border border-red-200 rounded-lg p-3 text-xs text-red-700">
            ⚠️ This job has ₱{collectedAmount.toFixed(2)} already collected. Choosing a reason below does not
            refund or reverse this automatically — pick the one that matches what actually happened.
          </div>
        )}

        {REASONS.map(reason => (
          <button
            key={reason.value}
            type="button"
            onClick={() => setSelected(reason.value)}
            className={`w-full text-left flex items-start gap-2.5 p-3 rounded-xl border transition-colors ${
              selected === reason.value
                ? (reason.flagged ? 'border-[#B26959] bg-[#B26959]/5' : 'border-taupe bg-[#FAF6F3]')
                : 'border-[#EBE6E0] hover:border-[#D1C7BD]'
            }`}
          >
            <input type="radio" checked={selected === reason.value} readOnly className="mt-0.5 accent-taupe" />
            <div>
              <p className={`text-xs font-semibold ${reason.flagged ? 'text-[#B26959]' : 'text-[#2D2A26]'}`}>{reason.title}</p>
              <p className="text-[10.5px] text-[#A8A19A] mt-0.5">{reason.description}</p>
              {reason.value === 'other' && selected === 'other' && (
                <input
                  type="text"
                  value={otherText}
                  onChange={e => setOtherText(e.target.value)}
                  placeholder="Explain what happened…"
                  className="w-full mt-1.5 px-2.5 py-1.5 border border-[#D1C7BD] rounded-lg text-xs"
                  onClick={e => e.stopPropagation()}
                />
              )}
            </div>
          </button>
        ))}

        <div className="pt-2 border-t border-[#EBE6E0] flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm font-medium text-[#524A44] hover:text-[#2D2A26]">
            Never mind, keep it active
          </button>
          <button
            type="button"
            onClick={handleConfirm}
            className="px-4 py-2 rounded-lg text-sm font-semibold bg-red-600 hover:bg-red-700 text-white"
          >
            Confirm Cancellation
          </button>
        </div>
      </div>
    </Modal>
  );
}
```

- [ ] **Step 2: Wire the modal into JobProductionTimeline**

In `sutura-client/src/components/jobs/JobProductionTimeline.tsx`:

Add the import:

```typescript
import CancellationReasonModal from './CancellationReasonModal';
```

Add a new prop to the component's props interface: `readonly setCancellationReason: (reason: string) => void;` and `readonly collectedAmount: number;`

Add local state right after the component's existing destructured props:

```typescript
  const [showCancelModal, setShowCancelModal] = useState(false);
```

(Add `useState` to the existing React import if not already imported.)

Find the `<select ... onChange={e => setStatus(e.target.value)} ...>` element and replace its `onChange`:

```tsx
          <select
            id="update-production-phase"
            value={status}
            onChange={e => {
              if (e.target.value === 'cancelled') {
                setShowCancelModal(true);
              } else {
                setStatus(e.target.value);
              }
            }}
            className="w-full px-4 py-2 bg-[#FAF6F3] border border-[#EBE6E0] rounded-lg text-[#2D2A26] focus:outline-none focus:border-taupe focus:ring-1 focus:ring-taupe"
          >
```

Add the modal right after the component's closing `</div>` of the outer container — actually, add it as a sibling right before the final `</div>` that closes the whole component's returned JSX:

```tsx
      <CancellationReasonModal
        isOpen={showCancelModal}
        onClose={() => setShowCancelModal(false)}
        collectedAmount={collectedAmount}
        onConfirm={(reason) => {
          setCancellationReason(reason);
          setStatus('cancelled');
          setShowCancelModal(false);
        }}
      />
```

- [ ] **Step 3: Pass the new props from the page**

In `sutura-client/src/app/dashboard/jobs/[id]/page.tsx`, find the `<JobProductionTimeline status={status} setStatus={setStatus} ... />` usage and add:

```tsx
              setCancellationReason={setCancellationReason}
              collectedAmount={Number.parseFloat(String(job.total_amount)) - Number.parseFloat(String(job.balance))}
```

Also destructure `cancellationReason, setCancellationReason,` from the `useJobDetail(...)` call at the top of the component (next to `handleUpdate,` etc.) — `cancellationReason` itself isn't directly used in the page's JSX (it only feeds `handleUpdate`'s payload from inside the hook), so only `setCancellationReason` needs passing down; keep the destructure as `setCancellationReason` to avoid an unused-variable lint warning.

- [ ] **Step 4: Verify with the TypeScript compiler**

Run: `cd sutura-client && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 5: Manually verify in the browser**

With both dev servers running (Task 7, Step 7), open a job order detail page. Change the Production Phase dropdown to "Cancelled" — confirm the modal appears instead of the phase changing immediately, that the warning banner only appears when the job has money collected, that picking a reason and clicking "Confirm Cancellation" updates the dropdown to show Cancelled, and that clicking "Save Changes" (the page's main save button) persists it — reload the page and confirm the status is still `cancelled`.

- [ ] **Step 6: Commit**

```bash
git add src/components/jobs/CancellationReasonModal.tsx src/components/jobs/JobProductionTimeline.tsx "src/app/dashboard/jobs/[id]/page.tsx"
git commit -m "feat: add cancellation reason modal intercepting the Cancelled status option"
```

---

## Task 9: Reports — rejected/forfeited KPI tiles and branch comparison columns

**Files:**
- Modify: `sutura-client/src/components/reports/reportHelpers.tsx`
- Modify: `sutura-client/src/components/reports/ReportKpiCards.tsx`
- Modify: `sutura-client/src/components/reports/BranchComparisonTable.tsx`

**Interfaces:**
- Consumes: `rejected_payments_amount`, `forfeited_deposit_amount` fields from the `/analytics` and `/analytics/branches` responses (Task 4).

- [ ] **Step 1: Add the new fields to AnalyticsData**

In `sutura-client/src/components/reports/reportHelpers.tsx`, add two fields to `AnalyticsData` (after `avg_order_value?: number;`):

```typescript
  rejected_payments_amount?: number;
  forfeited_deposit_amount?: number;
```

- [ ] **Step 2: Add two KPI tiles, blended into the existing grid**

In `sutura-client/src/components/reports/ReportKpiCards.tsx`, add `Flag` and `PackageX` to the `lucide-react` import line:

```typescript
import {
  TrendingUp, Wallet, PackageCheck, Calendar as CalendarIcon,
  Users, Target, DollarSign, AlertTriangle, BarChart2, Flag, PackageX,
} from 'lucide-react';
```

Add two entries to the `kpis` array (after the `'Completed Orders'` entry, before `'Overdue Orders'` — same visual weight as every other tile here, not visually separated, per the reviewed design):

```typescript
    {
      label: 'Rejected Payments',
      value: `₱${Number(data?.rejected_payments_amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`,
      sub: 'Flagged as fake, balance reversed',
      icon: <Flag className="text-[#B26959]" size={20} />,
      color: 'text-[#B26959] bg-[#B26959]/10 border-[#B26959]/20',
    },
    {
      label: 'Forfeited Deposits',
      value: `₱${Number(data?.forfeited_deposit_amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`,
      sub: 'Kept from abandoned orders',
      icon: <PackageX className="text-[#B26959]" size={20} />,
      color: 'text-[#B26959] bg-[#B26959]/10 border-[#B26959]/20',
    },
```

- [ ] **Step 3: Add two columns to BranchComparisonTable**

In `sutura-client/src/components/reports/BranchComparisonTable.tsx`, add two fields to the `BranchPerformance` interface (after `total_outstanding_balance: number;`):

```typescript
  rejected_payments_amount: number;
  forfeited_deposit_amount: number;
```

Add two `<th>` cells to the table header (after `<th className="px-6 py-3 font-medium">Outstanding</th>`):

```tsx
              <th className="px-6 py-3 font-medium text-[#B26959]">Rejected</th>
              <th className="px-6 py-3 font-medium text-[#B26959]">Forfeited</th>
```

Add two `<td>` cells to the table body (after the `Outstanding` `<td>` block that reads `row.total_outstanding_balance`):

```tsx
                <td className="px-6 py-3">
                  {row.rejected_payments_amount > 0 ? (
                    <span className="text-[#B26959] font-semibold">
                      ₱{row.rejected_payments_amount.toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                    </span>
                  ) : (
                    <span className="text-[#A8A19A]">₱0.00</span>
                  )}
                </td>
                <td className="px-6 py-3">
                  {row.forfeited_deposit_amount > 0 ? (
                    <span className="text-[#B26959] font-semibold">
                      ₱{row.forfeited_deposit_amount.toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                    </span>
                  ) : (
                    <span className="text-[#A8A19A]">₱0.00</span>
                  )}
                </td>
```

- [ ] **Step 4: Verify with the TypeScript compiler**

Run: `cd sutura-client && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 5: Manually verify in the browser**

Open the Reports page (`/dashboard/reports`). Confirm the two new tiles render among the existing nine with the same visual weight, and the Branch Performance Comparison table shows the two new columns. If no rejected/forfeited data exists yet in local seed data, values should read ₱0.00, not error or blank.

- [ ] **Step 6: Commit**

```bash
git add src/components/reports/reportHelpers.tsx src/components/reports/ReportKpiCards.tsx src/components/reports/BranchComparisonTable.tsx
git commit -m "feat: surface rejected-payment and forfeited-deposit figures in Reports"
```
