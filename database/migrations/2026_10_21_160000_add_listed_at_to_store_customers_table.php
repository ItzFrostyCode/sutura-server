<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is on a shop's Customers list. Someone becomes a customer of the shop when an order is made for them; until then a
 * booking or a consultation only makes them someone the shop can pick (for a visit or an order). The one other way onto
 * the list is the shop adding them on the Customers page itself: that is `listed_at`. Existing rows stay unlisted
 * (they were all made by bookings and consultations), so only people with an order show until the shop adds others.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_customers', function (Blueprint $table) {
            $table->timestamp('listed_at')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('store_customers', function (Blueprint $table) {
            $table->dropColumn('listed_at');
        });
    }
};
