<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Shop;

class CatalogItemsSeeder extends Seeder
{
    public function run(): void
    {
        $shop = Shop::first(); // First shop is the main shop owner
        if (!$shop) {
            return;
        }

        $item_0 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Andrea & Leo A1237 Off Shoulder Slit Leg Floral Tulle A Line Gown'],
            [
                'price' => 4500.0,
                'material' => 'Chiffon & Tulle',
                'description' => 'Elegant designer Andrea & Leo A1237 Off Shoulder Slit Leg Floral Tulle A Line Gown available for rentals and custom sizing.',
                'garment_type' => 'gown',
                'listing_type' => 'for_rent',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_0->id, 'image_url' => '/catalog/Andrea & Leo A1237 Off Shoulder Slit Leg Floral Tulle A Line Gown.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_1 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Long Maid Of Honour Dresses Leia Modest Sweetheart Pleated Chiffon Maid Of Honor'],
            [
                'price' => 4500.0,
                'material' => 'Chiffon & Tulle',
                'description' => 'Elegant designer Long Maid Of Honour Dresses Leia Modest Sweetheart Pleated Chiffon Maid Of Honor available for rentals and custom sizing.',
                'garment_type' => 'gown',
                'listing_type' => 'for_rent',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_1->id, 'image_url' => '/catalog/Long Maid Of Honour Dresses Leia Modest Sweetheart Pleated Chiffon Maid Of Honor.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_2 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Cycling_Jerseys_1'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear Cycling_Jerseys_1 designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_2->id, 'image_url' => '/catalog/Cycling_Jerseys_1.jpeg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_3 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Shop Long Tail Wedding Gown '],
            [
                'price' => 4500.0,
                'material' => 'Chiffon & Tulle',
                'description' => 'Elegant designer Shop Long Tail Wedding Gown  available for rentals and custom sizing.',
                'garment_type' => 'gown',
                'listing_type' => 'for_rent',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_3->id, 'image_url' => '/catalog/Shop Long Tail Wedding Gown .jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_4 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Mga Pretty Bridesmaid Dresses, Perfect Maid of Honor Gowns - Lunss'],
            [
                'price' => 4500.0,
                'material' => 'Chiffon & Tulle',
                'description' => 'Elegant designer Mga Pretty Bridesmaid Dresses, Perfect Maid of Honor Gowns - Lunss available for rentals and custom sizing.',
                'garment_type' => 'gown',
                'listing_type' => 'for_rent',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_4->id, 'image_url' => '/catalog/Mga Pretty Bridesmaid Dresses, Perfect Maid of Honor Gowns - Lunss.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_5 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'esport tshirt blue'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear esport tshirt blue designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_5->id, 'image_url' => '/catalog/esport tshirt blue.jpeg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_6 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => "Women's Esports Jersey with Customized Design "],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => "Custom sublimation activewear Women's Esports Jersey with Customized Design  designed for maximum breathability.",
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_6->id, 'image_url' => "/catalog/Women's Esports Jersey with Customized Design .jpg"],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_7 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Bulls-Basketball-Jersey'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear Bulls-Basketball-Jersey designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_7->id, 'image_url' => '/catalog/Bulls-Basketball-Jersey.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_8 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Pink Chiffon Mother of the Bride Dresses Simple Scoop Neck Long Sleeves Pearls Tea-Length A-LINE Evening Mother Gowns '],
            [
                'price' => 4500.0,
                'material' => 'Chiffon & Tulle',
                'description' => 'Elegant designer Pink Chiffon Mother of the Bride Dresses Simple Scoop Neck Long Sleeves Pearls Tea-Length A-LINE Evening Mother Gowns  available for rentals and custom sizing.',
                'garment_type' => 'gown',
                'listing_type' => 'for_rent',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_8->id, 'image_url' => '/catalog/Pink Chiffon Mother of the Bride Dresses Simple Scoop Neck Long Sleeves Pearls Tea-Length A-LINE Evening Mother Gowns .jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_9 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'images'],
            [
                'price' => 1500.0,
                'material' => 'Premium Fabric',
                'description' => 'High-quality custom images tailored to perfection.',
                'garment_type' => 'other',
                'listing_type' => 'ready_to_wear',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_9->id, 'image_url' => '/catalog/images.jpeg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_10 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'KobeBryant-Basketball-Jersey'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear KobeBryant-Basketball-Jersey designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_10->id, 'image_url' => '/catalog/KobeBryant-Basketball-Jersey.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_11 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Greed Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora'],
            [
                'price' => 1500.0,
                'material' => 'Premium Fabric',
                'description' => 'High-quality custom Greed Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora tailored to perfection.',
                'garment_type' => 'other',
                'listing_type' => 'ready_to_wear',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_11->id, 'image_url' => '/catalog/Greed Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_12 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Best Custom Tuxedos in NYC | Bespoke Groom Tuxedos '],
            [
                'price' => 12000.0,
                'material' => 'Premium Wool',
                'description' => 'Bespoke premium Best Custom Tuxedos in NYC | Bespoke Groom Tuxedos  crafted for formal attire and weddings.',
                'garment_type' => 'suit',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_12->id, 'image_url' => '/catalog/Best Custom Tuxedos in NYC | Bespoke Groom Tuxedos .jpeg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_13 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Bespoke_Suits2'],
            [
                'price' => 12000.0,
                'material' => 'Premium Wool',
                'description' => 'Bespoke premium Bespoke_Suits2 crafted for formal attire and weddings.',
                'garment_type' => 'suit',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_13->id, 'image_url' => '/catalog/Bespoke_Suits2.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_14 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Buy Luxury White Tail Wedding Gown with Champagne-Gold Embroidery | Elegant Bridal Dress with Corset Back | Floor-Length Wedding Dress for Women (in, Alpha, 2XL, White with Champagne-Gold Embroidery) at Amazon.in '],
            [
                'price' => 4500.0,
                'material' => 'Chiffon & Tulle',
                'description' => 'Elegant designer Buy Luxury White Tail Wedding Gown with Champagne-Gold Embroidery | Elegant Bridal Dress with Corset Back | Floor-Length Wedding Dress for Women (in, Alpha, 2XL, White with Champagne-Gold Embroidery) at Amazon.in  available for rentals and custom sizing.',
                'garment_type' => 'gown',
                'listing_type' => 'for_rent',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_14->id, 'image_url' => '/catalog/Buy Luxury White Tail Wedding Gown with Champagne-Gold Embroidery | Elegant Bridal Dress with Corset Back | Floor-Length Wedding Dress for Women (in, Alpha, 2XL, White with Champagne-Gold Embroidery) at Amazon.in .jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_15 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Traditional Ivory Color Barong Tagalog | Formal Fit'],
            [
                'price' => 4500.0,
                'material' => 'Pina Cocoon',
                'description' => 'Traditional Filipino Traditional Ivory Color Barong Tagalog | Formal Fit featuring delicate hand embroidery.',
                'garment_type' => 'barong',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_15->id, 'image_url' => '/catalog/Traditional Ivory Color Barong Tagalog | Formal Fit.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_16 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Riders_Long_Sleeves'],
            [
                'price' => 1500.0,
                'material' => 'Premium Fabric',
                'description' => 'High-quality custom Riders_Long_Sleeves tailored to perfection.',
                'garment_type' => 'other',
                'listing_type' => 'ready_to_wear',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_16->id, 'image_url' => '/catalog/Riders_Long_Sleeves.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_17 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'AllStar-Basketball-Jersey'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear AllStar-Basketball-Jersey designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_17->id, 'image_url' => '/catalog/AllStar-Basketball-Jersey.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_18 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'mens-custom-tuxedos-its-all-about-the-fit'],
            [
                'price' => 12000.0,
                'material' => 'Premium Wool',
                'description' => 'Bespoke premium mens-custom-tuxedos-its-all-about-the-fit crafted for formal attire and weddings.',
                'garment_type' => 'suit',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_18->id, 'image_url' => '/catalog/mens-custom-tuxedos-its-all-about-the-fit.jpeg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_19 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Volleyball Jersey_2'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear Volleyball Jersey_2 designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_19->id, 'image_url' => '/catalog/Volleyball Jersey_2.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_20 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Cycling_Jerseys_3'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear Cycling_Jerseys_3 designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_20->id, 'image_url' => '/catalog/Cycling_Jerseys_3.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_21 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Riders_Long_Sleeves_2'],
            [
                'price' => 1500.0,
                'material' => 'Premium Fabric',
                'description' => 'High-quality custom Riders_Long_Sleeves_2 tailored to perfection.',
                'garment_type' => 'other',
                'listing_type' => 'ready_to_wear',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_21->id, 'image_url' => '/catalog/Riders_Long_Sleeves_2.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_22 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Vintage Dark Teal Mother Gowns for Wedding Women 2024 Lace Mother of the Groom Dress Long Sleeve ZXI'],
            [
                'price' => 4500.0,
                'material' => 'Chiffon & Tulle',
                'description' => 'Elegant designer Vintage Dark Teal Mother Gowns for Wedding Women 2024 Lace Mother of the Groom Dress Long Sleeve ZXI available for rentals and custom sizing.',
                'garment_type' => 'gown',
                'listing_type' => 'for_rent',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_22->id, 'image_url' => '/catalog/Vintage Dark Teal Mother Gowns for Wedding Women 2024 Lace Mother of the Groom Dress Long Sleeve ZXI.jpeg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_23 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Cycling_Jerseys_2'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear Cycling_Jerseys_2 designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_23->id, 'image_url' => '/catalog/Cycling_Jerseys_2.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_24 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Bespoke_Suits'],
            [
                'price' => 12000.0,
                'material' => 'Premium Wool',
                'description' => 'Bespoke premium Bespoke_Suits crafted for formal attire and weddings.',
                'garment_type' => 'suit',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_24->id, 'image_url' => '/catalog/Bespoke_Suits.png'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_25 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Tailor Made Suits London - The Bespoke Tailor UK'],
            [
                'price' => 12000.0,
                'material' => 'Premium Wool',
                'description' => 'Bespoke premium Tailor Made Suits London - The Bespoke Tailor UK crafted for formal attire and weddings.',
                'garment_type' => 'suit',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_25->id, 'image_url' => '/catalog/Tailor Made Suits London - The Bespoke Tailor UK.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_26 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Custom_Tuxedos_men'],
            [
                'price' => 12000.0,
                'material' => 'Premium Wool',
                'description' => 'Bespoke premium Custom_Tuxedos_men crafted for formal attire and weddings.',
                'garment_type' => 'suit',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_26->id, 'image_url' => '/catalog/Custom_Tuxedos_men.jpeg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_27 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Luxury_Bridal_Gowns_Long_Tail'],
            [
                'price' => 4500.0,
                'material' => 'Chiffon & Tulle',
                'description' => 'Elegant designer Luxury_Bridal_Gowns_Long_Tail available for rentals and custom sizing.',
                'garment_type' => 'gown',
                'listing_type' => 'for_rent',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_27->id, 'image_url' => '/catalog/Luxury_Bridal_Gowns_Long_Tail.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_28 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Bears-Basketball-Jersey'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear Bears-Basketball-Jersey designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_28->id, 'image_url' => '/catalog/Bears-Basketball-Jersey.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_29 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'rashguard_1'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear rashguard_1 designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_29->id, 'image_url' => '/catalog/rashguard_1.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_30 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Lebron James-Lakers-Basketball-Jersey'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear Lebron James-Lakers-Basketball-Jersey designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_30->id, 'image_url' => '/catalog/Lebron James-Lakers-Basketball-Jersey.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_31 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => "Men's - Traditional Barong Tagalog - Page 1 - Barong At Bestida Australia"],
            [
                'price' => 4500.0,
                'material' => 'Pina Cocoon',
                'description' => "Traditional Filipino Men's - Traditional Barong Tagalog - Page 1 - Barong At Bestida Australia featuring delicate hand embroidery.",
                'garment_type' => 'barong',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_31->id, 'image_url' => "/catalog/Men's - Traditional Barong Tagalog - Page 1 - Barong At Bestida Australia.jpg"],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_32 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Esports-Jersey-women'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear Esports-Jersey-women designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_32->id, 'image_url' => '/catalog/Esports-Jersey-women.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_33 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Custom Tuxedos for Memorable Events'],
            [
                'price' => 12000.0,
                'material' => 'Premium Wool',
                'description' => 'Bespoke premium Custom Tuxedos for Memorable Events crafted for formal attire and weddings.',
                'garment_type' => 'suit',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_33->id, 'image_url' => '/catalog/Custom Tuxedos for Memorable Events.jpeg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_34 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'rashguard_3'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear rashguard_3 designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_34->id, 'image_url' => '/catalog/rashguard_3.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_35 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Arsenal-Jersey'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear Arsenal-Jersey designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_35->id, 'image_url' => '/catalog/Arsenal-Jersey.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_36 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Traditional Barong Tagalog Polo Shirt for Men'],
            [
                'price' => 4500.0,
                'material' => 'Pina Cocoon',
                'description' => 'Traditional Filipino Traditional Barong Tagalog Polo Shirt for Men featuring delicate hand embroidery.',
                'garment_type' => 'barong',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_36->id, 'image_url' => '/catalog/Traditional Barong Tagalog Polo Shirt for Men.jpeg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_37 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'esport tshirt '],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear esport tshirt  designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_37->id, 'image_url' => '/catalog/esport tshirt .webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_38 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Blue Tuxedo Belt Tuxedo Blue Suit Brown Belt Core Navy'],
            [
                'price' => 12000.0,
                'material' => 'Premium Wool',
                'description' => 'Bespoke premium Blue Tuxedo Belt Tuxedo Blue Suit Brown Belt Core Navy crafted for formal attire and weddings.',
                'garment_type' => 'suit',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_38->id, 'image_url' => '/catalog/Blue Tuxedo Belt Tuxedo Blue Suit Brown Belt Core Navy.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_39 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Barong Tagalog For Sale - Traditional and Modern Filipino Attire for M – Tagged "barong with lining'],
            [
                'price' => 4500.0,
                'material' => 'Pina Cocoon',
                'description' => 'Traditional Filipino Barong Tagalog For Sale - Traditional and Modern Filipino Attire for M – Tagged "barong with lining featuring delicate hand embroidery.',
                'garment_type' => 'barong',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_39->id, 'image_url' => '/catalog/Barong Tagalog For Sale - Traditional and Modern Filipino Attire for M – Tagged "barong with lining.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_40 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => '9 Luxury Designer Bridesmaid Dresses for the Bridal Crew'],
            [
                'price' => 4500.0,
                'material' => 'Chiffon & Tulle',
                'description' => 'Elegant designer 9 Luxury Designer Bridesmaid Dresses for the Bridal Crew available for rentals and custom sizing.',
                'garment_type' => 'gown',
                'listing_type' => 'for_rent',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_40->id, 'image_url' => '/catalog/9 Luxury Designer Bridesmaid Dresses for the Bridal Crew.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_41 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Red Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora'],
            [
                'price' => 1500.0,
                'material' => 'Premium Fabric',
                'description' => 'High-quality custom Red Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora tailored to perfection.',
                'garment_type' => 'other',
                'listing_type' => 'ready_to_wear',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_41->id, 'image_url' => '/catalog/Red Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_42 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Lakers-Basketball-Jersey'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear Lakers-Basketball-Jersey designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_42->id, 'image_url' => '/catalog/Lakers-Basketball-Jersey.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_43 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Light Pink Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora'],
            [
                'price' => 1500.0,
                'material' => 'Premium Fabric',
                'description' => 'High-quality custom Light Pink Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora tailored to perfection.',
                'garment_type' => 'other',
                'listing_type' => 'ready_to_wear',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_43->id, 'image_url' => '/catalog/Light Pink Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_44 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Barong Tagalog Cloth- Traditional and Elegant Fabrics'],
            [
                'price' => 4500.0,
                'material' => 'Pina Cocoon',
                'description' => 'Traditional Filipino Barong Tagalog Cloth- Traditional and Elegant Fabrics featuring delicate hand embroidery.',
                'garment_type' => 'barong',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_44->id, 'image_url' => '/catalog/Barong Tagalog Cloth- Traditional and Elegant Fabrics.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_45 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'volleyballroundneckSET'],
            [
                'price' => 1500.0,
                'material' => 'Premium Fabric',
                'description' => 'High-quality custom volleyballroundneckSET tailored to perfection.',
                'garment_type' => 'other',
                'listing_type' => 'ready_to_wear',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_45->id, 'image_url' => '/catalog/volleyballroundneckSET.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_46 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Elegant Sequined Off White Wedding Dresses with Puff Sleeves and Long Tail from Dhgate Ball Gown Wedding Gown'],
            [
                'price' => 4500.0,
                'material' => 'Chiffon & Tulle',
                'description' => 'Elegant designer Elegant Sequined Off White Wedding Dresses with Puff Sleeves and Long Tail from Dhgate Ball Gown Wedding Gown available for rentals and custom sizing.',
                'garment_type' => 'gown',
                'listing_type' => 'for_rent',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_46->id, 'image_url' => '/catalog/Elegant Sequined Off White Wedding Dresses with Puff Sleeves and Long Tail from Dhgate Ball Gown Wedding Gown.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_47 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'Riders_Long_Sleeves'],
            [
                'price' => 1500.0,
                'material' => 'Premium Fabric',
                'description' => 'High-quality custom Riders_Long_Sleeves tailored to perfection.',
                'garment_type' => 'other',
                'listing_type' => 'ready_to_wear',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_47->id, 'image_url' => '/catalog/Riders_Long_Sleeves.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_48 = \App\Models\CatalogItem::firstOrCreate(
            ['shop_id' => $shop->id, 'name' => 'VBALL_PRE-2001_800x800'],
            [
                'price' => 1500.0,
                'material' => 'Premium Fabric',
                'description' => 'High-quality custom VBALL_PRE-2001_800x800 tailored to perfection.',
                'garment_type' => 'other',
                'listing_type' => 'ready_to_wear',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.'
            ]
        );
        \App\Models\CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_48->id, 'image_url' => '/catalog/VBALL_PRE-2001_800x800.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
    }
}
