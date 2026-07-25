# Job Order Approval/Rejection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a shop_owner/branch_manager decline a job order while it's still `pending` (before any production starts), with a free-text reason, and fix an unrelated regression this uncovered — the Job Detail page's existing "Cancellation Reason" panel silently stopped working after the recent cancellation-reason feature shipped.

**Architecture:** Backend (Laravel, `sutura-server`): one new terminal status value, one new endpoint mirroring the existing `pay()`/`applyDiscount()`/`rejectPayment()` role-gate pattern exactly, reusing the already-existing `rejection_reason` column (no migration needed). Frontend (Next.js, `sutura-client`): fix the orphaned reason panel to read the right field for each terminal status, add a matching panel for the new `rejected` status, and add the Decline action itself.

**Tech Stack:** Laravel 11 (PHPUnit), Next.js/React/TypeScript (no frontend test runner — verify via `npx tsc --noEmit` and manual browser check).

## Global Constraints

- Money/business-decision actions on `JobOrder` are gated `role:shop_owner,branch_manager` (never plain `staff`) — this repo's established convention, followed by `pay()`, `applyDiscount()`, `rejectPayment()`, and now this endpoint.
- `rejected` is reachable only from `status === 'pending'`. A job already in production is cancelled (existing `cancellation_reason` flow), never rejected.
- No enumerated rejection-reason categories — free text only, deliberately, since real reasons vary shop to shop.
- No customer-facing exposure of the specific reason text — the existing generic `JobStatusUpdatedNotification` already covers telling the customer the status changed; nothing new should surface the reason itself outside the owner/branch_manager dashboard.
- No new migration — both `job_orders.status` (plain string column) and `job_orders.rejection_reason` (added 2026-07-08) already exist.
- Spec reference: `sutura-server/docs/superpowers/specs/2026-07-25-job-order-approval-rejection-design.md`.

---

## Task 1: Backend — reject endpoint

**Files:**
- Modify: `app/Models/JobOrder.php`
- Modify: `app/Http/Controllers/Api/V1/JobOrderController.php`
- Modify: `app/Notifications/JobStatusUpdatedNotification.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/V1/JobOrderTest.php`

**Interfaces:**
- Produces: `JobOrder::STATUSES` gains `'rejected'`. `JobOrderController::rejectOrder(Request $request, Shop $shop, JobOrder $jobOrder): JsonResponse`, reachable at `POST /api/v1/shops/{shop}/jobs/{jobOrder}/reject`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Api/V1/JobOrderTest.php`:

```php
    public function test_owner_can_reject_a_pending_job_order()
    {
        $jobOrder = \App\Models\JobOrder::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'order_number' => 'JO-2026-9020',
            'total_amount' => 5000,
            'balance' => 5000,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/shops/{$this->shop->id}/jobs/{$jobOrder->id}/reject",
            ['reason' => "We don't carry the fabric this order needs."]
        );

        $response->assertStatus(200)->assertJsonPath('success', true);

        $this->assertDatabaseHas('job_orders', [
            'id' => $jobOrder->id,
            'status' => 'rejected',
            'rejection_reason' => "We don't carry the fabric this order needs.",
        ]);
    }

    public function test_cannot_reject_a_job_order_already_in_production()
    {
        $jobOrder = \App\Models\JobOrder::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'order_number' => 'JO-2026-9021',
            'total_amount' => 5000,
            'balance' => 5000,
            'status' => 'cutting',
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/shops/{$this->shop->id}/jobs/{$jobOrder->id}/reject",
            ['reason' => 'Changed my mind.']
        );

        $response->assertStatus(422);
        $this->assertDatabaseHas('job_orders', [
            'id' => $jobOrder->id,
            'status' => 'cutting',
        ]);
    }

    public function test_staff_cannot_reject_a_job_order()
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
            'order_number' => 'JO-2026-9022',
            'total_amount' => 5000,
            'balance' => 5000,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($staffUser)->postJson(
            "/api/v1/shops/{$this->shop->id}/jobs/{$jobOrder->id}/reject",
            ['reason' => 'test']
        );

        $response->assertStatus(403);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=test_owner_can_reject_a_pending_job_order`
Run: `php artisan test --filter=test_cannot_reject_a_job_order_already_in_production`
Run: `php artisan test --filter=test_staff_cannot_reject_a_job_order`
Expected: all three FAIL — the route doesn't exist yet (404).

- [ ] **Step 3: Add `'rejected'` to the STATUSES constant**

In `app/Models/JobOrder.php`, modify the existing constant:

```php
    public const STATUSES = [
        'pending', 'design', 'pattern_making', 'mass_cutting_printing', 'cutting', 'sewing',
        'ready_for_fitting', 'final_adjustments', 'qc_ironing', 'ready_for_pickup',
        'completed', 'cancelled', 'rejected',
    ];
