# Payment Rejection & Forfeited-Deposit Tracking — Design

## Context

`Appointment.payment_status` already supports `pending|paid|rejected` — a customer books
online, uploads a receipt screenshot via the public `upload-receipt` route, and staff can
later mark it `rejected` via `AppointmentController::verifyPayment`. `JobOrder.payment_status`
only supports `unpaid|partial|paid` — there is no way to flag a job-order payment as fake,
and no way to distinguish a normal cancellation from one where a legitimate downpayment was
forfeited because the customer went uncontactable after fabric was cut.

Both gaps are in-scope (payment/order status tracking is explicitly part of SUTURA's approved
scope — this is not payment gateway integration, just verification/status tracking of
manually-recorded payments, which the system already partially does via `Payment.receipt_path`
and `JobOrderController::pay()`).

### Why this isn't just "copy the Appointment pattern"

`Appointment`'s flow exists because it has a genuine submit-then-review gate: a customer
self-submits a receipt online, and the appointment sits in `pending` until staff reviews it.
`JobOrder` has no equivalent. `JobOrderController::pay()` is always staff/owner-initiated and
immediate — a staff member enters an amount and the balance updates right then, because the
shop needs to keep operating even when the owner isn't physically present to review a receipt
in real time. There is also no customer-facing job-order payment route at all.

So for `JobOrder`, "payment rejected" is a **correction discovered after the fact** (a bad or
faked receipt found during a later review), not a pre-confirmation gate. This changes the
design:

- A specific `Payment` row needs to be identifiable as rejected (a job can have many payments
  over time; this is not a single flag on the whole job).
- Rejecting must reverse the ledger (`balance`/`payment_status`), even if the job has already
  reached `completed` — the existing "no balance, no claim" rule in
  `JobOrderController::update()` only blocks *advancing to* `completed` with a balance owed; it
  does not forbid a completed job from later having a balance again. That state becoming
  possible is the intended effect: it's how a fraud discovered after the garment was already
  handed over becomes visible in the shop's own books and in cross-branch reporting.
- No software fix can prevent a well-forged receipt from being accepted in the moment — staff
  aren't forensic document examiners. This feature is about honest bookkeeping and visibility
  after the fact, not prevention.

There is a second, unrelated scenario that surfaced during discussion: a customer pays a
legitimate downpayment, fabric gets cut, and the customer becomes uncontactable before paying
the balance or picking up. Per the shop's own policy (documented in the interview research),
the deposit is forfeited — no refund. Nothing here is fraudulent and nothing needs reversing;
the shop legitimately keeps the money. This needs a *reason/category* on the cancellation, not
a payment reversal, and is tracked separately from payment rejection.

## Design

### 1. Payment rejection (fraud discovered after the fact)

- `payments` table gains three nullable columns: `rejected_at`, `rejected_reason`,
  `rejected_by` (FK to `users`). No separate status enum — "rejected" is
  `rejected_at !== null`, matching the existing `job_order_staff.completed_at` convention
  already used elsewhere in this codebase for the same kind of two-state field.
- New endpoint: `POST /shops/{shop}/job-orders/{jobOrder}/payments/{payment}/reject`.
  - Role gate: `role:shop_owner,branch_manager` (same as `pay()`, `applyDiscount()`,
    `updatePayment()`, `assignStaff()` — every other money-touching JobOrder action already
    uses this gate). Plain `staff` is excluded, consistent with this codebase's documented
    role boundary ("staff... cannot... see owner-only financials") and with
    `AnalyticsController::branchComparison()`'s existing "owner-level strategic view, not
    exposed to branch managers [or below]" pattern that this feature's reporting plugs into.
  - Plus the existing `branchAccessDenied()` check (branch_manager scoped to their own branch;
    shop_owner unrestricted).
  - Body: `{ reason: required|string|max:1000 }`.
  - Guards: the payment must belong to the given job/shop; a payment that's already rejected
    cannot be rejected again (idempotency).
- Inside a locking transaction (same pattern as `pay()`/`applyDiscount()` — lock the `JobOrder`
  row for the duration):
  1. Add the payment's `amount` back to `balance`.
  2. Recompute `payment_status`: `paid` if the new balance is `<= 0`, else `partial` if any
     other non-rejected payments exist on the job, else `unpaid`.
  3. Stamp `rejected_at`/`rejected_reason`/`rejected_by` on the `Payment` row.
  4. Write an `AuditLog` entry (`action: 'payment_rejected'`), same pattern as
     `applyDiscount()`'s audit entry.
  5. If a branch_manager performed the rejection, notify the shop owner (they weren't the one
     who caught it).
- Deliberately allowed regardless of the job's current `status`, including `completed`.

### 2. Cancellation reason / forfeited-deposit tracking (no fraud, legitimate loss)

- `job_orders` gains one new nullable column: `cancellation_reason`. The existing unused
  `rejection_reason` column (added 2026-07-08, never wired to any controller logic) is left
  alone rather than repurposed — its original intent is undocumented, and "rejection" doesn't
  cleanly describe "the customer went dark," so a clearly-named new column is safer than
  stretching an ambiguous existing one.
