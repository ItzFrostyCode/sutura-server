<?php

namespace App\Support;

/**
 * The single source of truth for SUTURA's apparel + services taxonomy,
 * transcribed directly from Mocked Layout/Categories.md and Services.md
 * (the canonical "Apparel & Services Directory" the header nav, /search,
 * and the catalog/service forms all key off). Mirrored exactly on the
 * frontend at src/lib/canonicalTaxonomy.ts — keep both in sync by hand,
 * same convention this codebase already uses for catalogCategories.ts
 * mirroring CatalogItem's old constants.
 *
 * Shape: DEPARTMENTS[department]['subcategories'][subcategory]['structures'][structure] = string[] of garment_type slugs.
 * Children's Apparel (per Categories.md) has no defined leaf garment types
 * yet — its structures exist (top_wear/bottom_wear/sets) but their arrays
 * are deliberately empty, not invented data.
 */
class CanonicalTaxonomy
{
    public const DEPARTMENTS = ['men', 'women', 'children'];

    public const DEPARTMENT_LABELS = [
        'men' => "Men's Apparel",
        'women' => "Women's Apparel",
        'children' => "Children's Apparel",
    ];

    // 'other' is a deliberate safety valve, not a doc-defined structure —
    // every department's subcategory list ends in an "Others" entry (see
    // tree()) so a garment that doesn't fit the canonical hierarchy still
    // has somewhere to go under the right department, rather than an owner
    // being unable to list it at all.
    public const GARMENT_STRUCTURES = ['top_wear', 'bottom_wear', 'sets', 'one_piece', 'other'];

    public const GARMENT_STRUCTURE_LABELS = [
        'top_wear' => 'Top Wear',
        'bottom_wear' => 'Bottom Wear',
        'sets' => 'Sets',
        'one_piece' => 'One-Piece',
        'other' => 'Other',
    ];

    public const SUBCATEGORY_LABELS = [
        'formal_wear' => 'Formal Wear',
        'traditional_wear' => 'Traditional Wear',
        'traditional_cultural_wear' => 'Traditional & Cultural Wear',
        'dresses_gowns' => 'Dresses & Gowns',
        'casual_wear' => 'Casual Wear',
        'uniforms_workwear' => 'Uniforms & Workwear',
        'sportswear_teamwear' => 'Sportswear & Teamwear',
        'costumes_performance' => 'Costumes & Performance',
        'outerwear' => 'Outerwear',
        'boys_apparel' => "Boys' Apparel",
        'girls_apparel' => "Girls' Apparel",
        'others' => 'Others',
    ];

    public const GARMENT_TYPE_LABELS = [
        // Men — Formal Wear
        'dress_shirts' => 'Dress Shirts', 'formal_shirts' => 'Formal Shirts', 'formal_vests' => 'Formal Vests',
        'formal_trousers' => 'Formal Trousers', 'dress_pants' => 'Dress Pants', 'pleated_trousers' => 'Pleated Trousers',
        'suits' => 'Suits', 'tuxedo_sets' => 'Tuxedo Sets', 'formal_sets' => 'Formal Sets',
        // Men — Traditional Wear
        'barong_tagalog' => 'Barong Tagalog', 'modern_barong' => 'Modern Barong', 'short_sleeve_barong' => 'Short-Sleeve Barong',
        'other_traditional_tops' => 'Other Traditional Tops', 'traditional_trousers' => 'Traditional Trousers',
        'traditional_pants' => 'Traditional Pants', 'barong_sets' => 'Barong Sets', 'traditional_wear_sets' => 'Traditional Wear Sets',
        // Men — Casual Wear
        'polo_shirts' => 'Polo Shirts', 'casual_shirts' => 'Casual Shirts', 'button_down_shirts' => 'Button-Down Shirts',
        'custom_t_shirts' => 'Custom T-Shirts', 'casual_trousers' => 'Casual Trousers', 'chinos' => 'Chinos',
        'casual_pants' => 'Casual Pants', 'casual_sets' => 'Casual Sets',
        // Men/Women — Uniforms & Workwear
        'school_uniform_tops' => 'School Uniform Tops', 'corporate_shirts' => 'Corporate Shirts',
        'office_uniform_tops' => 'Office Uniform Tops', 'hospitality_tops' => 'Hospitality Tops', 'medical_tops' => 'Medical Tops',
        'school_uniform_pants' => 'School Uniform Pants', 'corporate_trousers' => 'Corporate Trousers',
        'office_uniform_pants' => 'Office Uniform Pants', 'school_uniform_sets' => 'School Uniform Sets',
        'corporate_uniform_sets' => 'Corporate Uniform Sets', 'institutional_uniform_sets' => 'Institutional Uniform Sets',
        // Men/Women — Sportswear & Teamwear
        'basketball_jerseys' => 'Basketball Jerseys', 'volleyball_jerseys' => 'Volleyball Jerseys',
        'esports_jerseys' => 'Esports Jerseys', 'cycling_jerseys' => 'Cycling Jerseys',
        'basketball_shorts' => 'Basketball Shorts', 'volleyball_shorts' => 'Volleyball Shorts', 'team_shorts' => 'Team Shorts',
        'basketball_sets' => 'Basketball Sets', 'volleyball_sets' => 'Volleyball Sets', 'esports_sets' => 'Esports Sets',
        'custom_team_sets' => 'Custom Team Sets',
        // Men — Costumes & Performance
        'costume_tops' => 'Costume Tops', 'character_tops' => 'Character Tops', 'stage_tops' => 'Stage Tops',
        'costume_pants' => 'Costume Pants', 'character_bottoms' => 'Character Bottoms', 'stage_bottoms' => 'Stage Bottoms',
        'full_costumes' => 'Full Costumes', 'stage_costumes' => 'Stage Costumes', 'cosplay_sets' => 'Cosplay Sets',
        // Men — Outerwear
        'jackets' => 'Jackets', 'coats' => 'Coats', 'overshirts' => 'Overshirts', 'lightweight_outerwear' => 'Lightweight Outerwear',
        // Women — Formal Wear
        'formal_blouses' => 'Formal Blouses', 'formal_tops' => 'Formal Tops', 'formal_boleros' => 'Formal Boleros',
        'formal_skirts' => 'Formal Skirts', 'formal_pants' => 'Formal Pants', 'tailored_trousers' => 'Tailored Trousers',
        'womens_suits' => "Women's Suits",
        // Women — Traditional & Cultural Wear
        'filipiniana_tops' => 'Filipiniana Tops', 'terno_tops' => 'Terno Tops', 'baro' => 'Baro',
        'maria_clara_tops' => 'Maria Clara Tops', 'modern_filipiniana_tops' => 'Modern Filipiniana Tops',
        'saya' => 'Saya', 'traditional_skirts' => 'Traditional Skirts',
        'filipiniana_sets' => 'Filipiniana Sets', 'terno_sets' => 'Terno Sets', 'barot_saya_sets' => "Baro't Saya Sets",
        // Women — Dresses & Gowns
        'casual_dresses' => 'Casual Dresses', 'formal_dresses' => 'Formal Dresses', 'evening_dresses' => 'Evening Dresses',
        'debut_gowns' => 'Debut Gowns', 'custom_gowns' => 'Custom Gowns',
        // Women — Casual Wear
        'casual_blouses' => 'Casual Blouses', 'casual_tops' => 'Casual Tops', 'polo_blouses' => 'Polo Blouses',
        'casual_skirts' => 'Casual Skirts', 'trousers' => 'Trousers',
        // Women — Uniforms & Workwear
        'corporate_blouses' => 'Corporate Blouses', 'institutional_tops' => 'Institutional Tops',
        'blazers' => 'Blazers',
        'school_uniform_skirts' => 'School Uniform Skirts', 'corporate_skirts' => 'Corporate Skirts',
    ];

