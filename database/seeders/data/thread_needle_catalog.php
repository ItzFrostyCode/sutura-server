<?php

/*
 * Thread & Needle Tailoring: services and designs that cover every ordering case in
 * "Tailoringshop in Davao.md" (made-to-order vs work on the customer's garment; Top / Bottom / Set with its own price;
 * fixed, starting and quotation prices; one person, group only, or both; standard sizes and body measurements;
 * own fabric; shop questions and per-person columns; 50%, 70% and full payment).
 *
 * Read by ThreadNeedleCatalogSeeder (through the same controllers the owner uses) and by the image script.
 * `image` is a file in sutura-client/public/catalog/seed/: a name alone means NAME.png; a real photo may carry its
 * own extension (office-uniform.webp). Replace a file any time; keep the name, or change it here too.
 * Prices are illustrative Davao-range figures, not quotes from a real shop.
 */

$top = ['columns' => ['Chest (in)', 'Shoulder (in)', 'Sleeve (in)', 'Length (in)'], 'rows' => [
    ['size' => 'S', 'values' => ['36', '16.5', '8', '27']],
    ['size' => 'M', 'values' => ['38', '17', '8.5', '28']],
    ['size' => 'L', 'values' => ['40', '18', '9', '29']],
    ['size' => 'XL', 'values' => ['42', '19', '9.5', '30']],
    ['size' => '2XL', 'values' => ['44', '20', '10', '31']],
    ['size' => '3XL', 'values' => ['46', '21', '10.5', '32']],
]];
$bottom = ['columns' => ['Waist (in)', 'Hip (in)', 'Inseam (in)', 'Length (in)'], 'rows' => [
    ['size' => 'S', 'values' => ['28', '36', '30', '39']],
    ['size' => 'M', 'values' => ['30', '38', '30.5', '40']],
    ['size' => 'L', 'values' => ['32', '40', '31', '41']],
    ['size' => 'XL', 'values' => ['34', '42', '31.5', '42']],
    ['size' => '2XL', 'values' => ['36', '44', '32', '42.5']],
    ['size' => '3XL', 'values' => ['38', '46', '32', '43']],
]];
$set = ['columns' => ['Chest (in)', 'Top length (in)', 'Waist (in)', 'Pants length (in)'], 'rows' => [
    ['size' => 'XS', 'values' => ['34', '26', '26', '38']],
    ['size' => 'S', 'values' => ['36', '27', '28', '39']],
    ['size' => 'M', 'values' => ['38', '28', '30', '40']],
    ['size' => 'L', 'values' => ['40', '29', '32', '41']],
    ['size' => 'XL', 'values' => ['42', '30', '34', '42']],
    ['size' => '2XL', 'values' => ['44', '31', '36', '42.5']],
]];
$kids = ['columns' => ['Chest (in)', 'Top length (in)', 'Waist (in)', 'Bottom length (in)'], 'rows' => [
    ['size' => '6', 'values' => ['26', '19', '22', '26']],
    ['size' => '8', 'values' => ['28', '20', '23', '28']],
    ['size' => '10', 'values' => ['30', '21', '24', '30']],
    ['size' => '12', 'values' => ['32', '23', '25', '32']],
    ['size' => '14', 'values' => ['34', '25', '26', '34']],
]];
$jersey = ['columns' => ['Chest (in)', 'Jersey length (in)', 'Shorts waist (in)', 'Shorts length (in)'], 'rows' => [
    ['size' => 'XS', 'values' => ['36', '27', '26', '17']],
    ['size' => 'S', 'values' => ['38', '28', '28', '18']],
    ['size' => 'M', 'values' => ['40', '29', '30', '19']],
    ['size' => 'L', 'values' => ['42', '30', '32', '20']],
    ['size' => 'XL', 'values' => ['44', '31', '34', '21']],
    ['size' => '2XL', 'values' => ['46', '32', '36', '22']],
    ['size' => '3XL', 'values' => ['48', '33', '38', '22']],
]];

$q = fn (string $id, string $label, string $type = 'text', bool $required = false, array $options = []) => array_filter(
    ['id' => $id, 'label' => $label, 'type' => $type, 'required' => $required, 'options' => $options ?: null],
    fn ($v) => $v !== null,
);
$opt = fn (string $label, float $amount, ?string $covers = null, ?string $soldAs = null) => ['label' => $label, 'amount' => $amount, 'covers' => $covers, 'sold_as' => $soldAs];
$chart = fn (array $c) => ['size_chart_columns' => $c['columns'], 'size_chart_rows' => $c['rows']];
$deposit = fn (int $percent = 50) => ['payment_policy' => 'deposit', 'payment_policy_percent' => $percent];