- Values, enforced via `Rule::in()`: `customer_request`, `shop_unable_to_fulfill`,
  `forfeited_deposit_abandoned`, `other`.
- Required only when `status` is being set to `cancelled`. Enforced in
  `UpdateJobOrderRequest`/`JobOrderController::update()` — cancelling is already just a status
  transition through the existing route, so no new endpoint is needed.
- No reversal logic. Whatever `Payment` rows already exist on the job are untouched — the shop
  legitimately keeps that money, and it should continue to count as collected revenue exactly
  as it does today.

### 3. Multi-branch reporting rollup

This is what actually closes the original gap ("an untracked loss on one branch is invisible
from another branch's view").

- `AnalyticsController::index()` (branch-scoped; visible to a branch_manager for their own
  branch, or shop-wide/branch-filtered for the owner) gains two new figures:
  `rejected_payments_amount` and `forfeited_deposit_amount` (each with a matching count).
  `forfeited_deposit_amount` sums whatever `Payment` rows exist on jobs where
  `status = 'cancelled' AND cancellation_reason = 'forfeited_deposit_abandoned'`.
- `AnalyticsController::branchComparison()` (owner-only, cross-branch — access unchanged) gains
  the same two figures per branch row, surfaced as new columns in
  `BranchComparisonTable.tsx`.
- Both figures are derived via query at read time — no new stored/denormalized flag on
  `JobOrder`, avoiding a value that could drift out of sync with the underlying `Payment`/
  `cancellation_reason` data.

### Role/view impact

- **shop_owner**: unrestricted — the reject action, the cancellation-reason picker, and both
  reporting views (branch-scoped KPIs and cross-branch comparison).
- **branch_manager**: same actions as shop_owner, scoped to their own branch (existing
  `branchAccessDenied()` pattern, unchanged). Sees their own branch's
  `rejected_payments_amount`/`forfeited_deposit_amount` in the regular dashboard KPIs. Does
  **not** see `branchComparison()` — unchanged from today.
- **staff**: no new UI, no new access. The reject action and cancellation-reason picker are
  hidden from staff client-side and blocked server-side by the same role gate that already
  hides `pay()`/`applyDiscount()`/`updatePayment()` from them today.
- **customer**: no new exposure, and none should be built. `JobStatusUpdatedNotification`
  already fires automatically on any transition to `cancelled` (derived from
  `JobOrder::STATUSES`, minus the two explicitly excluded statuses) — so a customer already
  gets a generic "your order was cancelled" notice with zero code changes. No automated
  message should ever say "we rejected your payment as fake" or "you forfeited your deposit" —
  that's an accusation the owner should make directly, not something a notification should
  send automatically (also safer legally if the system stays silent on the specific reason).
  The customer-facing portal (separate scope, built by a groupmate) should not have any new
  field leak into its API response.

## UI/UX (sutura-client)

Confirmed via mockups built from the real component styles (`JobFinancialsCard.tsx`,
`JobProductionTimeline.tsx`, `ReportKpiCards.tsx`, `BranchComparisonTable.tsx`):

1. **Reject action placement**: tucked behind a "⋮ more actions" menu on each payment row in
   `JobFinancialsCard.tsx`'s Payment History ledger, alongside the existing Edit action —
   **not** an always-visible inline icon next to Print/Edit. Nothing that reverses money should
   be one accidental tap away. A rejected payment row shows a strikethrough amount/method, a
   "Rejected" badge, and the reason/who/when.
2. **Cancellation reason modal**: intercepts the existing Production Phase `<select>` in
   `JobProductionTimeline.tsx` specifically when "Cancelled" is chosen (today it calls
   `setStatus('cancelled')` immediately with zero reason capture). Reuses the existing `Modal`
   component convention (same one `JobTrashModal.tsx` uses). Shows a warning banner only when
   the job has payments already collected, four reason options, and visually flags "Forfeited
   deposit" in the same rust color (`#B26959`) already used for Balance Due.
3. **Reporting**: the two new KPI tiles ("Rejected Payments", "Forfeited Deposits") are blended
   directly into the existing `ReportKpiCards.tsx` grid — same visual weight as the other nine
   tiles, not visually separated into their own section. Two new columns ("Rejected",
   "Forfeited") are added to `BranchComparisonTable.tsx`.

## Out of scope for this feature

- No refund mechanism — SUTURA does not move money (approved-scope limitation), so nothing
  here initiates an actual refund; it only corrects internal records.
- No automatic/scheduled detection of "customer gone dark" (e.g. a due-date-based staleness
  job) — cancellation with `forfeited_deposit_abandoned` is always a manual, human judgment
  call by the owner/branch_manager.
- No customer-facing notification changes beyond what already exists.
- No maker-checker restriction preventing the same branch_manager who logged a payment from
  also being the one who rejects it later — not requested, and adding it would be scope creep
  beyond what this design needs.