    // 'others' is the same deliberate safety valve as garment structure
    // 'other' above — not in Services.md, added so a service that doesn't
    // fit any defined category still has somewhere to go.
    public const SERVICE_CATEGORIES = [
        'custom_tailoring', 'alterations_repairs', 'uniform_production', 'printing_sublimation', 'custom_costume_creation', 'others',
        // The 4th approved service (2026-10-14); see SERVICE_GROUPS. The two old ones above stay valid until phase 3.
        'garment_customization',
    ];

    public const SERVICE_CATEGORY_LABELS = [
        'custom_tailoring' => 'Custom Tailoring & Garment Making',
        'alterations_repairs' => 'Alterations & Repairs',
        'uniform_production' => 'Uniform, Jersey & Teamwear Production',
        'printing_sublimation' => 'Printing & Sublimation',
        'custom_costume_creation' => 'Custom Costume Creation',
        'others' => 'Others',
        'garment_customization' => 'Garment Customization & Embroidery',
    ];

    public const SERVICE_TYPE_LABELS = [
        'bespoke_tailoring' => 'Bespoke Tailoring', 'made_to_measure' => 'Made-to-Measure',
        'custom_pattern_making' => 'Custom Pattern Making', 'formal_wear_tailoring' => 'Formal Wear Tailoring',
        'traditional_wear_tailoring' => 'Traditional Wear Tailoring', 'custom_garment_construction' => 'Custom Garment Construction',
        'hemming_length_adjustment' => 'Hemming & Length Adjustment', 'waist_seat_adjustment' => 'Waist & Seat Adjustment',
        'tapering_resizing' => 'Tapering & Resizing', 'sleeve_adjustment' => 'Sleeve Adjustment',
        'zipper_replacement' => 'Zipper Replacement', 'button_fastener_replacement' => 'Button & Fastener Replacement',
        'garment_repair_restoration' => 'Garment Repair & Restoration',
        'school_uniforms' => 'School Uniforms', 'corporate_uniforms' => 'Corporate Uniforms', 'office_uniforms' => 'Office Uniforms',
        'medical_uniforms' => 'Medical Uniforms', 'hospitality_uniforms' => 'Hospitality Uniforms', 'institutional_uniforms' => 'Institutional Uniforms',
        'sports_jersey_printing' => 'Sports Jersey Printing', 'full_sublimation' => 'Full Sublimation',
        'teamwear_printing' => 'Teamwear Printing', 'corporate_apparel_printing' => 'Corporate Apparel Printing',
        'custom_apparel_printing' => 'Custom Apparel Printing',
        'cosplay_costumes' => 'Cosplay Costumes', 'stage_theater_costumes' => 'Stage & Theater Costumes',
        'event_costumes' => 'Event Costumes', 'character_costumes' => 'Character Costumes',
        'cultural_dance_costumes' => 'Cultural & Dance Costumes',
        'logo_embroidery' => 'School & Company Logo Embroidery', 'name_rank_embroidery' => 'Name, Rank & Title Embroidery',
        'badge_patch_attachment' => 'Badge & Patch Attachment', 'monogram_callado' => 'Monogram, Initials & Barong Callado Embroidery',
    ];

    /**
     * What a store can say it specializes in — the same top-level axes the
     * header nav uses (Men/Women/Kids departments + each Services category),
     * so a store's own tags line up with how customers actually browse.
     * Replaces the old free-floating barong/gown/suit/... list, which didn't
     * map onto any part of this taxonomy.
     */
    public static function storeSpecializations(): array
    {
        // Men / Women / Kids are no longer offered (2026-10-14): who wears it is not a category.
        return self::STORE_SERVICE_TAGS;
    }

    /**
     * The "services offered" a shop ticks at registration / in its profile —
     * modelled on how Davao shops list them on their own signboards
     * (Formal, Casual, Office, School, Maritime, Medical, Sports, Embroidery),
     * plus the two core tailoring services. A store tag only: separate from
     * SERVICE_CATEGORIES, which classifies individual services and packages.
     */
    public const STORE_SERVICE_TAGS = [
        'custom_tailoring', 'alterations_repairs',
        'formal', 'casual', 'office', 'school', 'maritime', 'medical', 'sports', 'embroidery',
    ];

    /**
     * Tags no longer offered but still on shops that saved them earlier.
     * Accepted on save so those shops can still update their profile.
     */
    public const LEGACY_STORE_SERVICE_TAGS = [
        'uniform_production', 'printing_sublimation', 'custom_costume_creation', 'others',
        // Departments, offered until 2026-10-14; still accepted so those shops can save their profile.
        'men', 'women', 'children',
    ];

