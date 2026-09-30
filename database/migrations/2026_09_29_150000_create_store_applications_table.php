<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from the sutura2 System Admin prototype's shop_registrations table,
 * mapped onto this schema instead of duplicating it: the store itself (name,
 * address, pin, specializations) lives on `stores` with status=pending, and
 * the applicant's own login is a real `users` row they set a password for.
 * This table only holds what the admin needs to verify the owner — personal
 * details, business documents, and the plan/payment they applied with.
 *
 * Documents are stored on the private `local` disk (never `public`) since
 * they include government IDs; Admin\StoreApplicationController streams them
 * to admins only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('suffix', 20)->nullable();
            $table->date('birthday');
            $table->string('contact_number', 30);

            $table->foreignId('requested_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->string('billing_cycle', 20)->default('monthly');
            $table->decimal('quoted_price', 10, 2)->default(0);
            $table->string('payment_method', 50)->nullable();
            $table->text('payment_receipt_path')->nullable();

            $table->text('landmark_image_path');
            $table->text('dti_registration_path');
            $table->text('tin_id_path');
            $table->text('brgy_clearance_path');
            $table->text('government_id_path');
            $table->string('government_id_type', 100);
            $table->json('business_permit_paths')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        // Admin-side account suspension (sutura2's users.status), kept as a
        // timestamp so "when" is recorded. Not fillable — only
        // Admin\AccountController sets it.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('last_seen_at');
        });

        // Platform-level admin actions (sign-ins, account suspensions) have
        // no store to hang off; store-scoped audit views filter by store_id
        // so these rows never leak into a shop owner's Activity Log.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('suspended_at');
        });

        Schema::dropIfExists('store_applications');
    }
};
