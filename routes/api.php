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
use App\Http\Controllers\Api\V1\ServicePricingController;
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
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\InventoryController;
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
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login'])->name('login');

    // Public Catalog & Booking
    Route::get('/catalog/{shop:slug}', [CatalogController::class, 'index']);
    Route::get('/catalog/{shop:slug}/booking-settings', [PublicBookingController::class, 'getSettings']);
    Route::get('/catalog/{shop:slug}/appointments', [PublicBookingController::class, 'getAppointments']);
    Route::post('/catalog/{shop:slug}/book', [PublicBookingController::class, 'submit']);
    Route::get('/catalog/{shop:slug}/{catalog}', [CatalogController::class, 'show']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        
        // Notifications
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);

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
            });

            // Owner & Branch Manager Access
            Route::middleware('role:shop_owner,branch_manager')->group(function () {
                // Job Orders (Owner/Manager specific actions)
                Route::post('/jobs', [JobOrderController::class, 'store']);
                Route::post('/jobs/{jobOrder}/pay', [JobOrderController::class, 'pay']);
                Route::post('/jobs/{jobOrder}/staff', [JobOrderController::class, 'assignStaff']);
                Route::post('/jobs/{jobOrderId}/restore', [JobOrderController::class, 'restore'])->whereNumber('jobOrderId');
                Route::delete(JOB_DETAIL_ROUTE, [JobOrderController::class, 'destroy']);

                // Appointments — create and cancel (owner/manager only)
                Route::post('/appointments', [AppointmentController::class, 'store']);
                Route::delete('/appointments/{appointment}', [AppointmentController::class, 'destroy']);

                // Analytics
                Route::get('/analytics', [AnalyticsController::class, 'index']);

                // File Uploads
                Route::post('/upload', [FileUploadController::class, 'store']);

                // Suppliers
                Route::apiResource('suppliers', SupplierController::class)->except(['index', 'show']);
                Route::get('/suppliers', [SupplierController::class, 'index']);
                Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);

                // Inventory
                Route::apiResource('inventory', InventoryController::class)->except(['index', 'show']);
                Route::get('/inventory', [InventoryController::class, 'index']);
                Route::get('/inventory/{inventory}', [InventoryController::class, 'show']);
                Route::post('/inventory/{inventory}/adjust', [InventoryController::class, 'adjustStock']);
            });

            // Owner Only Access
            Route::middleware('role:shop_owner')->group(function () {
                // Staff Management
                Route::get('/staff', [StaffController::class, 'index']);
                Route::get('/staff/{staff}', [StaffController::class, 'show']);
                Route::post('/staff', [StaffController::class, 'store']);
                Route::put('/staff/{staff}', [StaffController::class, 'update']);
                Route::delete('/staff/{staff}', [StaffController::class, 'destroy']);
                
                // Services
                Route::get('/services', [ServiceController::class, 'index']);
                Route::post('/services', [ServiceController::class, 'store']);
                Route::post('/services/populate', [ServiceController::class, 'populate']);
                Route::post('/services/{serviceId}/restore', [ServiceController::class, 'restore'])->whereNumber('serviceId');
                Route::put('/services/{service}', [ServiceController::class, 'update']);
                Route::delete('/services/{service}', [ServiceController::class, 'destroy']);
                

                // Temporary Special Hours & Announcements
                Route::get('/special-hours', [ShopSpecialHourController::class, 'index']);
                Route::post('/special-hours', [ShopSpecialHourController::class, 'store']);
                Route::put('/special-hours/{specialHour}', [ShopSpecialHourController::class, 'update']);
                Route::delete('/special-hours/{specialHour}', [ShopSpecialHourController::class, 'destroy']);
                
                // Pricing
                Route::get('/services/{service}/pricing', [ServicePricingController::class, 'index']);
                Route::post('/services/{service}/pricing', [ServicePricingController::class, 'store']);
                Route::put('/services/{service}/pricing/{pricing}', [ServicePricingController::class, 'update']);
                Route::delete('/services/{service}/pricing/{pricing}', [ServicePricingController::class, 'destroy']);

                // Audit Logs
                Route::get('/audit-logs', [AuditLogController::class, 'index']);

                // Cross-branch performance comparison (owner-level strategic view)
                Route::get('/analytics/branches', [AnalyticsController::class, 'branchComparison']);

                // Reviews Management
                Route::get('/reviews', [\App\Http\Controllers\Api\V1\ShopReviewController::class, 'index']);
                Route::put('/reviews/{review}', [\App\Http\Controllers\Api\V1\ShopReviewController::class, 'update']);
                Route::delete('/reviews/{review}', [\App\Http\Controllers\Api\V1\ShopReviewController::class, 'destroy']);

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
            
            // Branch Management
            Route::get('/shops/{shop}/branches', [\App\Http\Controllers\Api\V1\ShopBranchController::class, 'index']);
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
    Route::post('/public/shops/{shop:slug}/upload-receipt', [FileUploadController::class, 'uploadPublicReceipt']);
    Route::get('/shops/{shop}/catalog', [CatalogController::class, 'index']);
    Route::get('/shops/{shop}/catalog/{catalog}', [CatalogController::class, 'show']);
    Route::post('/shops/{shop}/catalog/{catalogItem}/view', [\App\Http\Controllers\Api\V1\CatalogInteractionController::class, 'incrementViews']);
});