    /**
     * How a shop groups its own services on its storefront's Services tab
     * ("Service Categories" chips: Uniform, Casual, Office…). Set per service
     * by the owner (services.showcase_category); a service's catalog designs
     * are browsed from inside it. Separate from SERVICE_CATEGORIES, which
     * classifies services for search and job-order handling.
     */
    public const SERVICE_SHOWCASE_CATEGORIES = [
        'uniform', 'casual', 'office', 'formal', 'sports', 'school', 'medical', 'maritime', 'embroidery',
        'custom_tailoring', 'alterations_repairs',
    ];

    /** What validation accepts for a store's specializations. */
    public static function selectableStoreSpecializations(): array
    {
        return [...self::storeSpecializations(), ...self::LEGACY_STORE_SERVICE_TAGS];
    }

    /** department => [ subcategory => [ structure => garment_type[] ] ] */
    public static function tree(): array
    {
        return [
            'men' => [
                'formal_wear' => [
                    'top_wear' => ['dress_shirts', 'formal_shirts', 'formal_vests'],
                    'bottom_wear' => ['formal_trousers', 'dress_pants', 'pleated_trousers'],
                    'sets' => ['suits', 'tuxedo_sets', 'formal_sets'],
                    'other' => [],
                ],
                'traditional_wear' => [
                    'top_wear' => ['barong_tagalog', 'modern_barong', 'short_sleeve_barong', 'other_traditional_tops'],
                    'bottom_wear' => ['traditional_trousers', 'traditional_pants'],
                    'sets' => ['barong_sets', 'traditional_wear_sets'],
                    'other' => [],
                ],
                'casual_wear' => [
                    'top_wear' => ['polo_shirts', 'casual_shirts', 'button_down_shirts', 'custom_t_shirts'],
                    'bottom_wear' => ['casual_trousers', 'chinos', 'casual_pants'],
                    'sets' => ['casual_sets'],
                    'other' => [],
                ],
                'uniforms_workwear' => [
                    'top_wear' => ['school_uniform_tops', 'corporate_shirts', 'office_uniform_tops', 'hospitality_tops', 'medical_tops'],
                    'bottom_wear' => ['school_uniform_pants', 'corporate_trousers', 'office_uniform_pants'],
                    'sets' => ['school_uniform_sets', 'corporate_uniform_sets', 'institutional_uniform_sets'],
                    'other' => [],
                ],
                'sportswear_teamwear' => [
                    'top_wear' => ['basketball_jerseys', 'volleyball_jerseys', 'esports_jerseys', 'cycling_jerseys'],
                    'bottom_wear' => ['basketball_shorts', 'volleyball_shorts', 'team_shorts'],
                    'sets' => ['basketball_sets', 'volleyball_sets', 'esports_sets', 'custom_team_sets'],
                    'other' => [],
                ],
                'costumes_performance' => [
                    'top_wear' => ['costume_tops', 'character_tops', 'stage_tops'],
                    'bottom_wear' => ['costume_pants', 'character_bottoms', 'stage_bottoms'],
                    'sets' => ['full_costumes', 'stage_costumes', 'cosplay_sets'],
                    'other' => [],
                ],
                'outerwear' => [
                    'top_wear' => ['jackets', 'coats', 'overshirts', 'lightweight_outerwear'],
                    'other' => [],
                ],
                // Safety valve — not in Categories.md, deliberately added so
                // an item that doesn't fit any defined subcategory still has
                // somewhere to go under Men rather than nowhere at all.
                'others' => ['other' => []],
            ],
            'women' => [
                'formal_wear' => [
                    'top_wear' => ['formal_blouses', 'formal_tops', 'formal_boleros'],
                    'bottom_wear' => ['formal_skirts', 'formal_pants', 'tailored_trousers'],
                    'sets' => ['womens_suits', 'formal_sets'],
                    'other' => [],
                ],
                'traditional_cultural_wear' => [
                    'top_wear' => ['filipiniana_tops', 'terno_tops', 'baro', 'maria_clara_tops', 'modern_filipiniana_tops'],
                    'bottom_wear' => ['saya', 'traditional_skirts', 'traditional_pants'],
                    'sets' => ['filipiniana_sets', 'terno_sets', 'barot_saya_sets'],
                    'other' => [],
                ],
                'dresses_gowns' => [
                    'one_piece' => ['casual_dresses', 'formal_dresses', 'evening_dresses', 'debut_gowns', 'custom_gowns'],
                    'other' => [],
                ],
                'casual_wear' => [
                    'top_wear' => ['casual_blouses', 'casual_tops', 'polo_blouses'],
                    'bottom_wear' => ['casual_skirts', 'casual_pants', 'trousers'],
                    'sets' => ['casual_sets'],
                    'other' => [],
                ],
                'uniforms_workwear' => [
                    'top_wear' => ['school_uniform_tops', 'corporate_blouses', 'office_uniform_tops', 'institutional_tops', 'blazers'],
                    'bottom_wear' => ['school_uniform_skirts', 'corporate_skirts', 'office_uniform_pants'],
                    'sets' => ['school_uniform_sets', 'corporate_uniform_sets', 'institutional_uniform_sets'],
                    'other' => [],
                ],
                'sportswear_teamwear' => [
                    'top_wear' => ['basketball_jerseys', 'volleyball_jerseys', 'esports_jerseys', 'cycling_jerseys'],
                    'bottom_wear' => ['basketball_shorts', 'volleyball_shorts', 'team_shorts'],
                    'sets' => ['custom_team_sets'],
                    'other' => [],
                ],
                // Costumes & Performance is defined in Categories.md with no
                // leaf garment types yet, same gap as Children's Apparel.
                'costumes_performance' => [
                    'top_wear' => [], 'bottom_wear' => [], 'sets' => [], 'other' => [],
                ],
                'others' => ['other' => []],
            ],
            'children' => [
                // Boys'/Girls' Apparel have no leaf garment types defined in
                // Categories.md — structure exists, leaf lists intentionally
                // empty rather than invented.
                'boys_apparel' => ['top_wear' => [], 'bottom_wear' => [], 'sets' => [], 'other' => []],
                'girls_apparel' => ['top_wear' => [], 'bottom_wear' => [], 'sets' => [], 'other' => []],
                'others' => ['other' => []],
            ],
        ];
    }

