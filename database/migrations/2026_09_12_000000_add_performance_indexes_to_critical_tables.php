<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add missing indexes to critical tables that are frequently queried.
     * These indexes dramatically improve query performance for:
     * - Store-scoped queries (all controllers use store_id filtering)
     * - Status filtering (job status, appointment status, payment status)
     * - Customer/staff lookups
     * - Date range queries (analytics, reports)
     */
    public function up(): void
    {
        // ── job_orders ────────────────────────────────────────────────────────
        // Frequently queried by: store_id, customer_id, status, store_branch_id,
        // due_date, created_at, payment_status
        Schema::table('job_orders', function (Blueprint $table) {
            $table->index('store_id');
            $table->index('customer_id');
            $table->index('status');
            $table->index('store_branch_id');
            $table->index('due_date');
            $table->index('created_at');
            $table->index('payment_status');
            // Composite index for the most common query pattern:
            // "get all jobs for a store with a specific status"
            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'store_branch_id']);
        });

        // ── appointments ──────────────────────────────────────────────────────
        // Frequently queried by: store_id, customer_id, status, store_branch_id,
        // scheduled_at, appointment_type
        Schema::table('appointments', function (Blueprint $table) {
            $table->index('store_id');
            $table->index('customer_id');
            $table->index('status');
            $table->index('store_branch_id');
            $table->index('scheduled_at');
            $table->index('appointment_type');
            // Composite indexes for common query patterns
            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'store_branch_id']);
            $table->index(['store_id', 'scheduled_at']);
            $table->index(['store_branch_id', 'scheduled_at']);
        });

        // ── payments ──────────────────────────────────────────────────────────
        // Frequently queried by: job_order_id, store_id (via join), rejected_at
        Schema::table('payments', function (Blueprint $table) {
            $table->index('job_order_id');
            $table->index('rejected_at');
            $table->index('created_at');
        });

        // ── staff_profiles ────────────────────────────────────────────────────
        // Frequently queried by: store_id, user_id, store_branch_id, is_active
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->index('store_id');
            $table->index('user_id');
            $table->index('store_branch_id');
            $table->index('is_active');
            $table->index(['store_id', 'is_active']);
        });

        // ── users ─────────────────────────────────────────────────────────────
        // Frequently queried by: name lookups (email already has a unique index)
        Schema::table('users', function (Blueprint $table) {
            $table->string('name', 191)->change();
            $table->index('name');
        });

        // ── store_customers pivot ──────────────────────────────────────────────
        // Frequently queried by: user_id (inverse lookup)
        Schema::table('store_customers', function (Blueprint $table) {
            $table->index('user_id');
        });

        // ── job_order_staff pivot ─────────────────────────────────────────────
        // Frequently queried by: user_id (staff workload queries)
        Schema::table('job_order_staff', function (Blueprint $table) {
            $table->index('user_id');
            $table->index(['user_id', 'completed_at']);
        });

        // ── services ──────────────────────────────────────────────────────────
        // Frequently queried by: store_id, is_active
        Schema::table('services', function (Blueprint $table) {
            $table->index('store_id');
            $table->index(['store_id', 'is_active']);
        });

        // ── catalog_items ─────────────────────────────────────────────────────
        // Frequently queried by: store_id, is_active, garment_type
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->index('store_id');
            $table->index(['store_id', 'is_active']);
            $table->index('garment_type');
        });

        // ── store_branches ─────────────────────────────────────────────────────
        Schema::table('store_branches', function (Blueprint $table) {
            $table->index('store_id');
        });

        // ── notifications ─────────────────────────────────────────────────────
        // Frequently queried by: notifiable_id (polymorphic), read_at
        Schema::table('notifications', function (Blueprint $table) {
            $table->index('notifiable_id');
            $table->index(['notifiable_id', 'read_at']);
        });

        // ── measurements ──────────────────────────────────────────────────────
        Schema::table('measurements', function (Blueprint $table) {
            $table->index('store_id');
            $table->index('customer_id');
            $table->index(['store_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        // Remove indexes in reverse order
        Schema::table('measurements', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'customer_id']);
            $table->dropIndex('measurements_customer_id');
            $table->dropIndex('measurements_store_id');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['notifiable_id', 'read_at']);
            $table->dropIndex('notifications_notifiable_id');
        });

        Schema::table('store_branches', function (Blueprint $table) {
            $table->dropIndex('store_branches_store_id');
        });

        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'is_active']);
            $table->dropIndex('catalog_items_garment_type');
            $table->dropIndex('catalog_items_store_id');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'is_active']);
            $table->dropIndex('services_store_id');
        });

        Schema::table('job_order_staff', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'completed_at']);
            $table->dropIndex('job_order_staff_user_id');
        });

        Schema::table('store_customers', function (Blueprint $table) {
            $table->dropIndex('store_customers_user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['name']);
        });

        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'is_active']);
            $table->dropIndex('staff_profiles_is_active');
            $table->dropIndex('staff_profiles_store_branch_id');
            $table->dropIndex('staff_profiles_user_id');
            $table->dropIndex('staff_profiles_store_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_created_at');
            $table->dropIndex('payments_rejected_at');
            $table->dropIndex('payments_job_order_id');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['store_branch_id', 'scheduled_at']);
            $table->dropIndex(['store_id', 'scheduled_at']);
            $table->dropIndex(['store_id', 'store_branch_id']);
            $table->dropIndex(['store_id', 'status']);
            $table->dropIndex('appointments_appointment_type');
            $table->dropIndex('appointments_scheduled_at');
            $table->dropIndex('appointments_store_branch_id');
            $table->dropIndex('appointments_status');
            $table->dropIndex('appointments_customer_id');
            $table->dropIndex('appointments_store_id');
        });

        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'store_branch_id']);
            $table->dropIndex(['store_id', 'status']);
            $table->dropIndex('job_orders_payment_status');
            $table->dropIndex('job_orders_created_at');
            $table->dropIndex('job_orders_due_date');
            $table->dropIndex('job_orders_store_branch_id');
            $table->dropIndex('job_orders_status');
            $table->dropIndex('job_orders_customer_id');
            $table->dropIndex('job_orders_store_id');
        });
    }
};
