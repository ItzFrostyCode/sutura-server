# Job Order Approval/Rejection — Design

## Context

The Shop Owner use-case documentation (`Usecase-Diagram.md`) names "Approve Orders Feasibility / Reject Orders — Reviews incoming job orders and decides whether to accept or decline" as its own explicit use case. Nothing in the codebase implements this today: a `JobOrder` created by staff/branch_manager just starts at `pending` and moves forward through production with no gate. Notably, `job_orders.rejection_reason` (a nullable text column, added 2026-07-08) has sat completely unused in the codebase since — this feature is what it was provisioned for.

### The real-world shape of this, clarified in discussion

- SUTURA's multi-branch model is shop-to-customer per branch, not cross-branch order routing — a customer picks the specific branch near them, and that branch does the work directly. Multi-branch exists for owner-level reporting/performance visibility, not order dispatch. This rules out any design involving reassigning an order to a different branch.
- The shop owner is frequently not the one physically present for a walk-in intake ("hindi naman mananahi ang shop owner most of the time") — a branch_manager or staff member is the one talking to the customer and entering the order. So this is not "the owner personally vets every order before creation"; it's a lightweight after-the-fact decline option, exercisable by either the owner or a branch_manager, mirroring the exact role-gate reasoning already established for `rejectPayment()` (owner isn't always there in person; branch_manager needs to act locally; plain `staff` — the ones actually doing the sewing — is excluded, since accepting/declining a customer commitment is a business decision, not a production task).
- Explicitly **not** wanted: an enumerated set of rejection-reason categories. Real rejection reasons vary shop to shop, and the system should stay generalized — this is deliberately a free-text field, not a `Rule::in()`-constrained set like `cancellation_reason`.
- Explicitly wanted: keep it "few clicks, not complex" — no multi-step wizard, no structured intake form beyond what already exists.

## Design

- Add `'rejected'` to `JobOrder::STATUSES`, as a terminal value reachable only from `pending` (a job that's already started production, e.g. `design`/`cutting`/etc., is cancelled via the existing `cancellation_reason` flow instead — rejection is specifically a pre-production decline).
- New endpoint: `POST /shops/{shop}/job-orders/{jobOrder}/reject`.
  - Role gate: `role:shop_owner,branch_manager` — identical to `pay()`, `applyDiscount()`, `rejectPayment()`, and every other JobOrder business-decision action already in this codebase. Plus the existing `branchAccessDenied()` check.
  - Guard: only callable while `jobOrder->status === 'pending'`. Any other status returns a 422 ("only a pending order can be rejected — cancel it instead").
  - Body: `{ reason: required|string|max:2000 }` (matches `rejection_reason`'s existing `text` column type, no artificial length ceiling beyond a sane upper bound).
  - Sets `status = 'rejected'` and `rejection_reason` to the given text. No transaction/locking needed — unlike payment rejection, no balance/payment_status math is involved (a `pending` job order, per `JobOrder::STAGES_REQUIRING_DOWNPAYMENT`, hasn't necessarily collected any payment yet, and even if it has via an early/optional deposit, this feature does not touch `balance`/`payment_status` — that's a separate concern; if a shop wants to refund a deposit on a rejected order, that's handled the same way as any other cancellation-adjacent scenario, outside this feature's scope).
  - No new migration: both `status` (already a plain string column, not a DB-level enum) and `rejection_reason` (added 2026-07-08) already exist.
- Notification: reuses the existing generic `JobStatusUpdatedNotification`, unchanged. That notification already fires for any status change except `pending`/`ready_for_pickup` (derived from `array_diff(JobOrder::STATUSES, [...])` in `JobOrderController::update()`), so adding `'rejected'` to `STATUSES` means the customer is automatically notified of the status change with zero new notification code. The specific *reason* text is deliberately not included in that notification — same call as payment rejection: if the customer needs to know why, that's a direct conversation, not automated copy.

## UI/UX

- A "Decline" button appears on a job order's detail page, visible only while `status === 'pending'`, and only rendered for `shop_owner`/`branch_manager` (matching the existing client-side role-gating pattern already used to hide staff-inaccessible actions).
- Clicking it reveals one free-text input ("Why are you declining this order?") and a confirm/cancel pair — no dropdown, no reason categories. Mirrors the existing inline-reveal-a-small-form pattern already used for Apply Discount and Reject Payment in `JobFinancialsCard.tsx`.
- Once rejected, the job order's status badge reads "Rejected" (new terminal state, styled similarly to "Cancelled" — muted/red family, per existing status-color conventions), and the stored `rejection_reason` is visible to owner/branch_manager on the job detail page (not shown anywhere in a customer-facing surface, since none exists for this repo's scope anyway).

## Out of scope for this feature

- No structured rejection-reason categories (explicitly rejected during design discussion — real reasons vary shop to shop).
- No cross-branch reassignment/routing of a rejected order — rejecting just ends it; if the customer wants to try a different branch, that's their own new booking through the customer-facing side (separate scope).
- No automatic deposit refund/reversal logic tied to rejection — if a shop needs to walk back an already-collected payment on a rejected order, that's the same manual/owner-side handling as any other pre-production refund scenario, not something this feature automates.
- No change to how `cancellation_reason`/cancellation works for jobs already in production — rejection and cancellation remain two distinct concepts for two distinct points in a job's lifecycle.