    /** service_category => service_type[] */
    public static function servicesTree(): array
    {
        return [
            'custom_tailoring' => [
                'bespoke_tailoring', 'made_to_measure', 'custom_pattern_making',
                'formal_wear_tailoring', 'traditional_wear_tailoring', 'custom_garment_construction',
            ],
            'alterations_repairs' => [
                'hemming_length_adjustment', 'waist_seat_adjustment', 'tapering_resizing',
                'sleeve_adjustment', 'zipper_replacement', 'button_fastener_replacement', 'garment_repair_restoration',
            ],
            'uniform_production' => [
                'school_uniforms', 'corporate_uniforms', 'office_uniforms',
                'medical_uniforms', 'hospitality_uniforms', 'institutional_uniforms',
            ],
            'printing_sublimation' => [
                'sports_jersey_printing', 'full_sublimation', 'teamwear_printing',
                'corporate_apparel_printing', 'custom_apparel_printing',
            ],
            'custom_costume_creation' => [
                'cosplay_costumes', 'stage_theater_costumes', 'event_costumes',
                'character_costumes', 'cultural_dance_costumes',
            ],
            'others' => [],
            'garment_customization' => ['logo_embroidery', 'name_rank_embroidery', 'badge_patch_attachment', 'monogram_callado'],
        ];
    }

    public static function subcategoriesFor(string $department): array
    {
        return array_keys(self::tree()[$department] ?? []);
    }

    public static function structuresFor(string $department, string $subcategory): array
    {
        return array_keys(self::tree()[$department][$subcategory] ?? []);
    }

    public static function garmentTypesFor(string $department, string $subcategory, string $structure): array
    {
        return self::tree()[$department][$subcategory][$structure] ?? [];
    }

    /** Flat, deduped list of every valid garment_type slug — for Rule::in(). */
    public static function allGarmentTypeSlugs(): array
    {
        $slugs = [];
        foreach (self::tree() as $subcats) {
            foreach ($subcats as $structures) {
                foreach ($structures as $types) {
                    $slugs = array_merge($slugs, $types);
                }
            }
        }

        return array_values(array_unique($slugs));
    }

    public static function allSubcategorySlugs(): array
    {
        $slugs = [];
        foreach (self::tree() as $subcats) {
            $slugs = array_merge($slugs, array_keys($subcats));
        }

        return array_values(array_unique($slugs));
    }

    public static function allServiceTypeSlugs(): array
    {
        $slugs = [];
        foreach (self::servicesTree() as $types) {
            $slugs = array_merge($slugs, $types);
        }

        return array_values(array_unique($slugs));
    }

    // ---------------------------------------------------------------------------------------------
    // 2-level garment taxonomy (2026-10-14, adviser/panel directive): Garment Category → Garment.
    // A garment is what is being made, altered or customized; who wears it (men/women/kids) and
    // where it sits on the body (top/bottom/set) are not levels any more. Garments are kept apart
    // from services: a garment answers "what clothing?", a service answers "what work?".
    // The old 4-level tree above stays only until every screen has moved over (phase 5).
    // Mirrored on the client in src/lib/garmentTaxonomy.ts (generated from this file).
    // ---------------------------------------------------------------------------------------------

    /** Garment category => [label, search aliases]. 'other' holds custom garment names. */
    public const GARMENT_CATEGORIES = [
        'barong' => ['label' => 'Barong', 'aliases' => ['barong', 'filipino formal menswear']],
        'traditional' => ['label' => 'Traditional Filipino Wear', 'aliases' => ['traditional', 'filipiniana', 'cultural wear', 'buwan ng wika']],
        'uniforms' => ['label' => 'Uniforms', 'aliases' => ['uniform', 'uniporme', 'workwear']],
        'jerseys_sportswear' => ['label' => 'Jerseys & Sportswear', 'aliases' => ['jersey', 'sportswear', 'teamwear', 'athletic wear']],
        'dresses_gowns' => ['label' => 'Dresses & Gowns', 'aliases' => ['dress', 'gown', 'bestida', 'bridal']],
        'formal_wear' => ['label' => 'Formal Wear', 'aliases' => ['formal', 'suit', 'americana', 'coat and tie']],
        'shirts_tops' => ['label' => 'Shirts & Tops', 'aliases' => ['shirt', 'top', 'polo', 'blouse', 't-shirt']],
        'pants_bottoms' => ['label' => 'Pants & Bottoms', 'aliases' => ['pants', 'trousers', 'slacks', 'jeans', 'pantalon', 'shorts', 'skirt']],
        'outerwear' => ['label' => 'Jackets & Outerwear', 'aliases' => ['jacket', 'coat', 'outerwear', 'hoodie']],
        'costumes' => ['label' => 'Costumes & Performance Wear', 'aliases' => ['costume', 'cosplay', 'stage wear', 'dance wear']],
        'other' => ['label' => 'Other Garments', 'aliases' => []],
    ];

