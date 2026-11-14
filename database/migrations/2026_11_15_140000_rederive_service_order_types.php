<?php

use App\Support\OfferingDataRepair;
use Illuminate\Database\Migrations\Migration;

/**
 * Re-works out every service's order types from what it is (Service::deriveOrderTypes), so stored rows match the
 * current rules: a uniform service that takes both individual and bulk orders is no longer group-only, and
 * embroidery on the customer's own clothes uses the short repair flow. Services with no approved group are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        OfferingDataRepair::questionsAndTypes();
    }

    public function down(): void
    {
        // Derived data: nothing to restore.
    }
};
