<?php

use App\Support\CanonicalTaxonomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2-level garment taxonomy + the 4 approved services (2026-10-14, adviser/panel directive).
 *
 * Additive and reversible: no column is dropped or renamed and every changed value is kept beside it.
 * - Designs: an old 4-level garment slug becomes the garment it was merged into
 *   (CanonicalTaxonomy::LEGACY_GARMENT_SLUGS); the old slug is kept in `legacy_garment_type`.
 *   department / subcategory / garment_structure are left as they are (hidden, unused).
 * - Services and combos in a category that is no longer offered move to an approved one; the old value
 *   is kept in `legacy_service_category`. Printing & Sublimation: an embroidery service goes to
 *   Garment Customization, any other to Uniform, Jersey & Teamwear. Custom Costume Creation goes to
 *   Custom Tailoring (its garments become the Costumes category).
 * - Services get `garment_types` (what clothing they work on) and `capabilities` (which tasks), pre-filled
 *   from their own designs, their old sub-type and their price rows. The owner can change both.
 *
 * Uses CanonicalTaxonomy's old-slug map, which is historical data and never changes.
 */
return new class extends Migration
{
    /** Old single sub-type => capability, where the meaning is the same. */
    private const LEAF_CAPABILITY = [
        'bespoke_tailoring' => 'bespoke_pattern_making',
        'custom_pattern_making' => 'bespoke_pattern_making',
        'made_to_measure' => 'made_to_measure',
        'hemming_length_adjustment' => 'hemming_length_adjustment',
        'waist_seat_adjustment' => 'waist_seat_adjustment',
        'tapering_resizing' => 'tapering_resizing',
        'sleeve_adjustment' => 'sleeve_adjustment',
        'zipper_replacement' => 'zipper_replacement',
        'button_fastener_replacement' => 'button_fastener_replacement',
        'garment_repair_restoration' => 'garment_repair_mending',
    ];

    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->string('legacy_garment_type', 100)->nullable()->after('garment_type');
        });
        Schema::table('services', function (Blueprint $table) {
            $table->json('garment_types')->nullable()->after('service_leaf_type');
            $table->json('capabilities')->nullable()->after('garment_types');
            $table->string('legacy_service_category', 40)->nullable()->after('service_category');
        });
        Schema::table('service_packages', function (Blueprint $table) {
            $table->string('legacy_service_category', 60)->nullable()->after('service_category');
        });

        // 1. Designs: old slug -> the garment it was merged into.
        DB::table('catalog_items')->whereNotNull('garment_type')->orderBy('id')
            ->get(['id', 'garment_type'])
            ->each(function ($row) {
                $old = strtolower(trim((string) $row->garment_type));
                $new = CanonicalTaxonomy::LEGACY_GARMENT_SLUGS[$old] ?? null;
                if ($new !== null && $new !== $row->garment_type) {
                    DB::table('catalog_items')->where('id', $row->id)
                        ->update(['garment_type' => $new, 'legacy_garment_type' => $row->garment_type]);
                }
            });

        // 2. Services: category, garments, capabilities.
        DB::table('services')->orderBy('id')
            ->get(['id', 'name', 'service_category', 'showcase_category', 'service_leaf_type', 'service_types'])
            ->each(function ($s) {
                $update = [];
                $group = $s->service_category;
                if ($group === 'printing_sublimation' || $group === 'custom_costume_creation') {
                    $group = $this->newGroup($s);
                    $update['service_category'] = $group;
                    $update['legacy_service_category'] = $s->service_category;
                }

                $garments = DB::table('catalog_items')->where('service_id', $s->id)->whereNotNull('garment_type')
                    ->pluck('garment_type')
                    ->map(fn ($g) => CanonicalTaxonomy::canonicalGarment($g))
                    ->filter()->values()->all();
                if ($s->service_category === 'custom_costume_creation') {
                    $garments = [...$garments, ...CanonicalTaxonomy::garmentsInCategory('costumes')];
                }
                $garments = array_values(array_unique($garments));
                if ($garments !== []) {
                    $update['garment_types'] = json_encode($garments);
                }

                if ($group && isset(CanonicalTaxonomy::SERVICE_GROUPS[$group])) {
                    $caps = $this->capabilitiesFor($s, $group);
                    if ($caps !== []) {
                        $update['capabilities'] = json_encode($caps);
                    }
                }

                if ($update !== []) {
                    DB::table('services')->where('id', $s->id)->update($update);
                }
            });

        // 3. Combos: same category move.
        foreach (['printing_sublimation', 'custom_costume_creation'] as $old) {
            DB::table('service_packages')->where('service_category', $old)->update([
                'legacy_service_category' => $old,
                'service_category' => CanonicalTaxonomy::LEGACY_SERVICE_CATEGORIES[$old],
            ]);
        }
    }

    public function down(): void
    {
        DB::table('catalog_items')->whereNotNull('legacy_garment_type')
            ->update(['garment_type' => DB::raw('legacy_garment_type')]);
        DB::table('services')->whereNotNull('legacy_service_category')
            ->update(['service_category' => DB::raw('legacy_service_category')]);
        DB::table('service_packages')->whereNotNull('legacy_service_category')
            ->update(['service_category' => DB::raw('legacy_service_category')]);

        Schema::table('catalog_items', fn (Blueprint $table) => $table->dropColumn('legacy_garment_type'));
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn(['garment_types', 'capabilities', 'legacy_service_category']));
        Schema::table('service_packages', fn (Blueprint $table) => $table->dropColumn('legacy_service_category'));
    }

    private function newGroup(object $s): string
    {
        if ($s->service_category === 'custom_costume_creation') {
            return 'custom_tailoring';
        }
        $isEmbroidery = $s->showcase_category === 'embroidery' || str_contains(strtolower((string) $s->name), 'embroider');

        return $isEmbroidery ? 'garment_customization' : 'uniform_production';
    }

    /** @return list<string> */
    private function capabilitiesFor(object $s, string $group): array
    {
        $allowed = CanonicalTaxonomy::capabilitiesOf($group);
        $caps = [];
        $leaf = self::LEAF_CAPABILITY[$s->service_leaf_type] ?? null;
        if ($leaf && in_array($leaf, $allowed, true)) {
            $caps[] = $leaf;
        }
        // Each price row on its own, so words from two rows never combine into a match.
        foreach (DB::table('service_pricing')->where('service_id', $s->id)->pluck('label') as $label) {
            $caps = [...$caps, ...CanonicalTaxonomy::capabilitiesMentionedIn($group, (string) $label)];
        }
        $types = json_decode((string) $s->service_types, true) ?: [];
        if ($group === 'uniform_production' && in_array('bulk_sublimation', $types, true)) {
            $caps[] = 'bulk_orders';
        }
        // "We cut and sew jerseys" is the line between a tailoring shop and a print-only shop — only the owner says it.
        $caps = array_diff($caps, ['cut_and_sew_jersey']);

        return array_values(array_intersect($allowed, array_unique($caps)));
    }
};