    /** Garment slug => [label, category, search aliases]. 54 garments in the 10 categories. */
    public const GARMENTS = [
        // Barong
        'barong_tagalog' => ['label' => 'Barong Tagalog', 'category' => 'barong', 'aliases' => ['traditional barong', 'long sleeve barong', 'pina barong', 'jusi barong', 'barong set']],
        'modern_barong' => ['label' => 'Modern Barong', 'category' => 'barong', 'aliases' => ['contemporary barong', 'coat barong', 'slim fit barong']],
        'short_sleeve_barong' => ['label' => 'Short-Sleeve Barong', 'category' => 'barong', 'aliases' => ['polo barong', 'office barong', 'short sleeve barong']],
        // Traditional Filipino Wear
        'filipiniana' => ['label' => 'Filipiniana', 'category' => 'traditional', 'aliases' => ['filipiniana dress', 'filipiniana bolero', 'national costume', 'filipiniana set']],
        'modern_filipiniana' => ['label' => 'Modern Filipiniana', 'category' => 'traditional', 'aliases' => ['contemporary filipiniana', 'modern bolero']],
        'barot_saya' => ['label' => "Baro't Saya", 'category' => 'traditional', 'aliases' => ['baro at saya', 'baro', 'saya', 'buwan ng wika attire']],
        'terno' => ['label' => 'Terno', 'category' => 'traditional', 'aliases' => ['butterfly sleeves', 'filipiniana terno', 'sona gown', 'terno gown']],
        'maria_clara' => ['label' => 'Maria Clara', 'category' => 'traditional', 'aliases' => ['maria clara gown', 'traditional maria clara', 'maria clara dress']],
        'traditional_pants_skirts' => ['label' => 'Traditional Pants & Skirts', 'category' => 'traditional', 'aliases' => ['ethnic wear', 'cultural pants', 'cultural skirt', 'mindanao weave']],
        // Uniforms
        'school_uniform' => ['label' => 'School Uniform', 'category' => 'uniforms', 'aliases' => ['student uniform', 'college uniform', 'high school uniform', 'polo uniform', 'school blouse', 'school polo']],
        'school_uniform_pants' => ['label' => 'School Uniform Pants', 'category' => 'uniforms', 'aliases' => ['school slacks', 'student pants']],
        'school_uniform_skirt' => ['label' => 'School Uniform Skirt', 'category' => 'uniforms', 'aliases' => ['pleated school skirt', 'student skirt', 'school skirt']],
        'office_uniform' => ['label' => 'Office Uniform', 'category' => 'uniforms', 'aliases' => ['work uniform', 'employee uniform', 'daily office wear', 'deped uniform']],
        'corporate_uniform' => ['label' => 'Corporate Uniform', 'category' => 'uniforms', 'aliases' => ['company uniform', 'bank uniform', 'executive uniform', 'corporate wear']],
        'medical_uniform' => ['label' => 'Medical Uniform / Scrub Suit', 'category' => 'uniforms', 'aliases' => ['scrub suit', 'scrubs', 'lab coat', 'doctor coat', 'nursing uniform', 'clinical uniform']],
        'hospitality_uniform' => ['label' => 'Hospitality Uniform', 'category' => 'uniforms', 'aliases' => ['hotel uniform', 'chef uniform', 'restaurant uniform', 'apron', 'chef coat']],
        'institutional_uniform' => ['label' => 'Institutional & Security Uniform', 'category' => 'uniforms', 'aliases' => ['security guard uniform', 'security uniform', 'rotc uniform', 'pnp uniform', 'agency uniform', 'toga']],
        // Jerseys & Sportswear
        'basketball_jersey' => ['label' => 'Basketball Jersey', 'category' => 'jerseys_sportswear', 'aliases' => ['basketball uniform', 'liga jersey', 'sando jersey']],
        'basketball_shorts' => ['label' => 'Basketball Shorts', 'category' => 'jerseys_sportswear', 'aliases' => ['jersey shorts', 'sports shorts', 'basketball bottom']],
        'volleyball_jersey' => ['label' => 'Volleyball Jersey', 'category' => 'jerseys_sportswear', 'aliases' => ['volleyball uniform', 'spandex jersey']],
        'volleyball_shorts' => ['label' => 'Volleyball Shorts', 'category' => 'jerseys_sportswear', 'aliases' => ['volleyball spandex shorts', 'spandex shorts', 'volleyball bottom']],
        'cycling_jersey' => ['label' => 'Cycling Jersey', 'category' => 'jerseys_sportswear', 'aliases' => ['bike jersey', 'long sleeve rider shirt', 'rider jersey']],
        'esports_jersey' => ['label' => 'Esports Jersey', 'category' => 'jerseys_sportswear', 'aliases' => ['gaming jersey', 'org jersey', 'collared jersey', 'team shirt']],
        'custom_team_uniform' => ['label' => 'Custom Team Uniform', 'category' => 'jerseys_sportswear', 'aliases' => ['intrams uniform', 'palaro uniform', 'pe uniform', 'track suit', 'warmup set', 'team set']],
        'team_shorts' => ['label' => 'Team Shorts', 'category' => 'jerseys_sportswear', 'aliases' => ['athletic shorts', 'pe shorts', 'training shorts', 'sports shorts']],
        // Dresses & Gowns
        'casual_dress' => ['label' => 'Casual Dress', 'category' => 'dresses_gowns', 'aliases' => ['everyday dress', 'sundress', 'simple dress']],
        'formal_dress' => ['label' => 'Formal Dress', 'category' => 'dresses_gowns', 'aliases' => ['cocktail dress', 'party dress', 'graduation dress', 'civil wedding dress']],
        'evening_gown' => ['label' => 'Evening Gown', 'category' => 'dresses_gowns', 'aliases' => ['long gown', 'gala gown', 'pageant gown', 'entourage gown', 'bridesmaid gown']],
        'debut_gown' => ['label' => 'Debut Gown', 'category' => 'dresses_gowns', 'aliases' => ['18th birthday gown', 'ball gown', 'quinceanera gown']],
        'custom_gown' => ['label' => 'Custom Gown / Bridal Gown', 'category' => 'dresses_gowns', 'aliases' => ['wedding gown', 'bridal dress', 'bridal gown', 'bespoke gown', 'couture gown']],
        // Formal Wear
        'suit' => ['label' => 'Suit', 'category' => 'formal_wear', 'aliases' => ['2-piece suit', '3-piece suit', 'coat and tie', 'americana', 'pantsuit', 'mens suit', 'womens suit']],
        'tuxedo' => ['label' => 'Tuxedo', 'category' => 'formal_wear', 'aliases' => ['tux', 'black tie suit', 'groom suit', 'groom tuxedo']],
        'blazer' => ['label' => 'Blazer', 'category' => 'formal_wear', 'aliases' => ['coat', 'formal jacket', 'office blazer']],
        'formal_vest' => ['label' => 'Formal Vest', 'category' => 'formal_wear', 'aliases' => ['waistcoat', 'suit vest', 'waiter vest', 'vest']],
        'formal_bolero' => ['label' => 'Formal Bolero', 'category' => 'formal_wear', 'aliases' => ['bolero jacket', 'shrug', 'bolero', 'formal shrug']],
        'dress_shirt' => ['label' => 'Dress Shirt', 'category' => 'formal_wear', 'aliases' => ['long sleeves formal', 'tuxedo shirt', 'inner shirt', 'polo long sleeves']],
        'formal_blouse' => ['label' => 'Formal Blouse', 'category' => 'formal_wear', 'aliases' => ['dressy blouse', 'evening top', 'silk blouse', 'formal top']],
        'dress_pants' => ['label' => 'Dress Pants / Formal Trousers', 'category' => 'formal_wear', 'aliases' => ['slacks', 'formal slacks', 'tailored pants', 'pleated pants', 'tailored trousers']],
        'formal_skirt' => ['label' => 'Formal Skirt', 'category' => 'formal_wear', 'aliases' => ['pencil skirt', 'tailored skirt', 'maxi formal skirt']],
        // Shirts & Tops
        'polo_shirt' => ['label' => 'Polo Shirt', 'category' => 'shirts_tops', 'aliases' => ['collared shirt', 'org polo', 'company polo', 'golf shirt', 'polo blouse']],
        'button_down_shirt' => ['label' => 'Button-Down Shirt', 'category' => 'shirts_tops', 'aliases' => ['polo button down', 'short sleeve polo', 'oxford shirt', 'button down']],
        'casual_shirt' => ['label' => 'Casual Shirt', 'category' => 'shirts_tops', 'aliases' => ['everyday shirt', 'woven top', 'casual top']],
        'casual_blouse' => ['label' => 'Casual Blouse', 'category' => 'shirts_tops', 'aliases' => ['womens top', 'everyday blouse', 'blouse']],
        'custom_t_shirt' => ['label' => 'Custom T-Shirt', 'category' => 'shirts_tops', 'aliases' => ['round neck shirt', 'v-neck t-shirt', 'cotton tee', 'tshirt', 'tee shirt']],
        // Pants & Bottoms
        'trousers' => ['label' => 'Trousers / Slacks', 'category' => 'pants_bottoms', 'aliases' => ['pants', 'everyday slacks', 'pantalon', 'everyday trousers']],
        'casual_pants_jeans' => ['label' => 'Casual Pants / Jeans', 'category' => 'pants_bottoms', 'aliases' => ['denim jeans', 'jeans', 'denim', 'maong pants', 'maong', 'cargo pants', 'jogging pants']],
        'chinos' => ['label' => 'Chinos', 'category' => 'pants_bottoms', 'aliases' => ['khaki pants', 'chino trousers', 'chino pants']],
        'casual_skirt' => ['label' => 'Casual Skirt', 'category' => 'pants_bottoms', 'aliases' => ['a-line skirt', 'everyday skirt', 'denim skirt', 'skirt', 'palda']],
        // Jackets & Outerwear
        'jacket' => ['label' => 'Jacket', 'category' => 'outerwear', 'aliases' => ['bomber jacket', 'windbreaker', 'hoodie', 'varsity jacket', 'executive jacket']],
        'coat' => ['label' => 'Coat', 'category' => 'outerwear', 'aliases' => ['trench coat', 'winter coat', 'overcoat']],
        'overshirt' => ['label' => 'Overshirt', 'category' => 'outerwear', 'aliases' => ['shacket', 'utility shirt jacket', 'shirt jacket']],
        // Costumes & Performance Wear
        'cosplay_costume' => ['label' => 'Cosplay Costume', 'category' => 'costumes', 'aliases' => ['anime costume', 'character costume', 'book week costume']],
        'stage_costume' => ['label' => 'Stage & Theater Costume', 'category' => 'costumes', 'aliases' => ['roleplay costume', 'musical costume', 'pageant costume', 'theater costume']],
        'cultural_dance_costume' => ['label' => 'Cultural & Dance Costume', 'category' => 'costumes', 'aliases' => ['folk dance costume', 'cheering uniform', 'festival attire', 'kadayawan costume', 'dance costume']],
    ];

