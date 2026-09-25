<?php

use App\Http\Controllers\Api\V1\Admin\StoreController as AdminStoreController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionPlanController;
use App\Http\Controllers\Api\V1\Admin\SupportTicketAdminController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CatalogInteractionController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\FileUploadController;
use App\Http\Controllers\Api\V1\JobOrderController;
use App\Http\Controllers\Api\V1\JobOrderTrackingController;
use App\Http\Controllers\Api\V1\MeasurementController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PublicBookingController;
use App\Http\Controllers\Api\V1\RecentlyViewedController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\ServicePackageController;
use App\Http\Controllers\Api\V1\ServiceReviewController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\StoreBranchController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\StorePostController;
use App\Http\Controllers\Api\V1\StoreReviewController;
use App\Http\Controllers\Api\V1\StoreSpecialHourController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SupportTicketController;
use App\Http\Controllers\Api\V1\SystemNewsController;
use App\Http\Controllers\CatalogOrderController;
use Illuminate\Support\Facades\Route;

if (! defined('MEASUREMENT_DETAIL_ROUTE')) {
    define('MEASUREMENT_DETAIL_ROUTE', '/measurements/{measurement}');
}
if (! defined('JOB_DETAIL_ROUTE')) {
    define('JOB_DETAIL_ROUTE', '/jobs/{jobOrder}');
}
if (! defined('TICKETS_ROUTE')) {
    define('TICKETS_ROUTE', '/tickets');
}
if (! defined('TICKETS_DETAIL_ROUTE')) {
    define('TICKETS_DETAIL_ROUTE', '/tickets/{ticket}');
}
if (! defined('CUSTOMER_DETAIL_ROUTE')) {
    define('CUSTOMER_DETAIL_ROUTE', '/customers/{customer}');
}
if (! defined('STAFF_DETAIL_ROUTE')) {
    define('STAFF_DETAIL_ROUTE', '/staff/{staff}');
}

