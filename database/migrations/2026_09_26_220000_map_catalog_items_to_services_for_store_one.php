<?php

use App\Models\CatalogItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Bridal & Wedding Gown Design (Service ID 4)
        $gowns = [1, 2, 4, 5, 9, 12, 15, 23, 28, 41, 42, 44, 47, 49, 50];
        CatalogItem::where('store_id', 1)->whereIn('id', $gowns)->update(['service_id' => 4]);

        // 2. Custom Sublimation Team Jerseys (Service ID 1)
        $sublimation = [3, 6, 7, 8, 11, 17, 18, 20, 21, 22, 24, 29, 30, 31, 33, 35, 36, 38, 43, 46, 48];
        CatalogItem::where('store_id', 1)->whereIn('id', $sublimation)->update(['service_id' => 1]);

        // 3. Bespoke Suit Tailoring (Service ID 2)
        $suits = [13, 14, 19, 25, 26, 27, 34, 39];
        CatalogItem::where('store_id', 1)->whereIn('id', $suits)->update(['service_id' => 2]);

        // 4. Barong Tagalog Tailoring (Service ID 3)
        $barongs = [16, 32, 37, 40, 45];
        CatalogItem::where('store_id', 1)->whereIn('id', $barongs)->update(['service_id' => 3]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        CatalogItem::where('store_id', 1)->where('id', '!=', 3)->update(['service_id' => null]);
    }
};
