# SUTURA — Web-Based Tailoring Shop Tracker System (Backend)

Capstone project, BSIT, STI College Davao. Team: Joshua Wayman A. Arabejo, Jossua A. Bongo (Leader), Renalyn C. Bulotano, Clareynz June A. Masudog. Adviser: Jessiel Chris D. Hilot. **Defense/deployment deadline: first week of October 2026.**

This is the Laravel API. The frontend lives in the sibling `sutura-client` repo (Next.js) — same thesis, separate git history. The full thesis proposal, interview research, and UI design docs live in `sutura-client/` (`suturathesisapproved.txt`, `Tailorshop,Sublimationshop,FashionShop.txt`, etc.) — check there for objectives/scope narrative if you need more than the summary below.

**Scope reference — check these before assuming a feature is done or missing**: `../sutura-client/TASK_DIVISION.md` (lives in the sibling `sutura-client` repo, alongside its other shared thesis reference docs) maps each team member to their owned module — the section that matters here is **Module 3, "Shop Owner Module," owned by Joshua Wayman A. Arabejo** — everything in this repo's Shop Owner-facing work should stay inside that module's boundary, not drift into Customer (Renalyn), Staff (Masudog), or Admin (Bongo) module work. `../sutura-client/REQUIREMENTS.md` has the fuller functional spec. Both go stale fast relative to actual shipped code — trust `app/Models/`/`routes/api.php` over either when they conflict, but check them first for *whose* scope something belongs to before building it.

## Commit policy

Don't commit after every small task/feature by default — that produces a wall of tiny commits the owner then has to clean up. When working through a list of several small fixes/features in one sitting, batch them and let the owner decide the commit boundaries, unless they've explicitly asked for a commit per item. Ask before committing if it's not already clear from context that they want it committed now.

## Git workflow — branch per module, not direct commits to `main` (as of 2026-08-12)

**Do not commit or push directly to `main` anymore.** Up through 2026-08-12 all of Joshua's Shop Owner Module work landed straight on `main` (that history stays as-is — don't rewrite it) — the team has since switched to a branch-per-module workflow so `main` stays stable while all four people work in this same repo concurrently. If you're an AI agent picking up work here, check which branch you're on (`git branch --show-current`) before committing:

| Branch | Module | Owner |
|---|---|---|
| `feature/customer-module` | Customer Module | Bulotano, Renalyn C. |
| `feature/admin-module` | Administrative System Module | Bongo, Jossua A. |
| `feature/shop-owner-module` | Shop Owner Module | Arabejo, Joshua Wayman A. |
| `feature/staff-module` | Tailoring Staff Module | Masudog, Clareynz June A. |

All four already exist on `origin` (both this repo and `sutura-client`), branched from `main` as of 2026-08-12. Workflow:
1. `git checkout <your feature branch>` — never work directly on `main`.
2. Commit normally as work progresses.
3. `git push origin <your feature branch>` — never `git push origin main` directly.
4. Merge into `main` via a Pull Request on GitHub once a module's work is ready for review, not by pushing straight to `main`.
5. Periodically merge `main` into your branch (`git merge main`) to pick up other members' merged work and avoid a large stale diff later.

If a task doesn't obviously belong to one of the four modules above, ask the user which branch to use rather than guessing or defaulting to `main`.

## What SUTURA actually is

A subscription-tiered (Basic/Pro/Premium), multi-branch platform connecting Davao City tailoring shops with customers: shop owners manage storefronts/staff/orders across branches, customers discover shops by garment specialization on a map and track order production in real time.

## The four roles → actual role strings