    /**
     * Every old 4-level garment slug => the garment it was merged into (the code-side "merged_into_id").
     * Historical data: never change an entry, only add. Designs saved before 2026-10-14 may still carry
     * an old slug (and re-running the old seeders writes them), so every read resolves through this.
     */
    public const LEGACY_GARMENT_SLUGS = [
        'barong_tagalog' => 'barong_tagalog', 'barong_sets' => 'barong_tagalog',
        'modern_barong' => 'modern_barong', 'short_sleeve_barong' => 'short_sleeve_barong',
        'filipiniana_tops' => 'filipiniana', 'filipiniana_sets' => 'filipiniana',
        'modern_filipiniana_tops' => 'modern_filipiniana',
        'baro' => 'barot_saya', 'saya' => 'barot_saya', 'barot_saya_sets' => 'barot_saya',
        'terno_tops' => 'terno', 'terno_sets' => 'terno',
        'maria_clara_tops' => 'maria_clara',
        'traditional_pants' => 'traditional_pants_skirts', 'traditional_trousers' => 'traditional_pants_skirts',
        'traditional_skirts' => 'traditional_pants_skirts', 'traditional_wear_sets' => 'traditional_pants_skirts',
        'other_traditional_tops' => 'traditional_pants_skirts',
        'school_uniform_sets' => 'school_uniform', 'school_uniform_tops' => 'school_uniform',
        'school_uniform_pants' => 'school_uniform_pants', 'school_uniform_skirts' => 'school_uniform_skirt',
        'office_uniform_tops' => 'office_uniform', 'office_uniform_pants' => 'office_uniform',
        'corporate_uniform_sets' => 'corporate_uniform', 'corporate_shirts' => 'corporate_uniform',
        'corporate_blouses' => 'corporate_uniform', 'corporate_trousers' => 'corporate_uniform',
        'corporate_skirts' => 'corporate_uniform',
        'medical_tops' => 'medical_uniform', 'hospitality_tops' => 'hospitality_uniform',
        'institutional_uniform_sets' => 'institutional_uniform', 'institutional_tops' => 'institutional_uniform',
        'basketball_jerseys' => 'basketball_jersey', 'basketball_sets' => 'basketball_jersey',
        'basketball_shorts' => 'basketball_shorts',
        'volleyball_jerseys' => 'volleyball_jersey', 'volleyball_sets' => 'volleyball_jersey',
        'volleyball_shorts' => 'volleyball_shorts',
        'cycling_jerseys' => 'cycling_jersey',
        'esports_jerseys' => 'esports_jersey', 'esports_sets' => 'esports_jersey',
        'custom_team_sets' => 'custom_team_uniform', 'team_shorts' => 'team_shorts',
        'casual_dresses' => 'casual_dress', 'formal_dresses' => 'formal_dress', 'evening_dresses' => 'evening_gown',
        'debut_gowns' => 'debut_gown', 'custom_gowns' => 'custom_gown',
        'suits' => 'suit', 'womens_suits' => 'suit', 'formal_sets' => 'suit',
        'tuxedo_sets' => 'tuxedo', 'blazers' => 'blazer', 'formal_vests' => 'formal_vest', 'formal_boleros' => 'formal_bolero',
        'dress_shirts' => 'dress_shirt', 'formal_shirts' => 'dress_shirt',
        'formal_blouses' => 'formal_blouse', 'formal_tops' => 'formal_blouse',
        'dress_pants' => 'dress_pants', 'formal_trousers' => 'dress_pants', 'formal_pants' => 'dress_pants',
        'pleated_trousers' => 'dress_pants', 'tailored_trousers' => 'dress_pants',
        'formal_skirts' => 'formal_skirt',
        'polo_shirts' => 'polo_shirt', 'polo_blouses' => 'polo_shirt',
        'button_down_shirts' => 'button_down_shirt',
        'casual_shirts' => 'casual_shirt', 'casual_tops' => 'casual_shirt',
        'casual_blouses' => 'casual_blouse', 'custom_t_shirts' => 'custom_t_shirt',
        'trousers' => 'trousers', 'casual_trousers' => 'trousers',
        'casual_pants' => 'casual_pants_jeans', 'casual_sets' => 'casual_pants_jeans',
        'chinos' => 'chinos', 'casual_skirts' => 'casual_skirt',
        'jackets' => 'jacket', 'lightweight_outerwear' => 'jacket', 'coats' => 'coat', 'overshirts' => 'overshirt',
        'cosplay_sets' => 'cosplay_costume', 'character_tops' => 'cosplay_costume', 'character_bottoms' => 'cosplay_costume',
        'stage_costumes' => 'stage_costume', 'stage_tops' => 'stage_costume', 'stage_bottoms' => 'stage_costume',
        'full_costumes' => 'cultural_dance_costume', 'costume_tops' => 'cultural_dance_costume',
        'costume_pants' => 'cultural_dance_costume',
    ];

