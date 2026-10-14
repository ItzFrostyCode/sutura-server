<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which "Service Categories" chip a service sits under on its shop's
 * storefront (CanonicalTaxonomy::SERVICE_SHOWCASE_CATEGORIES). Existing
 * services get a best guess from their name and category; owners can
 * change it in the service editor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('showcase_category', 30)->nullable()->after('service_category');
        });

        DB::table('services')->orderBy('id')->get(['id', 'name', 'service_category'])->each(function ($s) {
            DB::table('services')->where('id', $s->id)->update(['showcase_category' => self::guess($s->name, $s->service_category)]);
        });
    }

    /** Name keywords first (most specific), then the service's category. */
    private static function guess(?string $name, ?string $category): string
    {
        $n = ' '.strtolower((string) $name).' ';
        $rules = [
            'school' => ['school'],
            'medical' => ['medical', 'scrub', 'nurse', 'hospital'],
            'maritime' => ['maritime', 'seaman', 'seafarer', 'marine', 'nautical'],
            'office' => ['office', 'corporate shirt', 'corporate uniform', 'blazer'],
            'embroidery' => ['embroider'],
            'sports' => ['jersey', 'sport', 'team', 'athletic'],
            'uniform' => ['uniform'],
            'casual' => ['casual', 'polo', 'everyday'],
            'formal' => ['suit', 'tuxedo', 'barong', 'gown', 'bridal', 'wedding', 'formal', 'costume'],
            'alterations_repairs' => ['alteration', 'repair', 'hemming', 'resiz'],
        ];
        foreach ($rules as $group => $words) {
            foreach ($words as $w) {
                if (str_contains($n, $w)) {
                    return $group;
                }
            }
        }

        return match ($category) {
            'uniform_production' => 'uniform',
            'printing_sublimation' => 'sports',
            'alterations_repairs' => 'alterations_repairs',
            'custom_costume_creation' => 'formal',
            default => 'custom_tailoring',
        };
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('showcase_category');
        });
    }
};
