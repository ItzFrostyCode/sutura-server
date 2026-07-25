# SUTURA — Web-Based Tailoring Shop Tracker System (Backend)

Capstone project, BSIT, STI College Davao. Team: Joshua Wayman A. Arabejo, Jossua A. Bongo (Leader), Renalyn C. Bulotano, Clareynz June A. Masudog. Adviser: Jessiel Chris D. Hilot. **Defense/deployment deadline: first week of October 2026.**

This is the Laravel API. The frontend lives in the sibling `sutura-client` repo (Next.js) — same thesis, separate git history. The full thesis proposal, interview research, and UI design docs live in `sutura-client/` (`suturathesisapproved.txt`, `Tailorshop,Sublimationshop,FashionShop.txt`, etc.) — check there for objectives/scope narrative if you need more than the summary below.

## Commit policy

Don't commit after every small task/feature by default — that produces a wall of tiny commits the owner then has to clean up. When working through a list of several small fixes/features in one sitting, batch them and let the owner decide the commit boundaries, unless they've explicitly asked for a commit per item. Ask before committing if it's not already clear from context that they want it committed now.

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

From `app/Models/JobOrder.php` and its migrations — this is ground truth, not the thesis paper's idealized 13–19-stage tables:

- `status`: `pending → cutting → sewing → fitting → ready_for_pickup → (packed → handed_to_courier, only if outsourced) → completed`, with `cancelled` reachable from most points.
- `payment_status`: `unpaid → partial → paid`.
- `JobOrder::STAFF_STAGES`: `design, pattern_making, cutting, sewing, qc_ironing` — the internal production stages staff progress through, distinct from the customer-facing `status`.
- `JobOrder::STAGES_REQUIRING_DOWNPAYMENT` and `MATERIAL_SOURCES` (`shop_supplied`, `customer_supplied`) also live as constants on this model — check them before adding new business rules around payment gating or material handling.
- `is_outsourced` and `is_rush` are boolean flags added after the initial schema (see migration names) — rush fee logic and outsourced-order courier stages are recent additions, not in the original design docs at all.

## Domain models (current, not the paper's ERD)

`Appointment`, `AuditLog`, `CatalogImage`, `CatalogItem`, `CatalogItemReview`, `CatalogItemSave`, `CatalogOrder`, `CatalogRecommendation`, `JobOrder`, `JobOrderStaff`, `Measurement`, `Payment`, `Role`, `Service`, `ServicePackage`, `ServicePricing`, `Shop`, `ShopBranch`, `ShopPost`, `ShopReview`, `ShopSpecialHour`, `ShopSubscription`, `StaffProfile`, `SubscriptionPlan`, `SupportTicket`, `SupportTicketReply`, `User`.

Notable divergences from the approved thesis ERD: no `CustomerProfile` (customers are `User` + `Role`); feedback is split into `ShopReview` + `CatalogItemReview` rather than one unified `Feedback` table; `CatalogOrder` (walk-in/RTW sales) isn't in the original diagrams at all.

## What's already built vs. genuinely missing

The Shop Owner side is fully built and working: Jobs, Appointments, Catalog, Payments, Staff, Reports, Branches, Billing all have working backend + frontend. Check `GroupTasks.md` before trusting this further — it's checked against real code but goes stale fast.

Backend-relevant open items (frontend work, but confirm the API contract holds):
- Customer-facing job filtering by `customer_id` already works in `JobOrderController::index` — Renalyn's missing piece is the frontend page, not the API.
- Staff filtering by `?assigned_staff_id=X` already works on the jobs endpoint — Masudog's missing piece is a frontend toggle, not the API.
- Admin endpoints (`/admin/shops` + approve/reject, `/admin/subscription-plans`, `/admin/tickets` + replies/status) are fully built — Bongo's missing piece is the entire admin frontend, not the API.

## Database — thesis paper vs. current reality

| Layer | Approved thesis paper says | Actual current team decision |
|---|---|---|
| Database | MySQL hosted on **PlanetScale** | **MySQL via XAMPP locally now**; migrating to **Supabase (Postgres)** ~mid-September 2026 |
| File storage | not specified | **Cloudflare R2** (planned at migration time) |
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