```

- [ ] **Step 4: Add `rejectOrder()` to the controller**

In `app/Http/Controllers/Api/V1/JobOrderController.php`, add this method (place it after `rejectPayment()`, before `assignStaff()`):

```php
    /**
     * Declines a job order before any production has started — a business
     * decision (feasibility, capacity, fabric availability), not a
     * production task, so plain staff can't do this any more than they can
     * touch payments. Only reachable from 'pending': once real work has
     * started, cancel the job instead (see UpdateJobOrderRequest's
     * cancellation_reason flow).
     */
    public function rejectOrder(Request $request, Shop $shop, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        if ($jobOrder->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only a pending order can be rejected — cancel it instead.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        $jobOrder->update([
            'status' => 'rejected',
            'rejection_reason' => $validated['reason'],
        ]);

        $jobOrder->customer->notify(new \App\Notifications\JobStatusUpdatedNotification($jobOrder, 'rejected'));

        return response()->json([
            'success' => true,
            'message' => 'Job order rejected.',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff'])
        ]);
    }
```

Note: this explicitly sends the notification itself, rather than relying on `update()`'s own notification logic — `rejectOrder()` doesn't go through `JobOrderController::update()` at all, so it needs its own call.

- [ ] **Step 5: Fix `JobStatusUpdatedNotification` — add a real `rejected` entry, remove a dead-and-wrong reason leak from `cancelled`**

`app/Notifications/JobStatusUpdatedNotification.php`'s `titles()`, `messages()`, and `toArray()`'s implicit key set are hardcoded arrays with no `'rejected'` entry — calling this notification with `'rejected'` today would silently fall through to the generic `?? 'Order Update'` fallback instead of a real message. Separately, the existing `'cancelled'` entry in `messages()` currently reads:

```php
            'cancelled'              => 'Your order (' . $this->jobOrder->order_number . ') has been cancelled.'
                . ($this->jobOrder->rejection_reason ? ' Reason: ' . $this->jobOrder->rejection_reason : ''),
```

This predates the cancellation-reason feature (already shipped this session) and is currently silently dead — nothing sets `rejection_reason` on cancellation anymore (that field, went to `cancellation_reason` instead). But it's still wrong code: even dead, it directly contradicts the explicit, already-approved design principle from that feature's spec — *"No automated message should ever say... 'you forfeited your deposit'... that's an accusation the owner should make directly, not something a notification should send automatically."* The same reasoning applies to rejection. Fix both in one pass:

In `app/Notifications/JobStatusUpdatedNotification.php`, in `titles()`, add (after the `'cancelled'` entry):

```php
            'rejected'               => 'Order Declined',
```

In `messages()`, replace the existing `'cancelled'` entry (removing the reason leak) and add a `'rejected'` entry with the same no-reason-exposed shape:

```php
            'cancelled'              => 'Your order (' . $this->jobOrder->order_number . ') has been cancelled.',
            'rejected'               => 'Your order (' . $this->jobOrder->order_number . ') could not be accepted. Please reach out to the shop directly for details.',
```

Also update the docblock comment on the `$status` property (currently lists the valid values in a trailing comment) to include `'rejected'`, and the class-level docblock comment ("Fires on every production-stage transition EXCEPT ready_for_pickup") doesn't need wording changes — `'cancelled'` and `'rejected'` aren't production stages but were/are already included in the same type maps, so no contradiction.

- [ ] **Step 6: Add the route**

In `routes/api.php`, inside the same `Route::middleware('role:shop_owner,branch_manager')->group(function () {` block that already contains the `reject` route for payments, add:

```php
                Route::post('/jobs/{jobOrder}/reject', [JobOrderController::class, 'rejectOrder']);
```

- [ ] **Step 7: Add a notification-content test**

Add to `tests/Feature/Api/V1/JobOrderTest.php` (add `use Illuminate\Support\Facades\Notification;` to this file's imports if not already present from an earlier task):

```php
    public function test_rejecting_a_job_order_notifies_customer_without_leaking_the_reason()
    {
        Notification::fake();

        $jobOrder = \App\Models\JobOrder::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'order_number' => 'JO-2026-9023',
            'total_amount' => 5000,
            'balance' => 5000,
            'status' => 'pending',
        ]);

        $this->actingAs($this->user)->postJson(
            "/api/v1/shops/{$this->shop->id}/jobs/{$jobOrder->id}/reject",
            ['reason' => 'Fabric they want is not something we carry.']
        );

        Notification::assertSentTo(
            $this->customer,
            \App\Notifications\JobStatusUpdatedNotification::class,
            function ($notification) {
                $array = $notification->toArray($this->customer);
                return $array['title'] === 'Order Declined'
                    && !str_contains($array['message'], 'Fabric they want is not something we carry.');
            }
        );
    }
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test --filter=test_owner_can_reject_a_pending_job_order`
Run: `php artisan test --filter=test_cannot_reject_a_job_order_already_in_production`
Run: `php artisan test --filter=test_staff_cannot_reject_a_job_order`
Run: `php artisan test --filter=test_rejecting_a_job_order_notifies_customer_without_leaking_the_reason`
Expected: all four PASS.

- [ ] **Step 9: Run the full JobOrder test suite for regressions**

Run: `php artisan test tests/Feature/Api/V1/JobOrderTest.php`
Expected: all tests PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Models/JobOrder.php app/Http/Controllers/Api/V1/JobOrderController.php app/Notifications/JobStatusUpdatedNotification.php routes/api.php tests/Feature/Api/V1/JobOrderTest.php
git commit -m "feat: add job order rejection for pending orders"
```

---

## Task 2: Frontend — fix the orphaned cancellation-reason panel, add a rejected-order panel

**Files:**
- Modify: `sutura-client/src/components/jobs/jobHelpers.tsx`
- Modify: `sutura-client/src/app/dashboard/jobs/[id]/page.tsx`

**Interfaces:**
- Produces: `CANCELLATION_REASON_LABELS: Record<string, string>` exported from `jobHelpers.tsx`, reused by both this task's display fix and (already) `CancellationReasonModal.tsx`'s own reason list conceptually (no need to refactor that file — just don't let the label text drift out of sync; copy it verbatim from `CancellationReasonModal.tsx`'s existing `REASONS` array).

**Context:** `page.tsx` currently has a "Cancellation Reason" panel (search for `{job.status === 'cancelled' && job.rejection_reason && (`) that reads `job.rejection_reason` — but the cancellation-reason feature (already shipped) writes to `job.cancellation_reason` instead, so this panel has been silently dead for every job cancelled since that feature merged. This task fixes that regression and extends the same pattern to the new `rejected` status from Task 1.

- [ ] **Step 1: Add the label map to jobHelpers.tsx**

Add this export to `sutura-client/src/components/jobs/jobHelpers.tsx` (values copied verbatim from `CancellationReasonModal.tsx`'s `REASONS` array titles, so the two never drift apart):

```typescript
export const CANCELLATION_REASON_LABELS: Record<string, string> = {
  customer_request: 'Customer requested cancellation',
  shop_unable_to_fulfill: 'Shop unable to fulfill',
  forfeited_deposit_abandoned: 'Forfeited deposit — customer went uncontactable',
  other: 'Other',
};
```

- [ ] **Step 2: Fix the existing panel and add the rejected-order panel**

In `sutura-client/src/app/dashboard/jobs/[id]/page.tsx`, find:

```tsx
        {/* Cancellation Reason */}
        {job.status === 'cancelled' && job.rejection_reason && (
          <div className="bg-[#B26959]/10 border border-[#B26959]/25 rounded-2xl px-5 py-3 flex items-start gap-3">
            <X size={15} className="text-[#B26959] shrink-0 mt-0.5" />
            <div>
              <p className="text-[#9A5C4F] text-sm font-semibold">This order was cancelled</p>
              <p className="text-[#9A5C4F]/80 text-xs mt-0.5">Reason: {job.rejection_reason}</p>
            </div>
          </div>
        )}
```

Replace it with:

```tsx
        {/* Cancellation Reason */}
        {job.status === 'cancelled' && job.cancellation_reason && (
          <div className="bg-[#B26959]/10 border border-[#B26959]/25 rounded-2xl px-5 py-3 flex items-start gap-3">
            <X size={15} className="text-[#B26959] shrink-0 mt-0.5" />
            <div>
              <p className="text-[#9A5C4F] text-sm font-semibold">This order was cancelled</p>
              <p className="text-[#9A5C4F]/80 text-xs mt-0.5">
                Reason: {CANCELLATION_REASON_LABELS[job.cancellation_reason] ?? job.cancellation_reason}
              </p>
            </div>
          </div>
        )}

        {/* Rejection Reason */}
        {job.status === 'rejected' && job.rejection_reason && (
          <div className="bg-[#B26959]/10 border border-[#B26959]/25 rounded-2xl px-5 py-3 flex items-start gap-3">
            <X size={15} className="text-[#B26959] shrink-0 mt-0.5" />
            <div>
              <p className="text-[#9A5C4F] text-sm font-semibold">This order was rejected</p>
              <p className="text-[#9A5C4F]/80 text-xs mt-0.5">Reason: {job.rejection_reason}</p>
            </div>
          </div>
        )}
```

Add the import for `CANCELLATION_REASON_LABELS` from `jobHelpers.tsx` to this file's existing imports (check what's already imported from that file in this component and add to the same import line rather than a new one, if one already exists — if `jobHelpers` isn't currently imported in this file at all, add a new import line).

- [ ] **Step 3: Verify with the TypeScript compiler**

Run: `cd sutura-client && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 4: Manually verify in the browser**

With dev servers running: open a job order that has `status: 'cancelled'` and a real `cancellation_reason` value set — confirm the panel now shows the human-readable label (e.g. "Forfeited deposit — customer went uncontactable"), not the raw enum string, and not blank. This directly verifies the regression fix.

- [ ] **Step 5: Commit**

```bash
git add src/components/jobs/jobHelpers.tsx "src/app/dashboard/jobs/[id]/page.tsx"
git commit -m "fix: cancellation-reason panel reads cancellation_reason instead of orphaned rejection_reason, add matching panel for rejected orders"
```

---

## Task 3: Frontend — Decline action

**Files:**
- Modify: `sutura-client/src/components/jobs/useJobDetail.ts`
- Modify: `sutura-client/src/app/dashboard/jobs/[id]/page.tsx`

**Interfaces:**
- Produces: `handleRejectOrder(reason: string): Promise<void>` from `useJobDetail`.

- [ ] **Step 1: Add the handler to useJobDetail.ts**

Add this function to `sutura-client/src/components/jobs/useJobDetail.ts`, right after `handleRejectPayment` (matching its exact error-handling shape — `setSaving`, try/catch with toast, re-throw, finally):

```typescript
  // Declines a job order before production starts — a business decision
  // (feasibility/capacity/fabric availability), gated shop_owner/branch_manager
  // server-side, only valid while status is still 'pending'.
  const handleRejectOrder = async (reason: string) => {
    if (!shop || !job) return;
    setSaving(true);
    try {
      await api.post(`/shops/${shop.id}/jobs/${job.id}/reject`, { reason });
      const res = await api.get(`/shops/${shop.id}/jobs/${job.id}`);
      const updatedJob = res.data.data;
      setJob(updatedJob);
      setStatus(updatedJob.status);
      toast.success('Job order rejected.');
    } catch (err: unknown) {
      const error = err as { response?: { data?: { message?: string } } };
      toast.error(error.response?.data?.message || 'Failed to reject job order.');
      throw err;
    } finally {
      setSaving(false);
    }
  };
```

Add `handleRejectOrder` to the hook's return statement, alongside `handleRejectPayment`.

- [ ] **Step 2: Add the Decline button + inline form to the page**

In `sutura-client/src/app/dashboard/jobs/[id]/page.tsx`:

Destructure `handleRejectOrder` from the `useJobDetail(...)` call, alongside the other handlers already destructured there.

Add local state for the inline reveal (near the top of the component, alongside other `useState` calls already in this file):

```typescript
  const [showRejectOrderForm, setShowRejectOrderForm] = useState(false);
  const [rejectOrderReason, setRejectOrderReason] = useState('');
  const [rejectingOrder, setRejectingOrder] = useState(false);
```

Add the Decline button + inline form, placed directly after the "Rejection Reason" panel block added in Task 2 (so it sits in the same visual area, right below the job's status-related banners, before the two-column `Job Details`/`Financials` grid). Only renders while the job is still pending, and only for shop_owner/branch_manager — reuse whatever existing role-check pattern this page/its parent layout already uses to distinguish those roles from staff (check how the page currently hides other owner/branch_manager-only actions, e.g. the Delete button or the discount action, and match that exact pattern rather than inventing a new one):

```tsx
        {job.status === 'pending' && (
          <div className="bg-white shadow-sm border border-[#EBE6E0] rounded-2xl p-5">
            {showRejectOrderForm ? (
              <div className="space-y-2.5">
                <p className="text-xs font-semibold text-rose-700 uppercase tracking-wider">Decline this order</p>
                <input
                  type="text"
                  value={rejectOrderReason}
                  onChange={e => setRejectOrderReason(e.target.value)}
                  placeholder="Why are you declining this order?"
                  className="w-full px-3 py-2 bg-white border border-rose-200 rounded-lg text-sm text-[#2D2A26] focus:outline-none focus:border-rose-400"
                />
                <div className="flex justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => { setShowRejectOrderForm(false); setRejectOrderReason(''); }}
                    className="px-3 py-1.5 rounded-lg text-xs font-medium text-[#827A73] hover:text-[#2D2A26]"
                  >
                    Cancel
                  </button>
                  <button
                    type="button"
                    disabled={rejectingOrder || !rejectOrderReason.trim()}
                    onClick={async () => {
                      setRejectingOrder(true);
                      try {
                        await handleRejectOrder(rejectOrderReason.trim());
                        setShowRejectOrderForm(false);
                        setRejectOrderReason('');
                      } catch {
                        // handled by parent
                      } finally {
                        setRejectingOrder(false);
                      }
                    }}
                    className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-rose-600 hover:bg-rose-700 text-white disabled:opacity-50"
                  >
                    {rejectingOrder ? 'Declining…' : 'Confirm Decline'}
                  </button>
                </div>
              </div>
            ) : (
              <button
                type="button"
                onClick={() => setShowRejectOrderForm(true)}
                className="w-full py-2 border border-rose-200 text-rose-700 text-xs font-semibold rounded-lg hover:bg-rose-50 transition-colors"
              >
                Decline Order
              </button>
            )}
          </div>
        )}
```

If this page's existing role-gating pattern for owner/branch_manager-only actions requires wrapping this whole block in an additional condition (e.g. a `canManageFinancials`-style boolean already computed elsewhere in this file), add that wrapper — check the file for how it currently hides other similarly-gated actions before finalizing this block, and match that exact approach rather than leaving it visible to all roles.

- [ ] **Step 3: Verify with the TypeScript compiler**

Run: `cd sutura-client && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 4: Manually verify in the browser**

Open a `pending` job order. Confirm the "Decline Order" button appears (for owner/branch_manager), clicking it reveals the reason input, submitting with an empty reason is disabled, and submitting with a reason updates the job's status to "Rejected" and shows the new rejection panel from Task 2. Also confirm the button does NOT appear once a job has moved past `pending` (e.g. `design` or later).

- [ ] **Step 5: Commit**

```bash
git add src/components/jobs/useJobDetail.ts "src/app/dashboard/jobs/[id]/page.tsx"
git commit -m "feat: add Decline Order action for pending job orders"
```
