<?php

namespace Database\Seeders;

use App\Models\CatalogImage;
use App\Models\CatalogItem;
use App\Models\CatalogOrder;
use App\Models\JobOrder;
use App\Models\Service;
use App\Models\Store;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Thread & Needle Tailoring's catalog. Every design is placed in the
 * canonical taxonomy (department → subcategory → garment structure →
 * garment type, see App\Support\CanonicalTaxonomy) so it shows up under the
 * header nav's MEN / WOMEN / KIDS menus and the matching /search filters.
 *
 * Convergent, not just idempotent: earlier revisions of this seeder keyed
 * `firstOrCreate` on titles that later changed, so every rename left the old
 * row behind and the catalog grew to ~80 rows of duplicates. Re-running this
 * on an existing DB now renames a row still carrying an older title
 * (`aliases`), merges duplicate listings of the same design into one row
 * (catalog orders and job orders are re-pointed first), and deletes titles
 * retired from earlier revisions — so an existing DB ends up with exactly
 * the catalog a fresh seed produces.
 */
class CatalogItemsSeeder extends Seeder
{
    public const ANDREA_LEO_NAME = 'Andrea & Leo A1237 Off Shoulder Slit Leg Floral Tulle A Line Gown';

    private const SVC_BRIDAL = 'Bridal & Wedding Gown Design';
    private const SVC_SUIT = 'Bespoke Suit Tailoring';
    private const SVC_BARONG = 'Barong Tagalog Tailoring';
    private const SVC_JERSEY = 'Custom Sublimation Team Jerseys';
    private const SVC_UNIFORM = 'School & Organization Uniform Sewing';

    private const SIZES_WOMEN = ['XS', 'S', 'M', 'L', 'XL'];
    private const SIZES_ADULT = ['S', 'M', 'L', 'XL', 'XXL'];
    private const SIZES_SUIT = ['36', '38', '40', '42', '44', '46'];
    private const SIZES_KIDS = ['4', '6', '8', '10', '12', '14'];

    private const FABRIC_BRIDAL = '/catalog/fabrics/bridal_chiffon_fabric.jpg';
    private const FABRIC_SATIN = '/catalog/fabrics/satin_silk_fabric.jpg';
    private const FABRIC_WOOL = '/catalog/fabrics/wool_twill_fabric.jpg';
    private const FABRIC_DRIFIT = '/catalog/fabrics/drifit_mesh_fabric.jpg';
    private const FABRIC_SPANDEX = '/catalog/fabrics/compression_spandex_fabric.jpg';
    private const FABRIC_PINA = '/catalog/fabrics/pina_cocoon_fabric.jpg';

    /**
     * Titles from earlier revisions with no canonical design behind them
     * (scraped listing titles, placeholder rows). Deleted unless an order or
     * job order still points at one, in which case it's left alone rather
     * than breaking that record.
     */
    private const RETIRED_NAMES = [
        'AllStar-Basketball-Jersey',
        'Lakers-Basketball-Jersey',
        'Best Custom Tuxedos in NYC - Bespoke Groom Tuxedos',
        'Tailor Made Suits London - The Bespoke Tailor UK',
        'images',
        'Custom Tailored Piece - Made to Order',
        'Elegant Sequined Off White Wedding Dresses with Puff Sleeves and Long Tail from Dhgate Ball Gown Wedding Gown',
        'Vintage Dark Teal Mother Gowns for Wedding Women 2024 Lace Mother of the Groom Dress Long Sleeve ZXI',
        'Barong Tagalog Cloth- Traditional and Elegant Fabrics',
        'Barong Tagalog For Sale - Traditional and Modern Filipino Attire for M - Tagged barong with lining',
        "Men's - Traditional Barong Tagalog - Page 1 - Barong At Bestida Australia",
        // These two were never their own design — both just reused one of
        // ANDREA_LEO_NAME's own color-variant photos (Champagne / the
        // "back" shot) as if it were a completely separate gown, so the
        // same stock photo confusingly showed up as two unrelated catalog
        // listings. The photos themselves stay exactly where they belong:
        // tagged onto Andrea & Leo's own images (see items() below).
        'Champagne-Gold Embroidered Wedding Gown',
        'Luxury Long-Train Bridal Gown',
    ];