- **admin** — approves/rejects shop registrations, manages subscription tier definitions, platform-wide monitoring.
- **shop_owner** — full control of their shop(s): profile, catalog, pricing, branches, staff, appointments, analytics.
- **branch_manager** — owner-adjacent permissions scoped to a branch (see route middleware below).
- **staff** — day-to-day execution: jobs, appointments, customers, measurements; cannot delete/reassign or see owner-only financials.
- **customer** — just a `User` with the `customer` role; there is no separate `CustomerProfile` model (the approved thesis ERD describes one, but the real schema doesn't have it — trust the code, not the paper diagram).

Role enforcement is via `role:` middleware in `routes/api.php`, e.g. `Route::middleware('role:shop_owner,branch_manager,staff')`. When adding an endpoint, match the narrowest role set that actually needs it — see the comments already in `routes/api.php` explaining *why* certain actions (discounts, staff reassignment, deletes) are owner/manager-only while day-to-day CRUD (measurements, job status updates, walk-in catalog orders) is open to staff too.

## Explicitly OUT of scope

Don't build these — they were deliberately excluded from the approved thesis scope:
- Hardware integration (body scanners, RFID) — measurements are always manually entered.
- Offline mode / background sync.
- Native payment gateway integration — track payment/deposit status only, don't move money.
- Predictive analytics / AI forecasting.
- Inventory / material stock / purchase orders.
- Payroll or utility-cost tracking.
- Tax filing or business permit validation.
- Logistics / courier / delivery management.
- **Rental lifecycle** (available → rented → returned → inspection → cleaning) — this is in the interview research for "Fashion Shop" businesses but was never adopted into SUTURA's approved scope.

## Job order tracking — the actual state machine

From `app/Models/JobOrder.php` and its migrations — this is ground truth, not the thesis paper's idealized 13–19-stage tables. **This replaced an earlier, simpler `pending → cutting → sewing → fitting → ready_for_pickup → completed` version — if you see that old sequence cited anywhere (old docs, old memory, stale comments), it's out of date.**

- `JobOrder::STATUSES` — the real, current "3-Phase Tailoring Tracker" pipeline: `pending → design → pattern_making (or mass_cutting_printing, the Bulk Order Override for jobs with a Team Roster/Size Sheet) → cutting → sewing → ready_for_fitting → final_adjustments → qc_ironing → ready_for_pickup → completed`, with `cancelled`, `rejected`, and `on_hold` reachable from most points. `ready_for_fitting` auto-creates a Fitting appointment for the customer; `final_adjustments` is the revert target when a fitting reveals issues (job either goes back to `sewing` for rework or forward to `qc_ironing`).
- `payment_status`: `unpaid → partial → paid`.
- `JobOrder::STAFF_STAGES`: `design, pattern_making, cutting, sewing, qc_ironing` — the internal production stages staff progress through, distinct from the customer-facing `status`. Assigned via the `job_order_staff` pivot (`JobOrderStaff`) — **one row per stage**, so a staff member working multiple stages of the same job has multiple pivot rows. Any "how many jobs is this person on" count must `COUNT(DISTINCT job_order_id)`, not a raw row count — a raw-row version of this exact bug shipped and was caught/fixed in `StaffController::index`/`show`.
- `JobOrder::STAGES_REQUIRING_DOWNPAYMENT` and `MATERIAL_SOURCES` (`shop_supplied`, `customer_supplied`) also live as constants on this model — check them before adding new business rules around payment gating or material handling. The 50% downpayment gate ("No DP, No Layout, No Cut") is enforced in `JobOrderController::update`.
- `is_outsourced` and `is_rush` are boolean flags added after the initial schema — rush fee logic and outsourced-order courier stages.
- `tracking_code` (unique 8-char string, server-generated in `JobOrderController::store`) lets a customer check order status without logging in, via the public `GET /track/{trackingCode}` route (`JobOrderTrackingController`, narrow safe field subset, no staff/internal data). **Backend/DB only — deliberately no frontend page for this yet**, don't build one unless asked.
- `ready_for_pickup_at` (server-derived timestamp, stamped on transition into that status) drives the Reports page's "Unclaimed Pickups" list — orders sitting ready 14+ days, with a matching daily proactive notification (`app:notify-unclaimed-pickups`).
- Completion photo upload (`completion_photo_url`) is available at QC time but **optional, not required** — a hard "no photo, no ready-for-pickup" gate was tried and explicitly reverted by the shop owner.
- `discount_amount` is a real column that `JobOrderController::applyDiscount` writes to, reducing `balance` directly (never `total_amount`) — **any revenue/paid calculation must be `total_amount - balance - discount_amount`, never just `total_amount - balance`**, or a discount silently gets counted as cash collected. This exact bug shipped across ~11 surfaces (frontend and backend) before being fixed everywhere; if you add a new revenue figure anywhere, use this formula from the start.

## Domain models (current, not the paper's ERD)

`Appointment`, `AuditLog`, `CatalogImage`, `CatalogItem`, `CatalogItemReview`, `CatalogItemSave`, `CatalogOrder`, `CatalogRecommendation`, `JobOrder`, `JobOrderStaff`, `Measurement`, `Payment`, `Role`, `Service`, `ServicePackage`, `ServicePricing`, `Shop`, `ShopBranch`, `ShopPost`, `ShopReview`, `ShopSpecialHour`, `ShopSubscription`, `StaffProfile`, `SubscriptionPlan`, `SupportTicket`, `SupportTicketReply`, `User`.

Notable divergences from the approved thesis ERD: no `CustomerProfile` (customers are `User` + `Role`); feedback is split into `ShopReview` + `CatalogItemReview` rather than one unified `Feedback` table; `CatalogOrder` (walk-in/RTW sales) isn't in the original diagrams at all.

## What's already built vs. genuinely missing

The Shop Owner side is fully built and working — and by this point *deeply* polished, not just present: Jobs, Appointments, Catalog, Services (+ Packages, merged into one tabbed page — the standalone `/dashboard/service-packages` nav link was removed as redundant), Payments, Staff, Reports, Branches, Billing all have working backend + frontend. Check `GroupTasks.md` before trusting this further — it's checked against real code but goes stale fast. Notable systems built on top of the base CRUD, worth knowing about before assuming something is missing:

- **Audit logging** — `AuditLog` (polymorphic) covers staff removal, service/branch/catalog-item deletion, job order delete/restore, discounts, payment rejections, reschedules. Log *before* delete so name/data is still captured.
- **Notification breadth** — in-app (database channel) + email for customer-facing events; in-app-only for internal owner/staff digests. Includes daily proactive digests (`app:notify-overdue-jobs`, `app:notify-unclaimed-pickups`, `app:remind-expiring-subscriptions`, `app:remind-upcoming-appointments`), not just reactive per-action notifications. `QUEUE_CONNECTION=sync`, so these fire synchronously in-request — real emails actually send during local testing.
- **Fraud-prevention "warn don't block" pattern** — duplicate GCash/bank reference-number detection spans all three real payment surfaces at a shop (`JobOrder` payments, `CatalogOrder.payment_reference`, `Appointment.payment_reference`) and returns a non-blocking `warning` field rather than hard-erroring — a human judges it, since legitimate reference collisions can happen. Use this pattern (surface facts, don't hard-block) for anything similar; reserve a hard 403/422 for actions with no legitimate "actually fine" case (see the subscription-downgrade check below).
- **Subscription tier limits** — `StaffController::store` (max staff) and `ShopBranchController::store` (premium-only multi-branch) block *adding* past a plan's limit; `SubscriptionController::subscribe` additionally blocks a **downgrade** that would leave current staff/branch usage already over the new plan's limit. Deliberately does **not** gate ongoing customer-facing operations (job/appointment/order creation) on any plan quota — that was tried and explicitly reverted, since it risks disrupting a real shop's day-to-day business, unlike blocking the owner's own downgrade mistake.
- **Print pages** (`sutura-client/src/app/print/jobs/[id]/{ticket,receipt}`) have an established house style: black/white/grayscale only, zero boxed/bordered sections (hairline `border-t`/`border-b` rules only), sharp corners, no icons/emoji — ink-economy by design. Follow this for any future print work.
- **`profile_picture`/`bio`/`is_available` on staff** — real, owner-manageable fields (Staff Profile page at `dashboard/staff/[id]`, mirrors the Customer profile page's layout). Watch for the "column exists but missing from `$fillable`/`#[Fillable]`" bug class — it shipped 3 times in this codebase (`User.profile_picture`/`cover_photo`, `StaffProfile.is_available`, plus the same root cause on `job_orders.ready_for_pickup_at` needing `forceFill()`). Before trusting that a column is settable via `update()`, check it's actually in the model's fillable list.

Backend-relevant open items (frontend work, but confirm the API contract holds):
- Customer-facing job filtering by `customer_id` already works in `JobOrderController::index` — Renalyn's missing piece is the frontend page, not the API.
- Staff filtering by `?assigned_staff_id=X` already works on the jobs endpoint — Masudog's missing piece is a frontend toggle, not the API.
- Admin endpoints (`/admin/shops` + approve/reject, `/admin/subscription-plans`, `/admin/tickets` + replies/status) are fully built — Bongo's missing piece is the entire admin frontend, not the API.

## Database — thesis paper vs. current reality

| Layer | Approved thesis paper says | Actual current team decision |
|---|---|---|
| Database | MySQL hosted on **PlanetScale** | **Real local MySQL 8.4** (Homebrew, not XAMPP — `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1:3306`); migrating to **Supabase (Postgres)** ~mid-September 2026 |
| File storage | not specified | **Cloudflare R2** (planned at migration time; local dev still uses the `public` disk, see `FileUploadController`/`ProfileController::UPLOAD_DISK`) |
| Deploy | Vercel + Railway/Render | Vercel (frontend) + **Railway** (backend) — Render dropped |

See `sutura-client/DEADLINE.md` for the full migration plan. **A low-stakes dry run of this migration was already done (2026-07-23) against a disposable Supabase + R2 project** — full detail in this project's Claude memory (`project_postgres_migration_test_bugs_fixed.md`, `project_r2_storage_connected_url_bug_fixed.md`), summary here:

- `league/flysystem-aws-s3-v3` — **installed**, not pending anymore.
- `FileUploadController`'s URL bug — **fixed**. It now uses a single `private const UPLOAD_DISK = 'public'` referenced by both the `store()` and `Storage::disk(...)->url()` calls in all 4 methods. **Don't call bare `Storage::url($path)` or hardcode `'public'`/`'s3'` in more than one place here again** — the original bug was exactly that drift (store used one disk, url-generation silently used a different default disk). When the real Sept switch happens, changing that one constant to `'s3'` is the entire migration for this file.
- MySQL/Postgres `LIKE` case-sensitivity — **already hit and fixed**, not just a theoretical risk. `CatalogController::index()`'s search used to silently return zero results on Postgres for any non-exact-case search term (verified: searching `"gown"` found 0 of 10 real matches). Fixed with `whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($search).'%'])`. **This is the pattern to use for any future user-typed search/filter field** — plain `LIKE` is not portable between MySQL and Postgres, `LOWER()` on both sides is.
- **New bug class found during the dry run, now fixed**: `varchar(255)` columns storing image/file URLs (`shops.logo_path`, `catalog_images.image_url`, `catalog_items.fabric_image_url`/`size_chart_image_url`/`external_gallery_url`, `services.size_chart_image_url`, `payments.receipt_path`, `catalog_orders.payment_receipt_path`, `appointments.payment_receipt_path`) are too narrow for real cloud storage URLs (domain + bucket + URL-encoded filename routinely exceeds 255 chars) — Postgres rejects the write outright rather than silently truncating like MySQL might. Widened all of them to `TEXT` in `2026_07_23_225530_widen_image_and_path_url_columns_to_text.php`. **If you add a new URL/path column, make it `TEXT` from the start, not `string()`.**
- `LocalTestSeeder.php`'s shop `logo_path` was hardcoded to `http://127.0.0.1:8000/...` — only ever worked on one machine with the server on that exact port. Fixed to `Storage::disk('public')->url(...)`, same pattern as the controller fix above.
- `config/cors.php` still doesn't exist — still not needed until frontend/backend are on separate domains (Vercel + Railway). Still pending, unlike everything else in this list.
- Auth (Sanctum Bearer tokens), Queue (`sync`), and Session (`database` driver) are already cross-domain-friendly — nothing to change there at migration time.
- Do NOT migrate the XAMPP data — it's seed/demo data only (`LocalTestSeeder`); just run `php artisan migrate --seed` fresh against Postgres.
- The actual disk switch (`UPLOAD_DISK` constant from `'public'` to `'s3'`, and pointing `.env`'s `DB_*` at the real production Supabase project instead of a throwaway test one) is still deliberately deferred to the real September migration — everything above was verified against disposable test infrastructure, not wired into the app's actual default config.