    /**
     * The 4 approved services ("what work can you do?"), each with the specific tasks a shop can
     * tick ("capabilities"). Keys reuse the old service_category values where they existed.
     */
    public const SERVICE_GROUPS = [
        'custom_tailoring' => [
            'label' => 'Custom Tailoring & Garment Making',
            'description' => 'Made-to-measure and bespoke clothing made from the customer\'s measurements.',
            'aliases' => ['patahi', 'magpatahi', 'make', 'custom made', 'made to measure', 'bespoke', 'tahi', 'dressmaking', 'tailor'],
            'capabilities' => [
                'made_to_measure' => ['label' => 'Made-to-Measure Fitting', 'aliases' => ['custom fit', 'sukat']],
                'bespoke_pattern_making' => ['label' => 'Bespoke Pattern Making', 'aliases' => ['bespoke', 'couture', 'pattern', 'tabas']],
                'labor_only' => ['label' => 'Client-Supplied Fabric / Labor Only', 'aliases' => ['labor only', 'tela ng customer']],
                'full_package' => ['label' => 'Full Package / Fabric + Sewing Included', 'aliases' => ['with fabric', 'kasama tela']],
                'garment_embroidery' => ['label' => 'Optional Embroidery / Beadwork on Made Garment', 'aliases' => ['burda', 'embroider', 'logo', 'monogram', 'beadwork']],
            ],
        ],
        'uniform_production' => [
            'label' => 'Uniform, Jersey & Teamwear Production',
            'description' => 'Sewing school, office and medical uniforms, and cut-and-sew sports jerseys.',
            'aliases' => ['uniform', 'uniporme', 'school uniform', 'office uniform', 'scrub suit', 'jersey maker', 'team uniform'],
            'capabilities' => [
                'custom_measured_uniform' => ['label' => 'Individual / Custom-Measured Uniform', 'aliases' => ['custom size uniform', 'sukat uniporme']],
                'standard_size_production' => ['label' => 'Standard Size Chart Production (XS–5XL)', 'aliases' => ['standard size', 'size chart']],
                'bulk_orders' => ['label' => 'Bulk / Batch Orders Accepted', 'aliases' => ['bulk order', 'maramihan', 'team order']],
                'cut_and_sew_jersey' => ['label' => 'Cut-and-Sew Sports Jersey Construction', 'aliases' => ['basketball jersey', 'volleyball jersey', 'pe uniform', 'tahi jersey']],
                'uniform_embroidery' => ['label' => 'Optional Uniform Logo, Nameplate & Patch Embroidery', 'aliases' => ['add logo', 'school seal', 'patch', 'nameplate', 'embroider name', 'badge']],
            ],
        ],
        'alterations_repairs' => [
            'label' => 'Alterations & Repairs',
            'description' => 'Resizing, hemming, tapering, zipper and button replacement, and mending of the customer\'s own clothes.',
            'aliases' => ['alter', 'alteration', 'repair', 'fix', 'ayos', 'paliit', 'paluwag', 'putol', 'shorten', 'mend', 'tahi punit'],
            'capabilities' => [
                'hemming_length_adjustment' => ['label' => 'Hemming & Length Adjustment (Putol / Pahaba)', 'aliases' => ['shorten', 'putol', 'hem', 'pahaba', 'cut length']],
                'original_denim_hemming' => ['label' => 'Original Denim Hemming', 'aliases' => ['original hem', 'maong putol']],
                'waist_seat_adjustment' => ['label' => 'Waist & Seat Adjustment (Paliit / Paluwag)', 'aliases' => ['waist in', 'waist out', 'paliit bewang', 'paluwag', 'take in waist']],
                'tapering_resizing' => ['label' => 'Tapering & Resizing (Baston / Fit Adjustment)', 'aliases' => ['baston', 'slim fit', 'resize', 'paliitan', 'adjust fit']],
                'sleeve_adjustment' => ['label' => 'Sleeve Adjustment', 'aliases' => ['shorten sleeves', 'putol manggas']],
                'zipper_replacement' => ['label' => 'Zipper Replacement', 'aliases' => ['change zipper', 'palit zipper', 'broken zipper', 'sirang zipper']],
                'button_fastener_replacement' => ['label' => 'Button & Fastener Replacement', 'aliases' => ['butones', 'hook and eye', 'snap']],
                'garment_repair_mending' => ['label' => 'Garment Repair & Seam Mending (Tahing Punit)', 'aliases' => ['fix tear', 'punit', 'butas', 'restoration', 'mend clothes']],
            ],
        ],
        'garment_customization' => [
            'label' => 'Garment Customization & Embroidery',
            'description' => 'Embroidered logos, names, ranks, monograms or badges on the customer\'s own clothing.',
            'aliases' => ['embroidery', 'embroider', 'burda', 'magpaburda', 'add logo', 'put badge', 'patch', 'nameplate', 'monogram', 'initials'],
            'capabilities' => [
                'logo_embroidery' => ['label' => 'School & Company Logo Embroidery', 'aliases' => ['add logo', 'embroider logo', 'school logo', 'company seal']],
                'name_rank_embroidery' => ['label' => 'Name, Rank & Title Embroidery', 'aliases' => ['embroider name', 'nameplate', 'rank', 'surname stitching']],
                'badge_patch_attachment' => ['label' => 'Badge & Patch Attachment', 'aliases' => ['put a badge', 'sew patch', 'school patch', 'military patch']],
                'monogram_callado' => ['label' => 'Monogram, Initials & Barong Callado Embroidery', 'aliases' => ['initials', 'barong embroidery', 'callado', 'custom burda']],
            ],
        ],
    ];

