<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ShopController;
use App\Http\Controllers\Api\V1\Admin\ShopController as AdminShopController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionPlanController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\SpecializationController;
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
Route::prefix('v1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login'])->name('login');

    // Public Catalog
    Route::get('/catalog/{shop:slug}', [CatalogController::class, 'index']);
    Route::get('/catalog/{shop:slug}/{catalog}', [CatalogController::class, 'show']);
    
    // Public Booking
    Route::get('/catalog/{shop:slug}/booking-settings', [PublicBookingController::class, 'getSettings']);
    Route::post('/catalog/{shop:slug}/book', [PublicBookingController::class, 'submit']);

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
                Route::get('/measurements/{measurement}', [MeasurementController::class, 'show']);
                Route::post('/measurements', [MeasurementController::class, 'store']);
                Route::put('/measurements/{measurement}', [MeasurementController::class, 'update']);
                Route::delete('/measurements/{measurement}', [MeasurementController::class, 'destroy']);

                // Job Orders
                Route::get('/jobs', [JobOrderController::class, 'index']);
                Route::get('/jobs/{jobOrder}', [JobOrderController::class, 'show']);
                Route::put('/jobs/{jobOrder}', [JobOrderController::class, 'update']);
                Route::delete('/jobs/{jobOrder}', [JobOrderController::class, 'destroy']);
                Route::post('/jobs/{jobOrder}/staff', [JobOrderController::class, 'assignStaff']);

                // Appointments — read + status transitions (role enforcement inside controller)
                Route::get('/appointments', [AppointmentController::class, 'index']);
                Route::put('/appointments/{appointment}', [AppointmentController::class, 'update']);
                Route::post('/appointments/{appointment}/complete', [AppointmentController::class, 'complete']);
            });

            // Owner & Branch Manager Access
            Route::middleware('role:shop_owner,branch_manager')->group(function () {
                // Job Orders (Owner/Manager specific actions)
                Route::post('/jobs', [JobOrderController::class, 'store']);
                Route::post('/jobs/{jobOrder}/pay', [JobOrderController::class, 'pay']);

                // Customers CRM
                Route::get('/customers', [CustomerController::class, 'index']);
                Route::post('/customers', [CustomerController::class, 'store']);
                Route::put('/customers/{customer}', [CustomerController::class, 'update']);
                Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);

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
                Route::post('/staff', [StaffController::class, 'store']);
                Route::put('/staff/{staff}', [StaffController::class, 'update']);
                Route::delete('/staff/{staff}', [StaffController::class, 'destroy']);
                
                // Services
                Route::get('/services', [ServiceController::class, 'index']);
                Route::post('/services', [ServiceController::class, 'store']);
                Route::put('/services/{service}', [ServiceController::class, 'update']);
                Route::delete('/services/{service}', [ServiceController::class, 'destroy']);
                
                // Specializations
                Route::get('/specializations', [SpecializationController::class, 'index']);
                Route::post('/specializations', [SpecializationController::class, 'store']);
                Route::put('/specializations/{id}', [SpecializationController::class, 'update']);
                Route::delete('/specializations/{id}', [SpecializationController::class, 'destroy']);
                
                // Pricing
                Route::get('/services/{service}/pricing', [ServicePricingController::class, 'index']);
                Route::post('/services/{service}/pricing', [ServicePricingController::class, 'store']);
                Route::delete('/services/{service}/pricing/{pricing}', [ServicePricingController::class, 'destroy']);

                // Audit Logs
                Route::get('/audit-logs', [AuditLogController::class, 'index']);

                // Catalog Management
                Route::get('/catalog', [CatalogController::class, 'index']);
                Route::post('/catalog', [CatalogController::class, 'store']);
                Route::put('/catalog/{catalog}', [CatalogController::class, 'update']);
                Route::delete('/catalog/{catalog}', [CatalogController::class, 'destroy']);
                
                // Premade Catalog Orders (Ready-to-wear Purchases)
                Route::get('/catalog-orders', [\App\Http\Controllers\CatalogOrderController::class, 'index']);
                Route::post('/catalog-orders', [\App\Http\Controllers\CatalogOrderController::class, 'store']);
                Route::put('/catalog-orders/{order}', [\App\Http\Controllers\CatalogOrderController::class, 'update']);

                // Support Tickets (Shop Owner → Admin)
                Route::get('/tickets', [SupportTicketController::class, 'index']);
                Route::post('/tickets', [SupportTicketController::class, 'store']);
                Route::get('/tickets/{ticket}', [SupportTicketController::class, 'show']);
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
            Route::get('/tickets', [\App\Http\Controllers\Api\V1\Admin\SupportTicketAdminController::class, 'index']);
            Route::get('/tickets/{ticket}', [\App\Http\Controllers\Api\V1\Admin\SupportTicketAdminController::class, 'show']);
            Route::post('/tickets/{ticket}/reply', [\App\Http\Controllers\Api\V1\Admin\SupportTicketAdminController::class, 'reply']);
            Route::put('/tickets/{ticket}/status', [\App\Http\Controllers\Api\V1\Admin\SupportTicketAdminController::class, 'updateStatus']);
        });
    });

    // Public Catalog & Shop Profile
    Route::get('/public/shops/{shop:slug}', [\App\Http\Controllers\Api\V1\ShopController::class, 'publicProfile']);
    Route::get('/shops/{shop}/catalog', [CatalogController::class, 'index']);
    Route::get('/shops/{shop}/catalog/{catalog}', [CatalogController::class, 'show']);
    Route::post('/shops/{shop}/catalog/{catalogItem}/view', [\App\Http\Controllers\Api\V1\CatalogInteractionController::class, 'incrementViews']);
});