return [
    'services' => [
        // ---------------- Made to order: one part ----------------
        [
            'name' => 'Polo & Dress Shirt (Made to Measure)', 'image' => 'polo-dress-shirt.webp',
            'service_category' => 'custom_tailoring', 'garment_types' => ['polo_shirt', 'dress_shirt', 'button_down_shirt'],
            'capabilities' => ['made_to_measure', 'full_package', 'labor_only'],
            'description' => 'A polo or dress shirt sewn to your size. Pick a standard size from the chart or let us measure you. Bring your own fabric and pay less.',
            'price_display' => 'fixed', 'base_price' => 650, 'estimated_days' => 5, 'estimated_days_max' => 7,
            'measurement_requirement' => 'shop', 'fitting_requirement' => 'optional', ...$deposit(), 'own_fabric_deduction' => 200,
            'pricing_tiers' => [$opt('Short-sleeve polo', 650, 'top', 'garment'), $opt('Long-sleeve dress shirt', 850, 'top', 'garment')],
            ...$chart($top),
            'custom_fields' => [$q('collar', 'Collar style', 'select', true, ['Regular', 'Mandarin', 'Button-down']), $q('pocket', 'Chest pocket', 'radio', false, ['With pocket', 'No pocket'])],
        ],
        [
            'name' => 'Slacks & Trousers (Made to Measure)', 'image' => 'slacks-trousers.webp',
            'service_category' => 'custom_tailoring', 'garment_types' => ['trousers', 'dress_pants', 'chinos'],
            'capabilities' => ['made_to_measure', 'full_package', 'labor_only'],
            'description' => 'Office slacks, dress pants or chinos cut to your waist, hip and length.',
            'price_display' => 'fixed', 'base_price' => 750, 'estimated_days' => 5, 'estimated_days_max' => 7,
            'measurement_requirement' => 'shop', 'fitting_requirement' => 'optional', ...$deposit(), 'own_fabric_deduction' => 200,
            'pricing_tiers' => [$opt('Office slacks', 750, 'bottom', 'garment'), $opt('Chinos', 700, 'bottom', 'garment')],
            ...$chart($bottom),
            'custom_fields' => [$q('pleats', 'Front', 'select', true, ['Flat front', 'Single pleat', 'Double pleat']), $q('hem', 'Hem finish', 'select', false, ['Plain hem', 'Cuffed hem'])],
        ],
        [
            'name' => 'Skirt Sewing (Office & School)', 'image' => 'skirt-sewing.webp',
            'service_category' => 'custom_tailoring', 'garment_types' => ['formal_skirt', 'casual_skirt'],
            'capabilities' => ['made_to_measure', 'full_package'],
            'description' => 'A-line or pencil skirt for office or school, sewn to your waist and length.',
            'price_display' => 'fixed', 'base_price' => 500, 'estimated_days' => 4, 'estimated_days_max' => 6,
            'measurement_requirement' => 'shop', 'fitting_requirement' => 'none', ...$deposit(),
            'pricing_tiers' => [$opt('A-line skirt', 500, 'bottom', 'garment'), $opt('Pencil skirt', 550, 'bottom', 'garment')],
            ...$chart($bottom),
            'custom_fields' => [$q('skirt_length', 'Skirt length', 'select', true, ['Above the knee', 'Knee length', 'Below the knee'])],
        ],
        [
            'name' => 'Corporate Blazer', 'image' => 'corporate-blazer.webp',
            'service_category' => 'custom_tailoring', 'garment_types' => ['blazer', 'formal_vest'],
            'capabilities' => ['made_to_measure', 'bespoke_pattern_making', 'full_package'],
            'description' => 'A lined blazer for work or events. The price starts here and depends on the fabric you choose.',
            'price_display' => 'starting', 'base_price' => 2500, 'estimated_days' => 10, 'estimated_days_max' => 14,
            'measurement_requirement' => 'shop', 'fitting_requirement' => 'required', ...$deposit(),
            'pricing_tiers' => [$opt('Poly-blend blazer', 2500, 'top', 'garment'), $opt('Wool-blend blazer', 3800, 'top', 'garment')],
            'custom_fields' => [$q('buttons', 'Buttons', 'radio', true, ['One button', 'Two buttons']), $q('lining_color', 'Lining colour')],
        ],
        [
            'name' => 'Jacket & Varsity Jacket', 'image' => 'varsity-jacket.webp',
            'service_category' => 'custom_tailoring', 'garment_types' => ['jacket', 'overshirt'],
            'capabilities' => ['made_to_measure', 'full_package', 'garment_embroidery'],
            'description' => 'Varsity, windbreaker or work jacket. Add your school or company name on the back.',
            'price_display' => 'starting', 'base_price' => 1200, 'estimated_days' => 7, 'estimated_days_max' => 12,
            'measurement_requirement' => 'shop', 'fitting_requirement' => 'optional', ...$deposit(),
            'pricing_tiers' => [$opt('Windbreaker', 1200, 'top', 'garment'), $opt('Varsity jacket', 1800, 'top', 'garment')],
            ...$chart($top),
            'custom_fields' => [$q('back_text', 'Text on the back (optional)'), $q('lining', 'Lining', 'checkbox')],
        ],
        [
            'name' => 'Sewing With Your Own Fabric (Labor Only)', 'image' => 'labor-only.webp',
            'service_category' => 'custom_tailoring', 'garment_types' => ['casual_shirt', 'casual_blouse', 'trousers', 'casual_skirt'],
            'capabilities' => ['labor_only', 'made_to_measure'],
            'description' => 'Bring your fabric and we sew it. We check that the fabric is enough and suitable before the price is final.',
            'price_display' => 'starting', 'base_price' => 350, 'estimated_days' => 5, 'estimated_days_max' => 8,
            'measurement_requirement' => 'shop', 'fitting_requirement' => 'optional', ...$deposit(),
            'pricing_tiers' => [$opt('Top (blouse or shirt)', 350, 'top', 'garment'), $opt('Bottom (pants or skirt)', 400, 'bottom', 'garment'), $opt('Top and bottom', 700, 'both', 'set')],
            'custom_fields' => [$q('yards', 'How many yards are you bringing?', 'number', true), $q('pattern', 'Do you have a sample or blueprint to copy?', 'radio', true, ['Yes, I will bring it', 'No, use the shop pattern'])],
        ],

        // ---------------- Made to order: sets (top, bottom, or both) ----------------
        [
            'name' => 'Office Uniform (Blouse, Skirt or Slacks)', 'image' => 'office-uniform.webp',
            'service_category' => 'uniform_production', 'garment_types' => ['office_uniform', 'corporate_uniform'],
            'capabilities' => ['custom_measured_uniform', 'standard_size_production', 'bulk_orders', 'uniform_embroidery'],
            'description' => 'Office uniform for one person or the whole team. Order the top, the bottom, or the set (the set costs less). Each part has its own size.',
            'price_display' => 'fixed', 'base_price' => 600, 'estimated_days' => 10, 'estimated_days_max' => 21, 'min_order_qty' => 5,
            'measurement_requirement' => 'shop', 'fitting_requirement' => 'optional', ...$deposit(),
            'pricing_tiers' => [$opt('Blouse or polo only', 600, 'top', 'garment'), $opt('Skirt or slacks only', 650, 'bottom', 'garment'), $opt('Complete set', 1150, 'both', 'set')],
            ...$chart($set),
            'custom_fields' => [$q('company', 'Company or office name', 'text', true), $q('uniform_color', 'Main colour', 'select', true, ['Navy', 'Black', 'Maroon', 'White', 'Other (write in notes)'])],
            'roster_fields' => [$q('department', 'Department')],
        ],
        [
            'name' => 'Nursing & Medical Scrub Suit', 'image' => 'scrub-suit.webp',
            'service_category' => 'uniform_production', 'garment_types' => ['medical_uniform'],
            'capabilities' => ['custom_measured_uniform', 'standard_size_production', 'bulk_orders', 'uniform_embroidery'],
            'description' => 'Scrub top, scrub pants or the full suit. One nurse or a whole class. Name on the pocket on request.',
            'price_display' => 'fixed', 'base_price' => 450, 'estimated_days' => 7, 'estimated_days_max' => 14, 'min_order_qty' => 10,
            'measurement_requirement' => 'none', 'fitting_requirement' => 'none', ...$deposit(),
            'pricing_tiers' => [$opt('Scrub top only', 450, 'top', 'garment'), $opt('Scrub pants only', 450, 'bottom', 'garment'), $opt('Scrub suit (top and pants)', 850, 'both', 'set')],
            ...$chart($set),
            'custom_fields' => [$q('scrub_color', 'Colour', 'select', true, ['Ceil blue', 'Navy', 'Wine', 'Teal', 'White']), $q('school', 'School or hospital (for a class order)')],
            'roster_fields' => [$q('Nickname', 'Nickname', 'text')],
        ],
        [
            'name' => 'School Uniform by the Piece', 'image' => 'school-uniform-piece.webp',
            'service_category' => 'uniform_production', 'garment_types' => ['school_uniform', 'school_uniform_pants', 'school_uniform_skirt'],
            'capabilities' => ['custom_measured_uniform', 'standard_size_production', 'bulk_orders'],
            'description' => 'Replace one piece or order for a whole section: polo, blouse, pants or skirt in kids and adult sizes.',
            'price_display' => 'fixed', 'base_price' => 380, 'estimated_days' => 5, 'estimated_days_max' => 10, 'min_order_qty' => 10,
            'measurement_requirement' => 'none', 'fitting_requirement' => 'none', ...$deposit(),
            'pricing_tiers' => [$opt('Polo', 380, 'top', 'garment'), $opt('Blouse', 380, 'top', 'garment'), $opt('Pants', 420, 'bottom', 'garment'), $opt('Skirt', 400, 'bottom', 'garment')],
            ...$chart($kids),
            'custom_fields' => [$q('school_name', 'School name', 'text', true), $q('grade', 'Grade or year level')],
            'roster_fields' => [$q('section', 'Section')],
        ],
        [
            'name' => 'Basketball Jersey & Shorts (Cut and Sew)', 'image' => 'basketball-jersey.webp',
            'service_category' => 'uniform_production', 'garment_types' => ['basketball_jersey', 'basketball_shorts', 'custom_team_uniform'],
            'capabilities' => ['standard_size_production', 'bulk_orders', 'cut_and_sew_jersey'],
            'description' => 'Sewn team jerseys and shorts for leagues and barangay teams. Group orders only: a name, number and size for each player.',
            'price_display' => 'fixed', 'base_price' => 380, 'estimated_days' => 10, 'estimated_days_max' => 14, 'min_order_qty' => 8,
            'measurement_requirement' => 'none', 'fitting_requirement' => 'none', ...$deposit(),
            'pricing_tiers' => [$opt('Jersey only', 380, 'top', 'garment'), $opt('Shorts only', 380, 'bottom', 'garment'), $opt('Jersey and shorts set', 700, 'both', 'set')],
            ...$chart($jersey),
            'custom_fields' => [$q('jersey_colors', 'Jersey colours', 'text', true)],
            'roster_fields' => [$q('Jersey Number', 'Jersey Number', 'number', true), $q('Nickname', 'Nickname', 'text'), $q('Position', 'Position', 'select', false, ['Guard', 'Forward', 'Center'])],
        ],
        [
            'name' => 'Chef & Hospitality Uniform', 'image' => 'chef-uniform.webp',
            'service_category' => 'uniform_production', 'garment_types' => ['hospitality_uniform'],
            'capabilities' => ['custom_measured_uniform', 'standard_size_production', 'bulk_orders', 'uniform_embroidery'],
            'description' => 'Chef jacket, chef pants or the set for restaurants, hotels and culinary students.',
            'price_display' => 'fixed', 'base_price' => 900, 'estimated_days' => 10, 'estimated_days_max' => 14, 'min_order_qty' => 6,
            'measurement_requirement' => 'shop', 'fitting_requirement' => 'optional', ...$deposit(),
            'pricing_tiers' => [$opt('Chef jacket only', 900, 'top', 'garment'), $opt('Chef pants only', 700, 'bottom', 'garment'), $opt('Chef jacket and pants', 1500, 'both', 'set')],
            ...$chart($set),
            'custom_fields' => [$q('business', 'Restaurant, hotel or school', 'text', true)],
        ],
        [
            'name' => 'Security Guard Uniform Set', 'image' => 'security-uniform.webp',
            'service_category' => 'uniform_production', 'garment_types' => ['institutional_uniform'],
            'capabilities' => ['standard_size_production', 'bulk_orders'],
            'description' => 'Polo and trousers for security agencies, ordered as a batch. Agency patches are sewn on if you supply them.',
            'price_display' => 'fixed', 'base_price' => 1100, 'estimated_days' => 14, 'estimated_days_max' => 21, 'min_order_qty' => 10,
            'measurement_requirement' => 'none', 'fitting_requirement' => 'none', ...$deposit(),
            'pricing_tiers' => [$opt('Polo and trousers set', 1100, 'both', 'set')],
            ...$chart($set),
            'custom_fields' => [$q('agency', 'Agency name', 'text', true), $q('patches', 'Will you supply the patches?', 'radio', true, ['Yes', 'No'])],
        ],

        // ---------------- Made to order: one piece, fittings, quotation, full payment ----------------
        [
            'name' => 'Filipiniana & Modern Filipiniana', 'image' => 'filipiniana.webp',
            'service_category' => 'custom_tailoring', 'garment_types' => ['filipiniana', 'modern_filipiniana', 'terno'],
            'capabilities' => ['made_to_measure', 'bespoke_pattern_making', 'full_package', 'garment_embroidery'],
            'description' => 'Filipiniana for Buwan ng Wika, weddings and formal events. The price depends on the fabric and embroidery, so we send you a quotation first.',
            'price_display' => 'quotation', 'estimated_days' => 14, 'estimated_days_max' => 30,
            'measurement_requirement' => 'shop', 'fitting_requirement' => 'required', 'payment_policy' => 'full',
            'pricing_tiers' => [$opt('Filipiniana top (bolero or blouse)', 0, 'top', 'garment'), $opt('Full Filipiniana (top and skirt)', 0, 'both', 'set')],
            'custom_fields' => [$q('event_date', 'Event date', 'text', true), $q('sleeve', 'Sleeve', 'select', true, ['Butterfly', 'Puff', 'Sleeveless'])],
        ],
        [
            'name' => 'Debut & Evening Gown', 'image' => 'debut-gown.webp',
            'service_category' => 'custom_tailoring', 'garment_types' => ['debut_gown', 'evening_gown', 'custom_gown'],
            'capabilities' => ['made_to_measure', 'bespoke_pattern_making', 'full_package', 'garment_embroidery'],
            'description' => 'A gown designed with you, with two fittings. A 70% deposit holds the materials and the schedule.',
            'price_display' => 'starting', 'base_price' => 6500, 'estimated_days' => 21, 'estimated_days_max' => 45,
            'measurement_requirement' => 'shop', 'fitting_requirement' => 'required', ...$deposit(70),
            'pricing_tiers' => [$opt('Simple gown', 6500, 'both', 'garment'), $opt('Gown with beadwork', 12000, 'both', 'garment')],
            'custom_fields' => [$q('event_date', 'Event date', 'text', true), $q('silhouette', 'Silhouette', 'select', true, ['A-line', 'Ball gown', 'Mermaid', 'Not sure yet'])],
        ],
        [
            'name' => 'School Program Costume (Kids)', 'image' => 'kids-costume.webp',
            'service_category' => 'custom_tailoring', 'garment_types' => ['cultural_dance_costume', 'stage_costume'],
            'capabilities' => ['full_package'],
            'description' => 'Costumes for school programs and folk dances in standard kids sizes. No measuring visit needed.',
            'price_display' => 'fixed', 'base_price' => 450, 'estimated_days' => 5, 'estimated_days_max' => 7,
            'measurement_requirement' => 'none', 'fitting_requirement' => 'none', ...$deposit(),
            'pricing_tiers' => [$opt('Costume top', 450, 'top', 'garment'), $opt('Full costume', 750, 'both', 'set')],
            ...$chart($kids),
            'custom_fields' => [$q('program', 'Program or dance', 'text', true)],
        ],

        // ---------------- Work on the customer's own garment ----------------
        [
            'name' => 'Pants Hemming & Waist Adjustment', 'image' => 'pants-hemming.webp',
            'service_category' => 'alterations_repairs', 'garment_types' => ['trousers', 'casual_pants_jeans', 'school_uniform_pants'],
            'capabilities' => ['hemming_length_adjustment', 'original_denim_hemming', 'waist_seat_adjustment', 'tapering_resizing'],
            'description' => 'Putol, paliit or paluwag for pants and jeans. Bring the pants; most are ready in a day.',
            'price_display' => 'fixed', 'base_price' => 80, 'estimated_days' => 1, 'estimated_days_max' => 2,
            'measurement_requirement' => 'none', 'fitting_requirement' => 'none',
            'pricing_tiers' => [$opt('Hem (putol)', 80), $opt('Original denim hem', 150), $opt('Take in the waist (paliit)', 150), $opt('Let out the waist (paluwag)', 180), $opt('Taper the legs (baston)', 250)],
        ],
        [
            'name' => 'Shirt & Blouse Alteration', 'image' => 'shirt-alteration.webp',
            'service_category' => 'alterations_repairs', 'garment_types' => ['polo_shirt', 'dress_shirt', 'casual_blouse', 'school_uniform'],
            'capabilities' => ['sleeve_adjustment', 'tapering_resizing', 'hemming_length_adjustment'],
            'description' => 'Shorten sleeves, take in the sides or shorten the length of a shirt or blouse.',
            'price_display' => 'fixed', 'base_price' => 120, 'estimated_days' => 1, 'estimated_days_max' => 3,
            'measurement_requirement' => 'none', 'fitting_requirement' => 'none',
            'pricing_tiers' => [$opt('Shorten sleeves', 120), $opt('Take in the sides', 180), $opt('Shorten the length', 120)],
        ],
        [
            'name' => 'Zipper, Button & Tear Repair', 'image' => 'zipper-repair.webp',
            'service_category' => 'alterations_repairs', 'garment_types' => [],
            'capabilities' => ['zipper_replacement', 'button_fastener_replacement', 'garment_repair_mending'],
            'description' => 'Broken zipper, missing buttons or a torn seam on any garment. The final price depends on the damage.',
            'price_display' => 'starting', 'base_price' => 30, 'estimated_days' => 1, 'estimated_days_max' => 3,
            'measurement_requirement' => 'none', 'fitting_requirement' => 'none',
            'pricing_tiers' => [$opt('Replace a zipper', 120), $opt('Sew on buttons (up to 5)', 30), $opt('Mend a torn seam', 80), $opt('Patch a hole', 150)],
        ],
        [
            'name' => 'Gown & Dress Alteration', 'image' => 'gown-alteration.webp',
            'service_category' => 'alterations_repairs', 'garment_types' => ['custom_gown', 'formal_dress', 'casual_dress'],
            'capabilities' => ['tapering_resizing', 'hemming_length_adjustment', 'garment_repair_mending'],
            'description' => 'Resize, shorten or repair a gown or dress. Every gown is different, so the shop prices it after seeing it.',
            'price_display' => 'quotation', 'estimated_days' => 3, 'estimated_days_max' => 10,
            'measurement_requirement' => 'none', 'fitting_requirement' => 'optional',
            'pricing_tiers' => [$opt('Resize (take in or let out)', 0), $opt('Shorten the hem', 0), $opt('Repair beadwork or lace', 0)],
        ],
        [
            'name' => 'Name & Logo Embroidery on Your Uniform', 'image' => 'name-embroidery.webp',
            'service_category' => 'garment_customization', 'garment_types' => [],
            'capabilities' => ['name_rank_embroidery', 'logo_embroidery', 'badge_patch_attachment'],
            'description' => 'We stitch a name, logo or patch on a uniform you already have. Bring the garment and the logo.',
            'price_display' => 'fixed', 'base_price' => 80, 'estimated_days' => 1, 'estimated_days_max' => 3,
            'measurement_requirement' => 'none', 'fitting_requirement' => 'none',
            'pricing_tiers' => [$opt('Name (up to 15 letters)', 80), $opt('Small logo (up to 3 in)', 150), $opt('Large design or patch embroidery', 350), $opt('Sew on a patch', 50), $opt('New logo setup (one time, for a logo not stitched before)', 500)],
        ],
        [
            'name' => 'Barong Callado & Monogram', 'image' => 'barong-callado.webp',
            'service_category' => 'garment_customization', 'garment_types' => ['barong_tagalog', 'modern_barong'],
            'capabilities' => ['monogram_callado'],
            'description' => 'Hand-guided callado or initials on a barong you bring. The price starts here and depends on the size of the design.',
            'price_display' => 'starting', 'base_price' => 500, 'estimated_days' => 5, 'estimated_days_max' => 10,
            'measurement_requirement' => 'none', 'fitting_requirement' => 'none',
            'pricing_tiers' => [$opt('Initials (monogram)', 500), $opt('Front callado panel', 1800)],
        ],
    ],

    // The shop's older services, made clear: one service per kind of work, every option says top / bottom / set,
    // no question the order form already asks, and size charts that match their designs. `was` = the name it has now.
    'tidy' => [
        [
            'was' => 'Custom Sublimation Team Jerseys', 'name' => 'Team Sports Jerseys (Cycling, Volleyball, Esports)', 'image' => 'team-sports-jerseys.webp',
            'description' => 'Team jerseys for cycling clubs, volleyball, esports and basketball teams. Choose the jersey only or the full set. Group orders: a name, number and size for each player.',
            'pricing_tiers' => [$opt('Jersey only', 600, 'top', 'garment'), $opt('Mesh jersey set (jersey and shorts)', 1000, 'both', 'set'), $opt('Honeycomb jersey set (jersey and shorts)', 1200, 'both', 'set')],
            'custom_fields' => [],
            'roster_fields' => [$q('Jersey Number', 'Jersey Number', 'number', true), $q('Nickname', 'Nickname', 'text')],
            'size_chart_columns' => $jersey['columns'],
            'size_chart_rows' => [
                ['size' => 'S', 'values' => ['38', '28', '28', '18']], ['size' => 'M', 'values' => ['40', '29', '30', '19']],
                ['size' => 'L', 'values' => ['42', '30', '32', '20']], ['size' => 'XL', 'values' => ['44', '31', '34', '21']],
                ['size' => 'XXL', 'values' => ['46', '32', '36', '22']],
            ],
        ],
        [
            'was' => 'Bespoke Suit Tailoring', 'name' => 'Bespoke Suit Tailoring',
            'pricing_tiers' => [$opt('Blazer or coat only', 2500, 'top', 'garment'), $opt('Classic wool suit (jacket and pants)', 3500, 'both', 'set'), $opt('Premium wool suit with silk lining', 5500, 'both', 'set'), $opt('Tuxedo, full canvas', 8000, 'both', 'set')],
            'size_chart_columns' => ['Chest (in)', 'Waist (in)', 'Shoulder (in)', 'Jacket length (in)'],
            'size_chart_rows' => [
                ['size' => '34', 'values' => ['34', '28', '16.5', '28']], ['size' => '36', 'values' => ['36', '30', '17', '28.5']],
                ['size' => '38', 'values' => ['38', '32', '17.5', '29']], ['size' => '40', 'values' => ['40', '34', '18', '29.5']],
                ['size' => '42', 'values' => ['42', '36', '18.5', '30']], ['size' => '44', 'values' => ['44', '38', '19', '30.5']],
                ['size' => '46', 'values' => ['46', '40', '19.5', '31']],
            ],
        ],
        [
            'was' => 'Barong Tagalog Tailoring', 'name' => 'Barong Tagalog Tailoring',
            'pricing_tiers' => [$opt('Plain cotton barong', 1500, 'top', 'garment'), $opt('Jusi barong', 2800, 'top', 'garment'), $opt('Premium piña barong', 4500, 'top', 'garment')],
        ],
        [
            'was' => 'Bridal & Wedding Gown Design', 'name' => 'Bridal & Wedding Gown Design',
            'pricing_tiers' => [$opt('Simple A-line gown', 8000, 'both', 'garment'), $opt('Ball gown with beadwork', 15000, 'both', 'garment'), $opt('Mermaid gown with train', 25000, 'both', 'garment')],
        ],
        [
            'was' => 'School & Organization Uniform Sewing', 'name' => 'School Uniform Set (Top and Bottom)', 'image' => 'school-uniform-set.webp',
            'description' => 'The complete school uniform for one student or a whole class. The top and the bottom each get their own size.',
            'capabilities' => ['custom_measured_uniform', 'standard_size_production', 'bulk_orders'], 'min_order_qty' => 10,
            'garment_types' => ['school_uniform', 'school_uniform_pants', 'school_uniform_skirt'],
            'pricing_tiers' => [$opt('Elementary uniform set', 450, 'both', 'set'), $opt('High school uniform set', 650, 'both', 'set'), $opt('Uniform set with PE uniform', 950, 'both', 'set')],
            'size_chart_columns' => $kids['columns'],
            'size_chart_rows' => array_merge([['size' => '4', 'values' => ['24', '18', '21', '24']]], $kids['rows']),
            'custom_fields' => [$q('school_name', 'School name', 'text', true)],
        ],
        [
            'was' => 'Cultural & Stage Costume Tailoring', 'name' => 'Stage, Folk Dance & Cosplay Costume', 'image' => 'stage-costume.webp',
            'description' => 'Costumes for folk dance troupes, stage plays and cosplay, made to your measurements. For simple school program costumes, see School Program Costume (Kids).',
            'pricing_tiers' => [$opt('Folk dance costume set', 1800, 'both', 'set'), $opt('Stage or character costume', 2500, 'both', 'garment'), $opt('Cosplay costume', 3000, 'both', 'garment')],
        ],
        // Duplicates of a clearer service: archived (the shop can restore them; past orders keep their copy).
        ['was' => 'Corporate & Team Jersey Printing', 'archive' => true],
        ['was' => 'Garment Alterations & Repair Services', 'archive' => true],
        ['was' => 'Embroidery & Logo Digitizing', 'archive' => true],
    ],

    // Older designs moved to the service that makes them, with sizes from that service's chart; `hide` = no service fits.
    'design_fixes' => [
        ['name' => 'Medical Scrub Top', 'service' => 'Nursing & Medical Scrub Suit', 'sizes' => ['S', 'M', 'L', 'XL', '2XL']],
        ['name' => "Women's Tailored Trousers", 'service' => 'Slacks & Trousers (Made to Measure)', 'sizes' => ['S', 'M', 'L', 'XL']],
        ['name' => 'Tailored Chino Trousers', 'service' => 'Slacks & Trousers (Made to Measure)', 'sizes' => ['S', 'M', 'L', 'XL', '2XL']],
        ['name' => 'Tailored Button-Down Shirt', 'service' => 'Polo & Dress Shirt (Made to Measure)', 'sizes' => ['S', 'M', 'L', 'XL', '2XL']],
        ['name' => "Women's Tailored Power Suit", 'service' => 'Bespoke Suit Tailoring', 'sizes' => ['34', '36', '38', '40', '42']],
        ['name' => "Women's Tailored Office Blazer", 'service' => 'Bespoke Suit Tailoring', 'sizes' => ['34', '36', '38', '40', '42']],
        ['name' => 'Tailored Casual Day Dress', 'hide' => true],
    ],

    // Designs: each one is ordered through its service. `pricing_mode`: same (service price), set (own price),
    // extra (service price + extra_charge), quote (priced by the shop).
    'designs' => [
        ['service' => 'Polo & Dress Shirt (Made to Measure)', 'name' => 'Classic White Office Polo', 'image' => 'design-white-polo.webp', 'garment_type' => 'polo_shirt', 'pricing_mode' => 'same', 'material' => 'Cotton blend', 'color' => 'White', 'sizes' => ['S', 'M', 'L', 'XL', '2XL', '3XL'], 'description' => 'Plain white short-sleeve polo with a regular collar.'],
        ['service' => 'Polo & Dress Shirt (Made to Measure)', 'name' => 'Barong-Inspired Polo with Embroidery', 'image' => 'design-barong-polo.webp', 'garment_type' => 'polo_shirt', 'pricing_mode' => 'extra', 'extra_charge' => 250, 'material' => 'Cotton', 'color' => 'Ecru', 'sizes' => ['S', 'M', 'L', 'XL', '2XL'], 'description' => 'Short-sleeve polo with light embroidery on the front panel.', 'add_ons' => [['id' => 'initials', 'name' => 'Initials on the cuff', 'price' => 80]]],
        ['service' => 'Slacks & Trousers (Made to Measure)', 'name' => 'Black Flat-Front Office Slacks', 'image' => 'design-black-slacks.webp', 'garment_type' => 'trousers', 'pricing_mode' => 'same', 'material' => 'Poly-viscose', 'color' => 'Black', 'sizes' => ['S', 'M', 'L', 'XL', '2XL'], 'description' => 'Straight-leg office slacks with a flat front.'],
        ['service' => 'Office Uniform (Blouse, Skirt or Slacks)', 'name' => 'Navy Corporate Uniform Set', 'image' => 'design-navy-corporate.webp', 'garment_type' => 'corporate_uniform', 'pricing_mode' => 'set', 'price' => 1250, 'material' => 'Gabardine', 'color' => 'Navy', 'sizes' => ['XS', 'S', 'M', 'L', 'XL', '2XL'], 'description' => 'Navy blouse with piping and a matching skirt or slacks. Logo embroidery available.', 'add_ons' => [['id' => 'logo', 'name' => 'Company logo embroidery', 'price' => 150]]],
        ['service' => 'Office Uniform (Blouse, Skirt or Slacks)', 'name' => 'Maroon Bank Teller Uniform', 'image' => 'design-maroon-teller.webp', 'garment_type' => 'office_uniform', 'pricing_mode' => 'same', 'material' => 'Gabardine', 'color' => 'Maroon', 'sizes' => ['XS', 'S', 'M', 'L', 'XL'], 'description' => 'Maroon blouse with a scarf tie and a black skirt.'],
        ['service' => 'Nursing & Medical Scrub Suit', 'name' => 'Ceil Blue V-Neck Scrub Suit', 'image' => 'design-ceil-scrub.webp', 'garment_type' => 'medical_uniform', 'pricing_mode' => 'same', 'material' => 'Poly-cotton', 'color' => 'Ceil blue', 'sizes' => ['XS', 'S', 'M', 'L', 'XL', '2XL'], 'description' => 'V-neck scrub top with three pockets and drawstring pants.', 'add_ons' => [['id' => 'pocket_name', 'name' => 'Name on the pocket', 'price' => 80]]],
        ['service' => 'Nursing & Medical Scrub Suit', 'name' => 'Jogger Scrub Suit (Wine)', 'image' => 'design-wine-scrub.webp', 'garment_type' => 'medical_uniform', 'pricing_mode' => 'extra', 'extra_charge' => 150, 'material' => 'Stretch poly', 'color' => 'Wine', 'sizes' => ['S', 'M', 'L', 'XL'], 'description' => 'Modern fit scrub top with jogger pants.'],
        ['service' => 'School Uniform by the Piece', 'name' => 'Elementary White Polo with Patch', 'image' => 'design-elem-polo.webp', 'garment_type' => 'school_uniform', 'pricing_mode' => 'same', 'material' => 'Cotton', 'color' => 'White', 'sizes' => ['6', '8', '10', '12', '14'], 'description' => 'White polo with the school patch sewn on the left chest.'],
        ['service' => 'Basketball Jersey & Shorts (Cut and Sew)', 'name' => 'Classic Mesh Team Jersey', 'image' => 'design-mesh-jersey.webp', 'garment_type' => 'basketball_jersey', 'pricing_mode' => 'same', 'material' => 'Mesh', 'color' => 'Royal blue and white', 'sizes' => ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL'], 'description' => 'Two-colour mesh jersey and shorts with sewn numbers.'],
        ['service' => 'Chef & Hospitality Uniform', 'name' => 'Double-Breasted Chef Jacket', 'image' => 'design-chef-jacket.webp', 'garment_type' => 'hospitality_uniform', 'pricing_mode' => 'set', 'price' => 1000, 'material' => 'Twill', 'color' => 'White', 'sizes' => ['XS', 'S', 'M', 'L', 'XL', '2XL'], 'description' => 'White double-breasted chef jacket with cloth buttons.'],
        ['service' => 'Corporate Blazer', 'name' => 'Charcoal Two-Button Blazer', 'image' => 'design-charcoal-blazer.webp', 'garment_type' => 'blazer', 'pricing_mode' => 'same', 'material' => 'Wool blend', 'color' => 'Charcoal', 'description' => 'Two-button notch-lapel blazer, fully lined.'],
        ['service' => 'Filipiniana & Modern Filipiniana', 'name' => 'Modern Filipiniana with Butterfly Sleeves', 'image' => 'design-modern-filipiniana.webp', 'garment_type' => 'modern_filipiniana', 'pricing_mode' => 'quote', 'material' => 'Jusi and organza', 'color' => 'Ivory', 'description' => 'Modern cut with structured butterfly sleeves and a fitted skirt.'],
        ['service' => 'Debut & Evening Gown', 'name' => 'Blush A-Line Debut Gown', 'image' => 'design-blush-debut.webp', 'garment_type' => 'debut_gown', 'pricing_mode' => 'set', 'price' => 9500, 'material' => 'Tulle and lace', 'color' => 'Blush pink', 'description' => 'A-line gown with lace bodice and a soft tulle skirt.'],
        ['service' => 'Debut & Evening Gown', 'name' => 'Emerald Mermaid Evening Gown', 'image' => 'design-emerald-mermaid.webp', 'garment_type' => 'evening_gown', 'pricing_mode' => 'quote', 'material' => 'Satin', 'color' => 'Emerald green', 'description' => 'Fitted mermaid silhouette with a short train.'],
        ['service' => 'School Program Costume (Kids)', 'name' => 'Tinikling Costume (Kids)', 'image' => 'design-tinikling.webp', 'garment_type' => 'cultural_dance_costume', 'pricing_mode' => 'same', 'material' => 'Cotton', 'color' => 'Red and white', 'sizes' => ['6', '8', '10', '12', '14'], 'description' => 'Baro and patadyong set for the tinikling dance.'],
    ],
];