    /**
     * Old service categories that are no longer offered => where their services went. Printing &
     * Sublimation is split per service by the 2026-10-14 migration (embroidery services go to
     * garment_customization); this is the default for everything else. 'others' had no home.
     */
    public const LEGACY_SERVICE_CATEGORIES = [
        'printing_sublimation' => 'uniform_production',
        'custom_costume_creation' => 'custom_tailoring',
        'others' => null,
    ];

    /** @return list<string> the 54 garments */
    public static function garmentSlugs(): array
    {
        return array_keys(self::GARMENTS);
    }

    /** @return list<string> every slug that is a garment of the taxonomy, old or new (anything else is a custom name) */
    public static function knownGarmentSlugs(): array
    {
        return array_values(array_unique([...self::garmentSlugs(), ...array_keys(self::LEGACY_GARMENT_SLUGS)]));
    }

    /** The garment a slug means today (old slugs resolve to what they were merged into); null for a custom name. */
    public static function canonicalGarment(?string $slug): ?string
    {
        if ($slug === null || $slug === '') {
            return null;
        }
        $slug = strtolower(trim($slug));
        if (isset(self::GARMENTS[$slug])) {
            return $slug;
        }

        return self::LEGACY_GARMENT_SLUGS[$slug] ?? null;
    }

    /** @return list<string> a garment and every old slug merged into it — for matching stored values */
    public static function garmentVariants(?string $slug): array
    {
        $canonical = self::canonicalGarment($slug);
        if ($canonical === null) {
            return $slug ? [strtolower(trim($slug))] : [];
        }
        $old = array_keys(array_filter(self::LEGACY_GARMENT_SLUGS, fn ($to) => $to === $canonical));

        return array_values(array_unique([$canonical, ...$old]));
    }

    /** @return list<string> the garments of one category */
    public static function garmentsInCategory(string $category): array
    {
        return array_keys(array_filter(self::GARMENTS, fn ($g) => $g['category'] === $category));
    }

    /** @return list<string> the 4 approved services */
    public static function serviceGroups(): array
    {
        return array_keys(self::SERVICE_GROUPS);
    }

    /** @return list<string> the capability slugs of one service group */
    public static function capabilitiesOf(string $group): array
    {
        return array_keys(self::SERVICE_GROUPS[$group]['capabilities'] ?? []);
    }

    /**
     * The capabilities of a service group that a piece of text names, by label or alias — e.g. a price row
     * "Zipper Replacement" or "Take In / Let Out Waist". A phrase matches when all its words are in the text,
     * in any order and form ("shorten sleeves" matches "Sleeve Shortening"); whole words only, so "hem" never
     * matches "chemise". The longest matching phrase wins its words: "Sleeve Shortening" is sleeve adjustment,
     * not also hemming (whose alias is just "shorten").
     *
     * @return list<string>
     */
    public static function capabilitiesMentionedIn(string $group, string $text): array
    {
        $words = array_flip(self::wordStems($text));
        $matches = [];
        foreach (self::SERVICE_GROUPS[$group]['capabilities'] ?? [] as $slug => $cap) {
            foreach ([$cap['label'], ...$cap['aliases']] as $phrase) {
                $need = array_values(array_unique(self::wordStems($phrase)));
                if ($need !== [] && ! array_diff_key(array_flip($need), $words)) {
                    $matches[] = ['slug' => $slug, 'words' => $need];
                }
            }
        }
        usort($matches, fn ($a, $b) => count($b['words']) <=> count($a['words']));

        $taken = [];
        $found = [];
        foreach ($matches as $m) {
            if (in_array($m['slug'], $found, true) || ! array_diff($m['words'], $taken)) {
                continue;
            }
            $found[] = $m['slug'];
            $taken = [...$taken, ...$m['words']];
        }

        return $found;
    }

    /**
     * Lower-case word stems of a text, so "Shortening"/"shorten", "Sleeves"/"sleeve", "Embroidery"/"embroider"
     * and "Patches"/"patch" compare equal. Deliberately small (no dictionary): it only has to line up the
     * taxonomy's own labels and aliases with how shops and customers write the same words.
     *
     * @return list<string>
     */
    public static function wordStems(string $text): array
    {
        $out = [];
        foreach (preg_split('/[^a-z0-9ñ]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (mb_strlen($w) > 5 && str_ends_with($w, 'ing')) {
                $w = substr($w, 0, -3);
                if (strlen($w) > 2 && $w[-1] === $w[-2]) {
                    $w = substr($w, 0, -1); // hemming -> hem, fitting -> fit
                }
            } elseif (mb_strlen($w) > 5 && str_ends_with($w, 'ery')) {
                $w = substr($w, 0, -1); // embroidery -> embroider
            } elseif (str_ends_with($w, 'ies') && mb_strlen($w) > 4) {
                $w = substr($w, 0, -3).'y';
            } elseif (preg_match('/(ss|x|ch|sh|z)es$/', $w)) {
                $w = substr($w, 0, -2); // dresses -> dress, patches -> patch
            } elseif (str_ends_with($w, 's') && ! str_ends_with($w, 'ss') && mb_strlen($w) > 3) {
                $w = substr($w, 0, -1); // sleeves -> sleeve, zippers -> zipper
            }
            $out[] = $w;
        }

        return $out;
    }
}
