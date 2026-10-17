<?php

use App\Support\CanonicalTaxonomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * "Validate apparel categories": a typed-in (custom) apparel name — the
 * free-text garment_type a shop can enter under Children's / Others / Other —
 * waits for the System Admin before a design using it is shown publicly.
 * Each name is approved once (allow-list), then reused by every shop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_apparel_types', function (Blueprint $table) {
            $table->id();
            $table->string('label', 100);
            $table->string('normalized', 100)->unique();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->text('rejection_reason')->nullable();
            $table->foreignId('requested_by_store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::table('catalog_items', function (Blueprint $table) {
            $table->foreignId('custom_apparel_type_id')->nullable()->after('garment_type')->constrained('custom_apparel_types')->nullOnDelete();
        });

        // Designs already live with a custom name stay live: their names
        // start out approved instead of vanishing from storefronts.
        $fixed = CanonicalTaxonomy::knownGarmentSlugs();
        DB::table('catalog_items')->whereNotNull('garment_type')->whereNotIn('garment_type', $fixed)
            ->orderBy('id')->get(['id', 'store_id', 'garment_type'])
            ->each(function ($item) {
                $label = Str::squish((string) $item->garment_type);
                if ($label === '') {
                    return;
                }
                $normalized = Str::lower($label);
                $typeId = DB::table('custom_apparel_types')->where('normalized', $normalized)->value('id')
                    ?? DB::table('custom_apparel_types')->insertGetId([
                        'label' => $label, 'normalized' => $normalized, 'status' => 'approved',
                        'requested_by_store_id' => $item->store_id, 'reviewed_at' => now(),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                DB::table('catalog_items')->where('id', $item->id)->update(['custom_apparel_type_id' => $typeId]);
            });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('custom_apparel_type_id');
        });
        Schema::dropIfExists('custom_apparel_types');
    }
};
