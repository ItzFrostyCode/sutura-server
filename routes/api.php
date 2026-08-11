<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ShopController;
use App\Http\Controllers\Api\V1\Admin\ShopController as AdminShopController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionPlanController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\ShopSpecialHourController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\MeasurementController;
use App\Http\Controllers\Api\V1\JobOrderController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\FileUploadController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\PublicBookingController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\SupportTicketController;

if (!defined('MEASUREMENT_DETAIL_ROUTE')) {
    define('MEASUREMENT_DETAIL_ROUTE', '/measurements/{measurement}');
}
if (!defined('JOB_DETAIL_ROUTE')) {
    define('JOB_DETAIL_ROUTE', '/jobs/{jobOrder}');
}
if (!defined('TICKETS_ROUTE')) {
    define('TICKETS_ROUTE', '/tickets');
}
if (!defined('TICKETS_DETAIL_ROUTE')) {
    define('TICKETS_DETAIL_ROUTE', '/tickets/{ticket}');
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
    Route::get('/track/{trackingCode}', [\App\Http\Controllers\Api\V1\JobOrderTrackingController::class, 'show'])->middleware('throttle:20,1,track');

    // Public Catalog & Booking
    Route::get('/catalog/{shop:slug}', [CatalogController::class, 'index']);
    Route::get('/catalog/{shop:slug}/booking-settings', [PublicBookingController::class, 'getSettings']);
    Route::get('/catalog/{shop:slug}/appointments', [PublicBookingController::class, 'getAppointments']);
    Route::post('/catalog/{shop:slug}/book', [PublicBookingController::class, 'submit']);
    Route::get('/catalog/{shop:slug}/{catalog}', [CatalogController::class, 'show']);
    Route::post('/catalog/{shop:slug}/{catalogItem}/view', [\App\Http\Controllers\Api\V1\CatalogInteractionController::class, 'incrementViews']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        
        // Notifications
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/notifications/{id}/unread', [NotificationController::class, 'markAsUnread']);
        Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

        // Catalog Interactions (Any authenticated user)
        Route::post('/shops/{shop}/catalog/{catalogItem}/save', [\App\Http\Controllers\Api\V1\CatalogInteractionController::class, 'toggleSave']);
        Route::post('/shops/{shop}/catalog/{catalogItem}/reviews', [\App\Http\Controllers\Api\V1\CatalogInteractionController::class, 'rate']);
        
        // Shop Interactions (Any authenticated user)
        Route::post('/shops/{shop}/reviews', [\App\Http\Controllers\Api\V1\ShopReviewController::class, 'store']);
        
        // Profile Settings (Any authenticated user)
        Route::put('/profile/personal', [\App\Http\Controllers\Api\V1\ProfileController::class, 'updatePersonal']);
        Route::put('/profile/password', [\App\Http\Controllers\Api\V1\ProfileController::class, 'updatePassword']);
        Route::put('/profile/availability', [\App\Http\Controllers\Api\V1\ProfileController::class, 'toggleAvailability']);
        Route::post('/profile/upload', [\App\Http\Controllers\Api\V1\ProfileController::class, 'uploadImage']);
        
        // Shop Owner & Staff Routes
        Route::prefix('shops/{shop}')->group(function () {
            
            // Shared Access (Owner, Manager, Staff)
            Route::middleware('role:shop_owner,branch_manager,staff')->group(function () {
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
                // Per-piece completion on a bulk/team order's roster — same
                // "staff at the workbench" reasoning as progress photos above.
                Route::post('/jobs/{jobOrder}/roster/{index}/toggle', [JobOrderController::class, 'toggleRosterItem'])->whereNumber('index');

                // Appointments — read + status transitions (role enforcement inside controller)
                Route::get('/appointments', [AppointmentController::class, 'index']);
                Route::put('/appointments/{appointment}', [AppointmentController::class, 'update']);
                Route::put('/appointments/{appointment}/verify-payment', [AppointmentController::class, 'verifyPayment']);
                Route::post('/appointments/{appointment}/complete', [AppointmentController::class, 'complete']);

                // Ready-to-Wear Orders — front-of-house staff record walk-in sales and
                // verify payment receipts day to day; this shouldn't require the owner.
                Route::get('/catalog-orders', [\App\Http\Controllers\CatalogOrderController::class, 'index']);
                Route::post('/catalog-orders', [\App\Http\Controllers\CatalogOrderController::class, 'store']);
                Route::put('/catalog-orders/{order}', [\App\Http\Controllers\CatalogOrderController::class, 'update']);
                Route::put('/catalog-orders/{order}/verify-payment', [\App\Http\Controllers\CatalogOrderController::class, 'verifyPayment']);

                // Customers CRM — front-of-house staff look up/add customers day to
                // day too; CustomerController's own authorization already permits
                // staff for every method here, so the route gate must match it.
                Route::get('/customers', [CustomerController::class, 'index']);
                Route::post('/customers', [CustomerController::class, 'store']);
                Route::put('/customers/{customer}', [CustomerController::class, 'update']);
                Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);

                // Services (read-only) — every role that can create/edit an
                // appointment or job order needs to populate a service picker;
                // managing services (create/update/delete) stays owner-only below.
                Route::get('/services', [ServiceController::class, 'index']);
            });

            // Owner & Branch Manager Access
            Route::middleware('role:shop_owner,branch_manager')->group(function () {
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
                // unlike the day-to-day create/update/verify-payment above.
                Route::post('/catalog-orders/{order}/discount', [\App\Http\Controllers\CatalogOrderController::class, 'applyDiscount']);

                // Appointments — create and cancel (owner/manager only)
                Route::post('/appointments', [AppointmentController::class, 'store']);
                Route::delete('/appointments/{appointment}', [AppointmentController::class, 'destroy']);

                // Analytics
                Route::get('/analytics', [AnalyticsController::class, 'index']);

                // File Uploads
                Route::post('/upload', [FileUploadController::class, 'store']);

                // Staff/branch lists (read-only) — a branch manager assigning
                // an appointment needs to know who's on staff and which branch
                // it's for; managing staff/branches stays owner-only below.
                Route::get('/staff', [StaffController::class, 'index']);
                Route::get('/branches', [\App\Http\Controllers\Api\V1\ShopBranchController::class, 'index']);

            });

            // Owner Only Access
            Route::middleware('role:shop_owner')->group(function () {
                // Staff Management (list/read is granted to shop_owner+branch_manager above)
                Route::get('/staff/{staff}', [StaffController::class, 'show']);
                Route::post('/staff', [StaffController::class, 'store']);
                Route::put('/staff/{staff}', [StaffController::class, 'update']);
                Route::delete('/staff/{staff}', [StaffController::class, 'destroy']);

                // Services (list/read is granted to shop_owner+branch_manager+staff above)
                Route::post('/services', [ServiceController::class, 'store']);
                Route::post('/services/{serviceId}/restore', [ServiceController::class, 'restore'])->whereNumber('serviceId');
                Route::put('/services/{service}', [ServiceController::class, 'update']);
                Route::put('/services/{service}/sale', [ServiceController::class, 'updateSale']);
                Route::delete('/services/{service}', [ServiceController::class, 'destroy']);

                // Service Packages — bundles of 2+ existing services sold as one combo
                Route::get('/service-packages', [\App\Http\Controllers\Api\V1\ServicePackageController::class, 'index']);
                Route::post('/service-packages', [\App\Http\Controllers\Api\V1\ServicePackageController::class, 'store']);
                Route::put('/service-packages/{servicePackage}', [\App\Http\Controllers\Api\V1\ServicePackageController::class, 'update']);
                Route::delete('/service-packages/{servicePackage}', [\App\Http\Controllers\Api\V1\ServicePackageController::class, 'destroy']);

                // Temporary Special Hours & Announcements
                Route::get('/special-hours', [ShopSpecialHourController::class, 'index']);
                Route::post('/special-hours', [ShopSpecialHourController::class, 'store']);
                Route::put('/special-hours/{specialHour}', [ShopSpecialHourController::class, 'update']);
                Route::delete('/special-hours/{specialHour}', [ShopSpecialHourController::class, 'destroy']);

                // Audit Logs
                Route::get('/audit-logs', [AuditLogController::class, 'index']);

                // Cross-branch performance comparison (owner-level strategic view)
                Route::get('/analytics/branches', [AnalyticsController::class, 'branchComparison']);

                // Individual staff productivity (owner-level strategic view)
                Route::get('/analytics/staff', [AnalyticsController::class, 'staffProductivity']);

                // Reviews Management
                Route::get('/reviews', [\App\Http\Controllers\Api\V1\ShopReviewController::class, 'index']);
                Route::put('/reviews/{review}', [\App\Http\Controllers\Api\V1\ShopReviewController::class, 'update']);
                Route::delete('/reviews/{review}', [\App\Http\Controllers\Api\V1\ShopReviewController::class, 'destroy']);

                // Catalog Item Reviews — per-design-item reviews (e.g. a
                // specific Barong/gown in the Design Catalog), distinct from
                // the shop-level reviews above.
                Route::get('/catalog-item-reviews', [\App\Http\Controllers\Api\V1\CatalogInteractionController::class, 'indexForShop']);
                Route::put('/catalog-item-reviews/{review}', [\App\Http\Controllers\Api\V1\CatalogInteractionController::class, 'replyToReview']);
                Route::delete('/catalog-item-reviews/{review}', [\App\Http\Controllers\Api\V1\CatalogInteractionController::class, 'destroyReview']);

                // Shop Posts — completed-work showcase the owner posts to their storefront
                Route::get('/posts', [\App\Http\Controllers\Api\V1\ShopPostController::class, 'index']);
                Route::post('/posts', [\App\Http\Controllers\Api\V1\ShopPostController::class, 'store']);
                Route::put('/posts/{post}', [\App\Http\Controllers\Api\V1\ShopPostController::class, 'update']);
                Route::delete('/posts/{post}', [\App\Http\Controllers\Api\V1\ShopPostController::class, 'destroy']);

                // Catalog Management
                Route::get('/catalog', [CatalogController::class, 'index']);
                Route::post('/catalog', [CatalogController::class, 'store']);
                Route::put('/catalog/{catalog}', [CatalogController::class, 'update']);
                Route::delete('/catalog/{catalog}', [CatalogController::class, 'destroy']);
                
                // Support Tickets (Shop Owner → Admin)
                Route::get(TICKETS_ROUTE, [SupportTicketController::class, 'index']);
                Route::post(TICKETS_ROUTE, [SupportTicketController::class, 'store']);
                Route::get(TICKETS_DETAIL_ROUTE, [SupportTicketController::class, 'show']);
                Route::post('/tickets/{ticket}/reply', [SupportTicketController::class, 'reply']);
                Route::post('/tickets/{ticket}/close', [SupportTicketController::class, 'close']);
                Route::post('/support/upload', [FileUploadController::class, 'uploadSupportAttachment']);
            });
        });

        // Shop Management (Owner Only)
        Route::middleware('role:shop_owner')->group(function () {
            Route::apiResource('shops', ShopController::class);
            
            // Branch Management (list/read is granted to shop_owner+branch_manager above)
            Route::post('/shops/{shop}/branches', [\App\Http\Controllers\Api\V1\ShopBranchController::class, 'store']);
            Route::put('/shops/{shop}/branches/{branch}', [\App\Http\Controllers\Api\V1\ShopBranchController::class, 'update']);
            Route::delete('/shops/{shop}/branches/{branch}', [\App\Http\Controllers\Api\V1\ShopBranchController::class, 'destroy']);

            // Subscription Plan Billing
            Route::get('/subscriptions/plans', [\App\Http\Controllers\Api\V1\SubscriptionController::class, 'index']);
            Route::get('/shops/{shop}/subscription', [\App\Http\Controllers\Api\V1\SubscriptionController::class, 'current']);
            Route::post('/shops/{shop}/subscription', [\App\Http\Controllers\Api\V1\SubscriptionController::class, 'subscribe']);
            Route::put('/shops/{shop}', [ShopController::class, 'update']);
        });

        // Admin Routes
        Route::prefix('admin')->middleware('role:admin')->group(function () {
            Route::get('/shops', [AdminShopController::class, 'index']);
            Route::put('/shops/{shop}/approve', [AdminShopController::class, 'approve']);
            Route::put('/shops/{shop}/reject', [AdminShopController::class, 'reject']);

            Route::get('/subscription-plans', [SubscriptionPlanController::class, 'index']);
            Route::post('/subscription-plans', [SubscriptionPlanController::class, 'store']);

            // Admin Support Ticket Management
            Route::get(TICKETS_ROUTE, [\App\Http\Controllers\Api\V1\Admin\SupportTicketAdminController::class, 'index']);
            Route::get(TICKETS_DETAIL_ROUTE, [\App\Http\Controllers\Api\V1\Admin\SupportTicketAdminController::class, 'show']);
            Route::post('/tickets/{ticket}/reply', [\App\Http\Controllers\Api\V1\Admin\SupportTicketAdminController::class, 'reply']);
            Route::put('/tickets/{ticket}/status', [\App\Http\Controllers\Api\V1\Admin\SupportTicketAdminController::class, 'updateStatus']);
        });
    });

    // Public Catalog & Shop Profile
    Route::get('/public/shops/{shop:slug}', [ShopController::class, 'publicProfile']);
    Route::get('/public/shops/{shop:slug}/services', [ServiceController::class, 'publicIndex']);
    Route::get('/public/shops/{shop:slug}/service-packages', [\App\Http\Controllers\Api\V1\ServicePackageController::class, 'publicIndex']);
    Route::get('/public/shops/{shop:slug}/posts', [\App\Http\Controllers\Api\V1\ShopPostController::class, 'publicIndex']);
    Route::get('/public/shops/{shop:slug}/reviews', [\App\Http\Controllers\Api\V1\ShopReviewController::class, 'publicIndex']);
    Route::post('/public/shops/{shop:slug}/upload-receipt', [FileUploadController::class, 'uploadPublicReceipt']);
    Route::post('/public/shops/{shop:slug}/upload-reference-image', [FileUploadController::class, 'uploadPublicReferenceImage']);
    Route::get('/shops/{shop}/catalog', [CatalogController::class, 'index']);
    Route::get('/shops/{shop}/catalog/{catalog}', [CatalogController::class, 'show']);
});