Route::prefix('v1')->group(function () {
    // Laravel's throttle middleware keys its bucket by IP alone (see
    // ThrottleRequests::resolveRequestSignature) — it does NOT factor in the
    // route by default, so these four endpoints would otherwise silently
    // share one combined 6-per-minute budget instead of 6 each. The 3rd
    // throttle argument is a key prefix; giving each route its own makes the
    // buckets genuinely independent.
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:6,1,register');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:6,1,login')->name('login');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:6,1,forgot-password');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1,reset-password');

    // Public Job Order Tracking — no account needed, just the tracking_code
    // handed to the customer at intake. Backend-only for now; no consuming
    // page yet (see the loop's memory note for the pending frontend task).
    // Throttled per IP, same reasoning as the auth routes above, though the
    // 8-char code space makes brute-forcing impractical either way.
    Route::get('/track/{trackingCode}', [JobOrderTrackingController::class, 'show'])->middleware('throttle:20,1,track');

    // Public Catalog & Booking
    Route::get('/catalog/{store:slug}', [CatalogController::class, 'index']);
    Route::get('/catalog/{store:slug}/booking-settings', [PublicBookingController::class, 'getSettings']);
    Route::get('/catalog/{store:slug}/appointments', [PublicBookingController::class, 'getAppointments']);
    // Rate limit: 10 bookings per minute per IP — high enough for a real
    // customer retrying after a validation error, low enough to slow a
    // spam bot hitting every store's /book endpoint.
    Route::post('/catalog/{store:slug}/book', [PublicBookingController::class, 'submit'])->middleware('throttle:10,1,book');
    Route::get('/catalog/{store:slug}/{catalog}', [CatalogController::class, 'show']);
    Route::post('/catalog/{store:slug}/{catalogItem}/view', [CatalogInteractionController::class, 'incrementViews']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        // System News — real commit history from both repos, not a
        // hardcoded changelog (see SystemNewsController for why).
        Route::get('/system-news', [SystemNewsController::class, 'index']);

        // Notifications
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/preferences', [NotificationController::class, 'getPreferences']);
        Route::post('/notifications/preferences', [NotificationController::class, 'updatePreferences']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::post('/notifications/bulk-read', [NotificationController::class, 'bulkRead']);
        Route::post('/notifications/bulk-delete', [NotificationController::class, 'bulkDelete']);
        Route::delete('/notifications/clear-all', [NotificationController::class, 'clearAll']);
        Route::get('/notifications/{id}', [NotificationController::class, 'show']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/notifications/{id}/unread', [NotificationController::class, 'markAsUnread']);
        Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

        // Catalog Interactions (Any authenticated user) — {store:slug}, not a
        // bare {store}: every customer-facing page only ever has the store's
        // SLUG in its URL/state (see store/[store_id]/page.tsx — that param is
        // actually the slug), never its numeric id. A bare {store} binds by
        // primary key, so calling any of these with a slug 404'd outright —
        // confirmed live (curl) before this fix. toggleSave/rate/report all
        // shared this bug; fixed together since they're the same call shape.
        Route::post('/stores/{store:slug}/catalog/{catalogItem}/save', [CatalogInteractionController::class, 'toggleSave']);
        Route::post('/stores/{store:slug}/catalog/{catalogItem}/reviews', [CatalogInteractionController::class, 'rate']);
        Route::post('/stores/{store:slug}/catalog/{catalogItem}/report', [CatalogInteractionController::class, 'report']);

        // Service Ratings (Any authenticated user) — star-only, see ServiceReviewController.
        Route::post('/stores/{store:slug}/services/{service}/reviews', [ServiceReviewController::class, 'rate']);
        Route::get('/stores/{store:slug}/services/{service}/my-review', [ServiceReviewController::class, 'myRating']);

        // Store Interactions (Any authenticated user)
        Route::get('/stores/{store:slug}/my-review', [StoreReviewController::class, 'myRating']);
        Route::get('/stores/{store}/my-review', [StoreReviewController::class, 'myRating']);
        Route::post('/stores/{store:slug}/reviews', [StoreReviewController::class, 'store']);
        Route::post('/stores/{store}/reviews', [StoreReviewController::class, 'store']);
        Route::delete('/stores/{store:slug}/reviews/mine', [StoreReviewController::class, 'unrate']);
        Route::delete('/stores/{store}/reviews/mine', [StoreReviewController::class, 'unrate']);
        Route::post('/stores/{store:slug}/repair-requests', [JobOrderController::class, 'customerRepairRequest']);
        Route::post('/stores/{store:slug}/bulk-orders', [JobOrderController::class, 'customerBulkOrder']);
        Route::post('/stores/{store:slug}/made-to-order', [JobOrderController::class, 'customerMadeToOrder']);

        // My Orders / My Appointments — cross-store, for whoever is logged
        // in (Objective 5: "customers monitor their order progress from
        // placement to pickup"). No role gate: filters by the authenticated
        // user's own id as customer_id, same as /auth/me isn't role-gated.
        Route::get('/my-orders', [JobOrderTrackingController::class, 'myOrders']);
        Route::get('/my-orders/{jobOrder}', [JobOrderTrackingController::class, 'myOrderDetail']);
        Route::get('/my-appointments', [AppointmentController::class, 'myAppointments']);
        Route::get('/my-appointments/{appointment}', [AppointmentController::class, 'myAppointmentDetail']);
        // Self-service cancel — distinct from the owner/manager destroy()
        // below (no reason required, never sets rebooking_blocked). Lets a
        // customer free up their one-active-appointment-per-store slot
        // (PublicBookingController::submit()'s guard) to book a different
        // date themselves instead of asking the store to do it.
        Route::delete('/my-appointments/{appointment}', [AppointmentController::class, 'cancelMine']);
        Route::get('/my-measurements', [MeasurementController::class, 'myMeasurements']);
        Route::get('/my-catalog-reviews', [CatalogInteractionController::class, 'myReviews']);
        Route::get('/my-store-reviews', [StoreReviewController::class, 'myReviews']);
        Route::get('/my-recently-viewed', [RecentlyViewedController::class, 'index']);
        Route::post('/recently-viewed', [RecentlyViewedController::class, 'store']);
        Route::get('/my-tickets', [SupportTicketController::class, 'myTickets']);
        Route::get('/my-tickets/{ticket}', [SupportTicketController::class, 'myTicketShow']);
        Route::post('/my-tickets/{ticket}/reply', [SupportTicketController::class, 'myTicketReply']);

        // Profile Settings (Any authenticated user)
        Route::put('/profile/personal', [ProfileController::class, 'updatePersonal']);
        Route::put('/profile/password', [ProfileController::class, 'updatePassword']);
        Route::put('/profile/availability', [ProfileController::class, 'toggleAvailability']);
        Route::post('/profile/upload', [ProfileController::class, 'uploadImage']);

        // Store Owner & Staff Routes
        Route::prefix('stores/{store}')->group(function () {

            // Shared Access (Owner, Manager, Staff)
            Route::middleware('role:store_owner,branch_manager,staff')->group(function () {
                // Measurements
                Route::get('/measurements', [MeasurementController::class, 'index']);
                Route::get(MEASUREMENT_DETAIL_ROUTE, [MeasurementController::class, 'show']);
                Route::post('/measurements', [MeasurementController::class, 'store']);
                Route::put(MEASUREMENT_DETAIL_ROUTE, [MeasurementController::class, 'update']);
                Route::delete(MEASUREMENT_DETAIL_ROUTE, [MeasurementController::class, 'destroy']);

                // Job Orders — staff can view and progress a job's stage/status,
                // but cannot delete it or reassign who's working on it (that's a
                // supervisory action reserved for the owner/branch manager below).
                Route::get('/jobs', [JobOrderController::class, 'index']);
                Route::get(JOB_DETAIL_ROUTE, [JobOrderController::class, 'show']);
                Route::put(JOB_DETAIL_ROUTE, [JobOrderController::class, 'update']);
                // Staff are the ones actually at the workbench — production-evidence
                // photos are a day-to-day task, not a supervisory decision, so this
                // sits in the staff-accessible group unlike reject/pay/discount below.
                Route::post('/jobs/{jobOrder}/progress-photos', [JobOrderController::class, 'addProgressPhoto']);
                Route::delete('/jobs/{jobOrder}/progress-photos', [JobOrderController::class, 'deleteProgressPhoto']);
                // Per-piece completion on a bulk/team order's roster — same
                // "staff at the workbench" reasoning as progress photos above.
                Route::post('/jobs/{jobOrder}/roster/{index}/toggle', [JobOrderController::class, 'toggleRosterItem'])->whereNumber('index');

                // Per-order material attribution (typically the cutter, during
                // cutting) — same "staff at the workbench" reasoning as above.
                Route::post('/jobs/{jobOrder}/materials', [JobOrderController::class, 'addMaterial']);
                Route::delete('/jobs/{jobOrder}/materials/{material}', [JobOrderController::class, 'deleteMaterial']);

                // Appointments — read + status transitions (role enforcement inside controller)
                Route::get('/appointments', [AppointmentController::class, 'index']);
                Route::put('/appointments/{appointment}', [AppointmentController::class, 'update']);
                Route::post('/appointments/{appointment}/complete', [AppointmentController::class, 'complete']);
                // Narrow operational follow-up — Staff may create a scheduled
                // return visit (consultation/fitting/adjustment/pickup) without
                // the full owner/manager booking form's fields (payment method,
                // etc.). One endpoint, usable from either the Appointments
                // dashboard or a Job's detail page (job_order_id optional,
                // used to derive the customer when supplied).
                // CUSTOMER-WORKFLOW.md §7.4, STAFF-WORKFLOW.md §17 — a
                // deliberately narrower action than store() below, not a role
                // addition to it.
                Route::post('/appointments/follow-up', [AppointmentController::class, 'createFollowUp']);

                // Ready-to-Wear Orders — front-of-house staff record walk-in sales
                // day to day; this shouldn't require the owner.
                Route::get('/catalog-orders', [CatalogOrderController::class, 'index']);
                Route::post('/catalog-orders', [CatalogOrderController::class, 'store']);
                Route::match(['put', 'patch'], '/catalog-orders/{order}', [CatalogOrderController::class, 'update']);
                Route::match(['put', 'patch'], '/catalog-orders/{order}/status', [CatalogOrderController::class, 'update']);

                // Customers CRM — front-of-house staff look up/add customers day to
                // day too; CustomerController's own authorization already permits
                // staff for every method here, so the route gate must match it.
                Route::get('/customers', [CustomerController::class, 'index']);
                Route::get(CUSTOMER_DETAIL_ROUTE, [CustomerController::class, 'show']);
                Route::post('/customers', [CustomerController::class, 'store']);
                Route::put(CUSTOMER_DETAIL_ROUTE, [CustomerController::class, 'update']);
                Route::delete(CUSTOMER_DETAIL_ROUTE, [CustomerController::class, 'destroy']);

                // Services (read-only) — every role that can create/edit an
                // appointment or job order needs to populate a service picker;
                // managing services (create/update/delete) stays owner-only below.
                Route::get('/services', [ServiceController::class, 'index']);

                // Staff & Branch directory (read-only) — staff, managers, and owners
                // need to see the artisan roster, availability, and branch assignments.
                Route::get('/staff', [StaffController::class, 'index']);
                Route::get(STAFF_DETAIL_ROUTE, [StaffController::class, 'show']);
                Route::get('/branches', [StoreBranchController::class, 'index']);

                // Store active subscription tier (read-only for feature gating)
                Route::get('/subscription', [SubscriptionController::class, 'current']);
            });

            // Owner & Branch Manager Access
            Route::middleware('role:store_owner,branch_manager')->group(function () {
                // Job Orders (Owner/Manager specific actions)
                Route::post('/jobs', [JobOrderController::class, 'store']);
                Route::post('/jobs/{jobOrder}/pay', [JobOrderController::class, 'pay']);
                Route::post('/jobs/{jobOrder}/discount', [JobOrderController::class, 'applyDiscount']);
                Route::post('/jobs/{jobOrder}/payments/{payment}/reject', [JobOrderController::class, 'rejectPayment']);
                Route::post('/jobs/{jobOrder}/reject', [JobOrderController::class, 'rejectOrder']);
                Route::put('/jobs/{jobOrder}/payments/{payment}', [JobOrderController::class, 'updatePayment']);
                Route::post('/jobs/{jobOrder}/staff', [JobOrderController::class, 'assignStaff']);
                Route::post('/jobs/{jobOrder}/notify-customer', [JobOrderController::class, 'notifyCustomer']);
                Route::post('/jobs/{jobOrderId}/restore', [JobOrderController::class, 'restore'])->whereNumber('jobOrderId');
                Route::delete(JOB_DETAIL_ROUTE, [JobOrderController::class, 'destroy']);

                // Catalog Orders — discount decisions are a supervisory action,
                // same as verify-payment below, unlike the day-to-day
                // create/update Staff already has above.
                Route::post('/catalog-orders/{order}/discount', [CatalogOrderController::class, 'applyDiscount']);
                Route::put('/catalog-orders/{order}/verify-payment', [CatalogOrderController::class, 'verifyPayment']);

                // Appointments — create, cancel, and payment verification
                // (owner/manager only). Payment capture/verification is
                // Owner/Branch-Manager-exclusive under the finalized target
                // (PAYMENT-WORKFLOW.md Part B) — moved here from the shared
                // Staff-accessible group above; Staff's role narrows to
                // reading the resulting payment_status, never setting it.
                Route::post('/appointments', [AppointmentController::class, 'store']);
                Route::delete('/appointments/{appointment}', [AppointmentController::class, 'destroy']);
                Route::put('/appointments/{appointment}/verify-payment', [AppointmentController::class, 'verifyPayment']);

                // Analytics
                Route::get('/analytics', [AnalyticsController::class, 'index']);

                // File Uploads
                Route::post('/upload', [FileUploadController::class, 'store']);
            });

            // Owner Only Access
            Route::middleware('role:store_owner')->group(function () {
                // Staff Management (list/read is granted to all store members above)
                Route::post('/staff', [StaffController::class, 'store']);
                Route::put(STAFF_DETAIL_ROUTE, [StaffController::class, 'update']);
                Route::delete(STAFF_DETAIL_ROUTE, [StaffController::class, 'destroy']);

                // Services (list/read is granted to store_owner+branch_manager+staff above)
                Route::post('/services', [ServiceController::class, 'store']);
                Route::post('/services/{serviceId}/restore', [ServiceController::class, 'restore'])->whereNumber('serviceId');
                Route::put('/services/{service}', [ServiceController::class, 'update']);
                Route::put('/services/{service}/sale', [ServiceController::class, 'updateSale']);
                Route::delete('/services/{service}', [ServiceController::class, 'destroy']);

                // Service Packages — bundles of 2+ existing services sold as one combo
                Route::get('/service-packages', [ServicePackageController::class, 'index']);
                Route::post('/service-packages', [ServicePackageController::class, 'store']);
                Route::put('/service-packages/{servicePackage}', [ServicePackageController::class, 'update']);
                Route::delete('/service-packages/{servicePackage}', [ServicePackageController::class, 'destroy']);

                // Temporary Special Hours & Announcements
                Route::get('/special-hours', [StoreSpecialHourController::class, 'index']);
                Route::post('/special-hours', [StoreSpecialHourController::class, 'store']);
                Route::put('/special-hours/{specialHour}', [StoreSpecialHourController::class, 'update']);
                Route::delete('/special-hours/{specialHour}', [StoreSpecialHourController::class, 'destroy']);

                // Audit Logs
                Route::get('/audit-logs', [AuditLogController::class, 'index']);

                // Cross-branch performance comparison (owner-level strategic view)
                Route::get('/analytics/branches', [AnalyticsController::class, 'branchComparison']);

                // Individual staff productivity (owner-level strategic view)
                Route::get('/analytics/staff', [AnalyticsController::class, 'staffProductivity']);

                // Subscription activity — Objective 7 ("subscription activity"
                // reporting). Owner-only, matching subscribe()'s own gate —
                // billing isn't a branch-manager action anywhere else either.
                Route::get('/analytics/subscription', [AnalyticsController::class, 'subscriptionActivity']);

                // Reviews Management
                Route::get('/reviews', [StoreReviewController::class, 'index']);
                Route::put('/reviews/{review}', [StoreReviewController::class, 'update']);
                Route::delete('/reviews/{review}', [StoreReviewController::class, 'destroy']);

                // Catalog Item Reviews — per-design-item reviews (e.g. a
                // specific Barong/gown in the Design Catalog), distinct from
                // the store-level reviews above.
                Route::get('/catalog-item-reviews', [CatalogInteractionController::class, 'indexForStore']);
                Route::put('/catalog-item-reviews/{review}', [CatalogInteractionController::class, 'replyToReview']);
                Route::delete('/catalog-item-reviews/{review}', [CatalogInteractionController::class, 'destroyReview']);

                // Store Posts — completed-work showcase the owner posts to their storefront
                Route::get('/posts', [StorePostController::class, 'index']);
                Route::post('/posts', [StorePostController::class, 'store']);
                Route::put('/posts/{post}', [StorePostController::class, 'update']);
                Route::delete('/posts/{post}', [StorePostController::class, 'destroy']);

                // Catalog Management
                Route::get('/catalog', [CatalogController::class, 'index']);
                Route::post('/catalog', [CatalogController::class, 'store']);
                Route::put('/catalog/{catalog}', [CatalogController::class, 'update']);
                Route::delete('/catalog/{catalog}', [CatalogController::class, 'destroy']);

                // Support Tickets (Store Owner → Admin)
                Route::get(TICKETS_ROUTE, [SupportTicketController::class, 'index']);
                Route::post(TICKETS_ROUTE, [SupportTicketController::class, 'store']);
                Route::get(TICKETS_DETAIL_ROUTE, [SupportTicketController::class, 'show']);
                Route::post('/tickets/{ticket}/reply', [SupportTicketController::class, 'reply']);
                Route::post('/tickets/{ticket}/close', [SupportTicketController::class, 'close']);
                Route::post('/support/upload', [FileUploadController::class, 'uploadSupportAttachment']);
            });
        });

        // Store Management (Owner Only)
        Route::middleware('role:store_owner')->group(function () {
            Route::apiResource('stores', StoreController::class);

            // Branch Management (list/read is granted to store_owner+branch_manager above)
            Route::post('/stores/{store}/branches', [StoreBranchController::class, 'store']);
            Route::put('/stores/{store}/branches/{branch}', [StoreBranchController::class, 'update']);
            Route::put('/stores/{store}/branches/{branch}/set-main', [StoreBranchController::class, 'setMain']);
            Route::delete('/stores/{store}/branches/{branch}', [StoreBranchController::class, 'destroy']);

            // Subscription Plan Billing
            Route::get('/subscriptions/plans', [SubscriptionController::class, 'index']);
            Route::post('/stores/{store}/subscription', [SubscriptionController::class, 'subscribe']);
            Route::put('/stores/{store}', [StoreController::class, 'update']);
        });

        // Admin Routes
        Route::prefix('admin')->middleware('role:admin')->group(function () {
            Route::get('/stores', [AdminStoreController::class, 'index']);
            Route::put('/stores/{store}/approve', [AdminStoreController::class, 'approve']);
            Route::put('/stores/{store}/reject', [AdminStoreController::class, 'reject']);

            Route::get('/subscription-plans', [SubscriptionPlanController::class, 'index']);
            Route::post('/subscription-plans', [SubscriptionPlanController::class, 'store']);

            // Admin Support Ticket Management
            Route::get(TICKETS_ROUTE, [SupportTicketAdminController::class, 'index']);
            Route::get(TICKETS_DETAIL_ROUTE, [SupportTicketAdminController::class, 'show']);
            Route::post('/tickets/{ticket}/reply', [SupportTicketAdminController::class, 'reply']);
            Route::put('/tickets/{ticket}/status', [SupportTicketAdminController::class, 'updateStatus']);
        });
    });

    // Public Catalog & Store Profile
    // Store Discovery — search/filter/map feed (Objectives 3 & 4)
    Route::get('/public/stores', [StoreController::class, 'publicIndex']);
    // Minimal public profile (name/avatar only) — used when a customer taps
    // another reviewer's name/avatar on a catalog item's Ratings & Reviews
    // page. Own-account viewing bypasses this entirely on the frontend and
    // links straight to /account instead.
    Route::get('/public/users/{id}', [ProfileController::class, 'publicShow']);
    // Cross-store catalog showroom feed for the landing page's catalog grid
    Route::get('/public/catalog-items', [CatalogController::class, 'publicShowroom']);
    Route::get('/public/services', [ServiceController::class, 'publicShowroom']);
    Route::get('/public/stores/{store:slug}', [StoreController::class, 'publicProfile']);
    Route::get('/public/stores/{store:slug}/services', [ServiceController::class, 'publicIndex']);
    Route::get('/public/stores/{store:slug}/service-packages', [ServicePackageController::class, 'publicIndex']);
    Route::get('/public/stores/{store:slug}/posts', [StorePostController::class, 'publicIndex']);
    Route::get('/public/stores/{store:slug}/reviews', [StoreReviewController::class, 'publicIndex']);
    Route::post('/public/stores/{store:slug}/upload-receipt', [FileUploadController::class, 'uploadPublicReceipt']);
    Route::post('/public/stores/{store:slug}/upload-reference-image', [FileUploadController::class, 'uploadPublicReferenceImage']);
    Route::get('/stores/{store}/catalog', [CatalogController::class, 'index']);
    Route::get('/stores/{store}/catalog/{catalog}', [CatalogController::class, 'show']);
});