    public function run(): void
    {
        $store = Store::where('slug', 'thread-needle')->first() ?? Store::first();
        if (! $store) {
            return;
        }

        $serviceIds = Service::where('store_id', $store->id)->pluck('id', 'name');

        foreach ($this->items() as $def) {
            $this->upsertItem($store->id, $def, $serviceIds);
        }

        $this->deleteRetired($store->id);
    }

    private function upsertItem(int $storeId, array $def, Collection $serviceIds): void
    {
        $names = array_merge([$def['name']], $def['aliases'] ?? []);
        $matches = CatalogItem::where('store_id', $storeId)->whereIn('name', $names)->orderBy('id')->get();
        $item = $matches->firstWhere('name', $def['name']) ?? $matches->first();

        $attributes = [
            'name' => $def['name'],
            'price' => $def['price'],
            'estimated_days' => $def['days'] ?? 7,
            'material' => $def['material'],
            'color' => $def['color'] ?? null,
            'fabric_image_url' => $def['fabric'] ?? null,
            'sizes' => $def['sizes'],
            'description' => $def['desc'],
            'department' => $def['dept'],
            'subcategory' => $def['sub'],
            'garment_structure' => $def['struct'],
            'garment_type' => $def['type'],
            'service_id' => isset($def['service']) ? ($serviceIds[$def['service']] ?? null) : null,
            'listing_type' => 'made_to_order',
            'is_active' => true,
            'features' => ['Made to Measure', 'SUTURA Guaranteed'],
            'care_instructions' => 'Handle with care.',
            'size_chart_columns' => $def['chart_columns'] ?? null,
            'size_chart_rows' => $def['chart_rows'] ?? null,
        ];

        if ($item) {
            $item->update($attributes);
        } else {
            $item = CatalogItem::create($attributes + ['store_id' => $storeId]);
        }

        // Other rows for the same design: move their orders over, then drop
        // them (their images cascade).
        foreach ($matches as $duplicate) {
            if ($duplicate->id === $item->id) {
                continue;
            }
            CatalogOrder::where('catalog_item_id', $duplicate->id)->update(['catalog_item_id' => $item->id]);
            JobOrder::where('catalog_item_id', $duplicate->id)->update(['catalog_item_id' => $item->id]);
            $duplicate->delete();
        }

        // updateOrCreate() matches on the exact image_url string, so if a
        // path's formatting ever changes between seeder revisions (e.g. a
        // leading slash added later) the old row is never matched — it just
        // sits there as an orphaned duplicate instead of being replaced.
        // This exact thing happened to ANDREA_LEO_NAME: three stale rows
        // (no leading slash) survived alongside the current ones, showing
        // up as extra/repeated thumbnails in its gallery. Pruning anything
        // not in this def's current image list keeps that from recurring.
        $currentUrls = array_column($def['images'], 0);
        CatalogImage::where('catalog_item_id', $item->id)
            ->whereNotIn('image_url', $currentUrls)
            ->delete();

        foreach ($def['images'] as $index => [$url, $angle]) {
            CatalogImage::updateOrCreate(
                ['catalog_item_id' => $item->id, 'image_url' => $url],
                ['view_angle' => $angle, 'is_primary' => $index === 0]
            );
        }
    }

    private function deleteRetired(int $storeId): void
    {
        CatalogItem::where('store_id', $storeId)
            ->whereIn('name', self::RETIRED_NAMES)
            ->get()
            ->each(function (CatalogItem $item) {
                $referenced = CatalogOrder::where('catalog_item_id', $item->id)->exists()
                    || JobOrder::where('catalog_item_id', $item->id)->exists();
                if (! $referenced) {
                    $item->delete();
                }
            });
    }

