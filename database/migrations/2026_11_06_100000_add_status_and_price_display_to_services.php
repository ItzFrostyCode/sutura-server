<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Services decisions S2 and B6-O11 (reference-repos/Services/11):
 * - status: Draft, Published, Unpublished, Archived (replaces the single is_active flag; is_active is kept in sync
 *   as "status = published" so every existing query keeps working).
 * - price_display: Fixed price, Starting price or Quotation required (no range).
 * Existing rows: active -> published, inactive -> unpublished, soft-deleted -> archived. Price display: a service
 * with a price or a priced option shows a starting price; one with none needs a quotation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('status', 12)->default('published')->after('is_active');
            $table->string('price_display', 12)->default('starting')->after('base_price');
            $table->index(['store_id', 'status']);
        });

        DB::table('services')->whereNotNull('deleted_at')->update(['status' => 'archived', 'is_active' => false]);
        DB::table('services')->whereNull('deleted_at')->where('is_active', false)->update(['status' => 'unpublished']);
        DB::table('services')->whereNull('deleted_at')->where('is_active', true)->update(['status' => 'published']);

        $priced = DB::table('service_pricing')->where('amount', '>', 0)->distinct()->pluck('service_id')->all();
        DB::table('services')
            ->where(fn ($q) => $q->whereNull('base_price')->orWhere('base_price', '<=', 0))
            ->whereNotIn('id', $priced === [] ? [0] : $priced)
            ->update(['price_display' => 'quotation']);
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'status']);
            $table->dropColumn(['status', 'price_display']);
        });
    }
};
