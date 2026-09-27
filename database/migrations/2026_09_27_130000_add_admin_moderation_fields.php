<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin takedowns (post-moderation). Kept separate from stores.is_hidden and
 * catalog_items.is_active on purpose: those two are owner-controlled (and
 * is_hidden is also cleared on subscription renewal), so reusing them alone
 * would let a taken-down listing switch itself back on. admin_hidden_at is
 * the lock only an admin can lift. support_tickets.catalog_item_id links a
 * "Report this product" ticket to the actual item the admin may act on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->timestamp('admin_hidden_at')->nullable();
            $table->text('admin_hidden_reason')->nullable();
        });

        Schema::table('catalog_items', function (Blueprint $table) {
            $table->timestamp('admin_hidden_at')->nullable();
            $table->text('admin_hidden_reason')->nullable();
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->foreignId('catalog_item_id')->nullable()->constrained('catalog_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('catalog_item_id');
        });

        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn(['admin_hidden_at', 'admin_hidden_reason']);
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['admin_hidden_at', 'admin_hidden_reason']);
        });
    }
};