    /** @return array<int, array<string, mixed>> */
    private function items(): array
    {
        $women = ['dept' => 'women'];
        $men = ['dept' => 'men'];
        $gown = ['sub' => 'dresses_gowns', 'struct' => 'one_piece', 'service' => self::SVC_BRIDAL, 'material' => 'Chiffon & Tulle', 'sizes' => self::SIZES_WOMEN];
        $formalSet = ['sub' => 'formal_wear', 'struct' => 'sets', 'service' => self::SVC_SUIT, 'material' => 'Premium Wool', 'fabric' => self::FABRIC_WOOL, 'sizes' => self::SIZES_SUIT, 'days' => 14];
        $barong = ['sub' => 'traditional_wear', 'struct' => 'top_wear', 'service' => self::SVC_BARONG, 'material' => 'Piña Cocoon', 'fabric' => self::FABRIC_PINA, 'color' => 'Ivory', 'sizes' => self::SIZES_ADULT, 'days' => 10];
        $sportTop = ['sub' => 'sportswear_teamwear', 'struct' => 'top_wear', 'service' => self::SVC_JERSEY, 'material' => 'Drifit Mesh', 'fabric' => self::FABRIC_DRIFIT, 'sizes' => self::SIZES_ADULT];
        $sportSet = ['sub' => 'sportswear_teamwear', 'struct' => 'sets', 'service' => self::SVC_JERSEY, 'material' => 'Drifit Mesh', 'fabric' => self::FABRIC_DRIFIT, 'sizes' => self::SIZES_ADULT];

        return [
            // ── WOMEN · Dresses & Gowns ───────────────────────────────────
            array_merge($women, $gown, [
                'name' => self::ANDREA_LEO_NAME,
                'aliases' => ['Off-Shoulder Floral Tulle A-Line Gown'],
                'type' => 'evening_dresses', 'price' => 4500,
                'color' => 'Sky Blue, Champagne, Ivory',
                'fabric' => '/catalog/fabrics/sky_blue_chiffon_tulle_fabric.jpg',
                'desc' => 'Off-shoulder A-line gown with a slit leg and hand-applied floral tulle, cut to your measurements.',
                'chart_columns' => ['Bust (cm)', 'Waist (cm)', 'Hip (cm)'],
                'chart_rows' => [
                    ['size' => 'XS', 'values' => ['82', '62', '88']],
                    ['size' => 'S', 'values' => ['86', '66', '92']],
                    ['size' => 'M', 'values' => ['90', '70', '96']],
                    ['size' => 'L', 'values' => ['94', '74', '100']],
                    ['size' => 'XL', 'values' => ['98', '78', '104']],
                ],
                // Front/back are angle shots; the named ones are color
                // variants (see fabricHelper.ts's isAngleLabel()).
                'images' => [
                    ['/catalog/gown-off-shoulder-tulle-floral.webp', 'front'],
                    ['/catalog/Luxury_Bridal_Gowns_Long_Tail.jpg', 'back'],
                    ['/catalog/wedding-gown-champagne-embroidery.jpg', 'Champagne'],
                    ['/catalog/wedding-gown-sequined-puff-sleeve.jpg', 'Ivory'],
                ],
            ]),
            array_merge($women, $gown, [
                'name' => 'Pleated Chiffon Maid of Honor Dress',
                'aliases' => ['Long Maid Of Honour Dresses Leia Modest Sweetheart Pleated Chiffon Maid Of Honor'],
                'type' => 'formal_dresses', 'price' => 4500, 'color' => 'Blush', 'fabric' => self::FABRIC_SATIN,
                'desc' => 'Floor-length maid of honor dress with a modest sweetheart neckline and soft pleated chiffon skirt.',
                'images' => [['/catalog/maid-of-honor-dress-chiffon.jpg', 'front']],
            ]),
            array_merge($women, $gown, [
                'name' => 'Long-Tail White Wedding Gown',
                'aliases' => ['Shop Long Tail Wedding Gown', 'Store Long Tail Wedding Gown', 'Long-Train Wedding Gown'],
                'type' => 'custom_gowns', 'price' => 4500, 'color' => 'White', 'fabric' => self::FABRIC_BRIDAL, 'days' => 14,
                'desc' => 'Classic white wedding gown with a long flowing tail, made to order for your wedding day.',
                'images' => [['/catalog/Shop Long Tail Wedding Gown.jpg', 'front']],
            ]),
            array_merge($women, $gown, [
                'name' => 'Emerald Green Multiway Convertible Bridesmaid Gown',
                'type' => 'formal_dresses', 'price' => 4500, 'color' => 'Emerald', 'fabric' => '/catalog/fabrics/emerald_lace_fabric.jpg',
                'desc' => 'Convertible bridesmaid gown that can be wrapped and tied several ways to suit every member of the entourage.',
                'images' => [['/catalog/bridesmaid-dresses-maid-of-honor.webp', 'front']],
            ]),
            array_merge($women, $gown, [
                'name' => 'Pink Chiffon Mother of the Bride Tea-Length Dress',
                'aliases' => ['Pink Chiffon Mother of the Bride Dresses Simple Scoop Neck Long Sleeves Pearls Tea-Length A-LINE Evening Mother Gowns'],
                'type' => 'evening_dresses', 'price' => 4500, 'color' => 'Blush', 'fabric' => self::FABRIC_SATIN,
                'desc' => 'Tea-length A-line dress with a scoop neck, long sleeves, and pearl detailing for the mother of the bride.',
                'images' => [['/catalog/mother-of-bride-dress-chiffon-pink.jpg', 'front']],
            ]),
            array_merge($women, $gown, [
                'name' => 'Bridal Crew Bridesmaid Dress',
                'aliases' => ['9 Luxury Designer Bridesmaid Dresses for the Bridal Crew'],
                'type' => 'formal_dresses', 'price' => 4500, 'color' => 'Blush', 'fabric' => self::FABRIC_SATIN,
                'desc' => 'Coordinated bridesmaid dress for the whole bridal crew, tailored individually for each member.',
                'images' => [['/catalog/bridesmaid-dresses-bridal-crew.jpg', 'front']],
            ]),
            array_merge($women, $gown, [
                'name' => 'Regal A-Line Floor-Length Satin Corset Mother of the Bride Dress',
                'aliases' => [
                    'Light Pink Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora',
                    'Greed Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora',
                    'Red Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora',
                ],
                'type' => 'formal_dresses', 'price' => 1500, 'material' => 'Satin', 'fabric' => self::FABRIC_SATIN,
                'color' => 'Blush, Emerald Green, Crimson Red',
                'desc' => 'Floor-length A-line satin dress with a structured corset bodice and floral accents, offered in three colors.',
                'images' => [
                    ['/catalog/mother-of-bride-dress-pink.webp', 'front'],
                    ['/catalog/mother-of-bride-dress-green.webp', 'Emerald Green'],
                    ['/catalog/mother-of-bride-dress-red.webp', 'Crimson Red'],
                ],
            ]),
            array_merge($women, $gown, [
                'name' => 'Tailored Casual Day Dress',
                'type' => 'casual_dresses', 'price' => 1800, 'material' => 'Cotton Blend', 'service' => null,
                'desc' => 'Everyday day dress tailored to your measurements for an easy, comfortable fit.',
                'images' => [['/images/categories/dress.jpg', 'front']],
            ]),

            // ── WOMEN · Traditional & Cultural / Formal / Workwear / Casual ─
            array_merge($women, [
                'name' => 'Modern Filipiniana Terno Top',
                'sub' => 'traditional_cultural_wear', 'struct' => 'top_wear', 'type' => 'modern_filipiniana_tops',
                'service' => self::SVC_BRIDAL, 'price' => 3800, 'material' => 'Piña-Jusi Blend', 'fabric' => self::FABRIC_PINA,
                'sizes' => self::SIZES_WOMEN, 'days' => 14,
                'desc' => 'Modern Filipiniana top with structured butterfly sleeves, for formal events and Buwan ng Wika.',
                'images' => [['/images/categories/filipiniana.jpg', 'front']],
            ]),
            array_merge($women, [
                'name' => "Women's Tailored Power Suit",
                'sub' => 'formal_wear', 'struct' => 'sets', 'type' => 'womens_suits',
                'service' => self::SVC_SUIT, 'price' => 7500, 'material' => 'Premium Wool Blend', 'fabric' => self::FABRIC_WOOL,
                'sizes' => self::SIZES_WOMEN, 'days' => 14,
                'desc' => 'Two-piece tailored suit for the office and formal occasions, cut to your measurements.',
                'images' => [['/images/categories/women_suit.jpg', 'front']],
            ]),
            array_merge($women, [
                'name' => "Women's Tailored Office Blazer",
                'sub' => 'uniforms_workwear', 'struct' => 'top_wear', 'type' => 'blazers',
                'service' => self::SVC_SUIT, 'price' => 3200, 'material' => 'Poly-Viscose Suiting', 'fabric' => self::FABRIC_WOOL,
                'sizes' => self::SIZES_WOMEN, 'days' => 10,
                'desc' => 'Single-breasted office blazer, tailored for corporate wear and company uniforms.',
                'images' => [['/images/categories/blazer.jpg', 'front']],
            ]),
            array_merge($women, [
                'name' => "Women's Tailored Trousers",
                'sub' => 'casual_wear', 'struct' => 'bottom_wear', 'type' => 'trousers',
                'service' => null, 'price' => 1300, 'material' => 'Poly-Viscose',
                'sizes' => self::SIZES_WOMEN, 'days' => 7,
                'desc' => 'Straight-leg trousers tailored to your waist, hip, and inseam.',
                'images' => [['/images/categories/women_pants.jpg', 'front']],
            ]),

            // ── WOMEN · Sportswear ────────────────────────────────────────
            array_merge($women, $sportTop, [
                'name' => "Women's Esports Jersey - Custom Print",
                'aliases' => ['Esports-Jersey-women', "Women's Esports Jersey with Customized Design"],
                'type' => 'esports_jerseys', 'price' => 660, 'color' => 'Blue',
                'desc' => "Women's-cut esports jersey with full sublimation print — team name, handle, and number included.",
                'images' => [['/catalog/Esports-Jersey-women.jpg', 'front']],
            ]),

            // ── MEN · Formal Wear ─────────────────────────────────────────
            array_merge($men, $formalSet, [
                'name' => 'Bespoke Two-Piece Suit - Charcoal Wool',
                'aliases' => ['Bespoke_Suits2'],
                'type' => 'suits', 'price' => 11500, 'color' => 'Black',
                'desc' => 'Two-piece bespoke suit in charcoal wool, fully canvassed and cut to your measurements.',
                'images' => [['/catalog/Bespoke_Suits2.jpg', 'front']],
            ]),
            array_merge($men, $formalSet, [
                'name' => 'Bespoke Three-Piece Suit - Navy Wool',
                'aliases' => ['Bespoke_Suits'],
                'type' => 'suits', 'price' => 11800, 'color' => 'Navy',
                'desc' => 'Three-piece bespoke suit in navy wool with a matching waistcoat.',
                'images' => [['/catalog/Bespoke_Suits.png', 'front']],
            ]),
            array_merge($men, $formalSet, [
                'name' => "Men's Custom Tuxedo - Classic Peak Lapel",
                'aliases' => ['mens-custom-tuxedos-its-all-about-the-fit'],
                'type' => 'tuxedo_sets', 'price' => 12500, 'color' => 'Black',
                'desc' => 'Classic tuxedo with satin peak lapels, tailored for weddings and black-tie events.',
                'images' => [['/catalog/mens-custom-tuxedos-its-all-about-the-fit.jpeg', 'front']],
            ]),
            array_merge($men, $formalSet, [
                'name' => "Men's Classic Black Tuxedo",
                'aliases' => ['Custom_Tuxedos_men'],
                'type' => 'tuxedo_sets', 'price' => 12200, 'color' => 'Black',
                'desc' => 'Timeless black tuxedo, cut and fitted to your measurements.',
                'images' => [['/catalog/Custom_Tuxedos_men.jpeg', 'front']],
            ]),
            array_merge($men, $formalSet, [
                'name' => 'Custom Groom Tuxedo - Event Package',
                'aliases' => ['Custom Tuxedos for Memorable Events'],
                'type' => 'tuxedo_sets', 'price' => 12800, 'color' => 'Black',
                'desc' => 'Groom tuxedo package with fittings scheduled around your wedding date.',
                'images' => [['/catalog/Custom Tuxedos for Memorable Events.jpeg', 'front']],
            ]),
            array_merge($men, $formalSet, [
                'name' => 'Navy Blue Tuxedo with Contrast Belt',
                'aliases' => ['Blue Tuxedo Belt Tuxedo Blue Suit Brown Belt Core Navy'],
                'type' => 'tuxedo_sets', 'price' => 12400, 'color' => 'Navy',
                'desc' => 'Navy tuxedo paired with a contrast belt for a modern formal look.',
                'images' => [['/catalog/navy-blue-tuxedo-suit.webp', 'front']],
            ]),

            // ── MEN · Traditional Wear ────────────────────────────────────
            array_merge($men, $barong, [
                'name' => 'Traditional Ivory Barong Tagalog - Formal Fit',
                'aliases' => ['Traditional Ivory Color Barong Tagalog - Formal Fit'],
                'type' => 'barong_tagalog', 'price' => 4500,
                'desc' => 'Ivory Barong Tagalog in piña cocoon with delicate hand embroidery, in a formal fit.',
                'images' => [['/catalog/barong-tagalog-ivory-formal.jpg', 'front']],
            ]),
            array_merge($men, $barong, [
                'name' => 'Short-Sleeve Barong Polo',
                'aliases' => ['Traditional Barong Tagalog Polo Shirt for Men'],
                'type' => 'short_sleeve_barong', 'price' => 4500,
                'desc' => 'Short-sleeve barong polo — traditional embroidery with a lighter, semi-formal cut.',
                'images' => [['/catalog/Traditional Barong Tagalog Polo Shirt for Men.jpeg', 'front']],
            ]),

            // ── MEN · Casual / Outerwear / Workwear ──────────────────────
            array_merge($men, [
                'name' => 'Tailored Button-Down Shirt',
                'sub' => 'casual_wear', 'struct' => 'top_wear', 'type' => 'button_down_shirts',
                'service' => null, 'price' => 1200, 'material' => 'Cotton Oxford', 'sizes' => self::SIZES_ADULT,
                'desc' => 'Button-down shirt cut to your neck, chest, and sleeve length.',
                'images' => [['/images/categories/shirt.jpg', 'front']],
            ]),
            array_merge($men, [
                'name' => 'Tailored Chino Trousers',
                'sub' => 'casual_wear', 'struct' => 'bottom_wear', 'type' => 'chinos',
                'service' => null, 'price' => 1100, 'material' => 'Cotton Twill', 'sizes' => self::SIZES_ADULT,
                'desc' => 'Everyday chinos tailored to your waist and inseam.',
                'images' => [['/images/categories/pants.jpg', 'front']],
            ]),
            array_merge($men, [
                'name' => 'Tailored Wool Overcoat',
                'sub' => 'outerwear', 'struct' => 'top_wear', 'type' => 'coats',
                'service' => self::SVC_SUIT, 'price' => 6500, 'material' => 'Wool Melton', 'fabric' => self::FABRIC_WOOL,
                'sizes' => self::SIZES_SUIT, 'days' => 14,
                'desc' => 'Knee-length wool overcoat, tailored to wear over a suit.',
                'images' => [['/images/categories/men_outerwear.jpg', 'front']],
            ]),
            array_merge($men, [
                'name' => 'Medical Scrub Top',
                'sub' => 'uniforms_workwear', 'struct' => 'top_wear', 'type' => 'medical_tops',
                'service' => self::SVC_UNIFORM, 'price' => 850, 'material' => 'Poly-Cotton Twill', 'sizes' => self::SIZES_ADULT,
                'desc' => 'Durable scrub top for clinics and hospitals — bulk orders sized per staff roster.',
                'images' => [['/images/categories/scrubs.jpg', 'front']],
            ]),

            // ── MEN · Sportswear & Teamwear ───────────────────────────────
            array_merge($men, $sportTop, [
                'name' => 'Pro-Fit Cycling Jersey - Team Kit',
                'aliases' => ['Cycling_Jerseys_1'],
                'type' => 'cycling_jerseys', 'price' => 620, 'color' => 'Blue',
                'desc' => 'Full-sublimation cycling jersey in breathable drifit mesh, printed with your team kit.',
                'images' => [['/catalog/Cycling_Jerseys_1.jpeg', 'front']],
            ]),
            array_merge($men, $sportTop, [
                'name' => 'Cycling Jersey - Sublimated Print',
                'aliases' => ['Cycling_Jerseys_2'],
                'type' => 'cycling_jerseys', 'price' => 640, 'color' => 'Blue',
                'desc' => 'Short-sleeve cycling jersey with an all-over sublimated print.',
                'images' => [['/catalog/Cycling_Jerseys_2.jpg', 'front']],
            ]),
            array_merge($men, $sportTop, [
                'name' => 'Long-Sleeve Cycling Jersey - Aero Fit',
                'aliases' => ['Cycling_Jerseys_3'],
                'type' => 'cycling_jerseys', 'price' => 670, 'color' => 'Blue',
                'desc' => 'Long-sleeve aero-fit cycling jersey for sun protection on long rides.',
                'images' => [['/catalog/Cycling_Jerseys_3.jpg', 'front']],
            ]),
            array_merge($men, $sportTop, [
                'name' => 'Motorcycle Riders Long-Sleeve Jersey',
                'type' => 'cycling_jerseys', 'price' => 1400, 'material' => 'Compression Spandex', 'fabric' => self::FABRIC_SPANDEX, 'color' => 'Black',
                'desc' => 'Long-sleeve riding jersey for motorcycle clubs, printed with your club design.',
                'images' => [['/catalog/Riders_Long_Sleeves.webp', 'front']],
            ]),
            array_merge($men, $sportTop, [
                'name' => 'Riders Club Long-Sleeve Jersey',
                'aliases' => ['Riders_Long_Sleeves'],
                'type' => 'cycling_jerseys', 'price' => 1450, 'material' => 'Compression Spandex', 'fabric' => self::FABRIC_SPANDEX, 'color' => 'Black',
                'desc' => 'Riders club long-sleeve jersey with sublimated club colors.',
                'images' => [['/catalog/Riders_Long_Sleeves.jpg', 'front']],
            ]),
            array_merge($men, $sportTop, [
                'name' => 'Riders Club Long-Sleeve Jersey V2',
                'aliases' => ['Riders_Long_Sleeves_2'],
                'type' => 'cycling_jerseys', 'price' => 1450, 'material' => 'Compression Spandex', 'fabric' => self::FABRIC_SPANDEX, 'color' => 'Black',
                'desc' => 'Second-edition riders club jersey with an updated panel layout.',
                'images' => [['/catalog/Riders_Long_Sleeves_2.jpg', 'front']],
            ]),
            array_merge($men, $sportTop, [
                'name' => 'Compression Rash Guard - Long Sleeve',
                'aliases' => ['rashguard_1'],
                'type' => 'cycling_jerseys', 'price' => 580, 'material' => 'Compression Spandex', 'fabric' => self::FABRIC_SPANDEX, 'color' => 'Blue',
                'desc' => 'Long-sleeve compression rash guard for training, surfing, and combat sports.',
                'images' => [['/catalog/rashguard_1.webp', 'front']],
            ]),
            array_merge($men, $sportTop, [
                'name' => 'Compression Rash Guard - Short Sleeve',
                'aliases' => ['rashguard_3'],
                'type' => 'cycling_jerseys', 'price' => 590, 'material' => 'Compression Spandex', 'fabric' => self::FABRIC_SPANDEX, 'color' => 'Blue',
                'desc' => 'Short-sleeve compression rash guard with a sublimated design.',
                'images' => [['/catalog/rashguard_3.jpg', 'front']],
            ]),
            array_merge($men, $sportTop, [
                'name' => 'Custom Basketball Jersey - Away Kit',
                'aliases' => ['Bulls-Basketball-Jersey'],
                'type' => 'basketball_jerseys', 'price' => 700, 'color' => 'Crimson',
                'desc' => 'Away-kit basketball jersey with your team name and player numbers.',
                'images' => [['/catalog/Bulls-Basketball-Jersey.jpg', 'front']],
            ]),
            array_merge($men, $sportTop, [
                'name' => 'Custom Basketball Jersey - Home Kit',
                'aliases' => ['Bears-Basketball-Jersey'],
                'type' => 'basketball_jerseys', 'price' => 660, 'color' => 'Blue',
                'desc' => 'Home-kit basketball jersey with your team name and player numbers.',
                'images' => [['/catalog/Bears-Basketball-Jersey.jpg', 'front']],
            ]),
            array_merge($men, $sportTop, [
                'name' => 'Retro Basketball Jersey - Purple & Gold',
                'aliases' => ['KobeBryant-Basketball-Jersey'],
                'type' => 'basketball_jerseys', 'price' => 700, 'color' => 'Purple',
                'desc' => 'Retro-style basketball jersey in purple and gold.',
                'images' => [['/catalog/KobeBryant-Basketball-Jersey.jpg', 'front']],
            ]),
            array_merge($men, $sportTop, [
                'name' => 'Retro Basketball Jersey - Gold Edition',
                'aliases' => ['Lebron James-Lakers-Basketball-Jersey'],
                'type' => 'basketball_jerseys', 'price' => 710, 'color' => 'Gold',
                'desc' => 'Retro-style basketball jersey, gold edition.',
                'images' => [['/catalog/Lebron James-Lakers-Basketball-Jersey.webp', 'front']],
            ]),
            array_merge($men, $sportTop, [
                'name' => 'Football Club Jersey - Custom Print',
                'aliases' => ['Arsenal-Jersey'],
                'type' => 'basketball_jerseys', 'price' => 690, 'color' => 'Crimson',
                'desc' => 'Club-style football jersey with a custom sublimated print.',
                'images' => [['/catalog/Arsenal-Jersey.jpg', 'front']],
            ]),
            array_merge($men, $sportTop, [
                'name' => 'Esports Team Jersey - Royal Blue',
                'aliases' => ['esport tshirt blue'],
                'type' => 'esports_jerseys', 'price' => 680, 'color' => 'Royal Blue',
                'desc' => 'Esports team jersey in royal blue, printed with team and player names.',
                'images' => [['/catalog/esport tshirt blue.jpeg', 'front']],
            ]),
            array_merge($men, $sportTop, [
                'name' => 'Esports Team Jersey - Classic Cut',
                'aliases' => ['esport tshirt'],
                'type' => 'esports_jerseys', 'price' => 630, 'color' => 'Blue',
                'desc' => 'Classic-cut esports team jersey with a full sublimated design.',
                'images' => [['/catalog/esport tshirt.webp', 'front']],
            ]),
            array_merge($men, $sportSet, [
                'name' => 'Volleyball Team Jersey - Sleeveless Set',
                'aliases' => ['Volleyball Jersey_2'],
                'type' => 'volleyball_sets', 'price' => 600, 'color' => 'Blue',
                'desc' => 'Sleeveless volleyball jersey and shorts set for your team.',
                'images' => [['/catalog/Volleyball Jersey_2.jpg', 'front']],
            ]),
            array_merge($men, $sportSet, [
                'name' => 'Volleyball Round-Neck Jersey Set',
                'aliases' => ['volleyballroundneckSET'],
                'type' => 'volleyball_sets', 'price' => 1300, 'color' => 'Blue',
                'desc' => 'Round-neck volleyball jersey and shorts set.',
                'images' => [['/catalog/volleyballroundneckSET.webp', 'front']],
            ]),
            array_merge($men, $sportSet, [
                'name' => 'Volleyball Pre-Order Jersey Set',
                'aliases' => ['VBALL_PRE-2001_800x800'],
                'type' => 'volleyball_sets', 'price' => 1350, 'color' => 'Blue',
                'desc' => 'Pre-order volleyball set — jersey and shorts, produced in one team batch.',
                'images' => [['/catalog/VBALL_PRE-2001_800x800.webp', 'front']],
            ]),

            // ── KIDS ──────────────────────────────────────────────────────
            [
                'name' => "Boys' School Uniform Set",
                'dept' => 'children', 'sub' => 'boys_apparel', 'struct' => 'sets', 'type' => 'school_uniform_sets',
                'service' => self::SVC_UNIFORM, 'price' => 650, 'material' => 'Poly-Cotton', 'sizes' => self::SIZES_KIDS, 'days' => 14,
                'desc' => 'Polo and trousers school uniform set, sized per student — bulk school orders welcome.',
                'images' => [['/catalog/school-uniforms.jpg', 'front']],
            ],
            [
                'name' => "Girls' School Uniform Set",
                'dept' => 'children', 'sub' => 'girls_apparel', 'struct' => 'sets', 'type' => 'school_uniform_sets',
                'service' => self::SVC_UNIFORM, 'price' => 650, 'material' => 'Poly-Cotton', 'sizes' => self::SIZES_KIDS, 'days' => 14,
                'desc' => 'Blouse and skirt school uniform set, sized per student — bulk school orders welcome.',
                'images' => [['/images/categories/school_uniform.jpg', 'front']],
            ],
        ];
    }
}
