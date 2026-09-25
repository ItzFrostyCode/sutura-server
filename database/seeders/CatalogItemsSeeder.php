<?php

namespace Database\Seeders;

use App\Models\CatalogImage;
use App\Models\CatalogItem;
use App\Models\Store;
use Illuminate\Database\Seeder;

class CatalogItemsSeeder extends Seeder
{
    public function run(): void
    {
        $store = Store::first(); // First store is the main store owner
        if (! $store) {
            return;
        }

        $item_0 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Andrea & Leo A1237 Off Shoulder Slit Leg Floral Tulle A Line Gown'],
            [
                'price' => 4500.0,
                'material' => 'Chiffon & Tulle',
                'description' => 'Elegant designer Andrea & Leo A1237 Off Shoulder Slit Leg Floral Tulle A Line Gown made to order with custom sizing.',
                'garment_type' => 'gown',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_0->id, 'image_url' => '/catalog/gown-off-shoulder-tulle-floral.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_1 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Long Maid Of Honour Dresses Leia Modest Sweetheart Pleated Chiffon Maid Of Honor'],
            [
                'price' => 4500.0,
                'material' => 'Chiffon & Tulle',
                'description' => 'Elegant designer Long Maid Of Honour Dresses Leia Modest Sweetheart Pleated Chiffon Maid Of Honor made to order with custom sizing.',
                'garment_type' => 'gown',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_1->id, 'image_url' => '/catalog/maid-of-honor-dress-chiffon.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_2 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Cycling_Jerseys_1'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear Cycling_Jerseys_1 designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_2->id, 'image_url' => '/catalog/Cycling_Jerseys_1.jpeg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_3 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Store Long Tail Wedding Gown'],
            [
                'price' => 4500.0,
                'material' => 'Chiffon & Tulle',
                'description' => 'Elegant designer Store Long Tail Wedding Gown made to order with custom sizing.',
                'garment_type' => 'gown',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_3->id, 'image_url' => '/catalog/Store Long Tail Wedding Gown.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_4 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Mga Pretty Bridesmaid Dresses, Perfect Maid of Honor Gowns - Lunss'],
            [
                'price' => 4500.0,
                'material' => 'Chiffon & Tulle',
                'description' => 'Elegant designer Mga Pretty Bridesmaid Dresses, Perfect Maid of Honor Gowns - Lunss made to order with custom sizing.',
                'garment_type' => 'gown',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_4->id, 'image_url' => '/catalog/bridesmaid-dresses-maid-of-honor.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_5 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'esport tshirt blue'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear esport tshirt blue designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_5->id, 'image_url' => '/catalog/esport tshirt blue.jpeg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_6 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => "Women's Esports Jersey with Customized Design"],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => "Custom sublimation activewear Women's Esports Jersey with Customized Design designed for maximum breathability.",
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_6->id, 'image_url' => "/catalog/Women's Esports Jersey with Customized Design.jpg"],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_7 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Bulls-Basketball-Jersey'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear Bulls-Basketball-Jersey designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_7->id, 'image_url' => '/catalog/Bulls-Basketball-Jersey.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_8 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Pink Chiffon Mother of the Bride Dresses Simple Scoop Neck Long Sleeves Pearls Tea-Length A-LINE Evening Mother Gowns'],
            [
                'price' => 4500.0,
                'material' => 'Chiffon & Tulle',
                'description' => 'Elegant designer Pink Chiffon Mother of the Bride Dresses Simple Scoop Neck Long Sleeves Pearls Tea-Length A-LINE Evening Mother Gowns made to order with custom sizing.',
                'garment_type' => 'gown',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_8->id, 'image_url' => '/catalog/mother-of-bride-dress-chiffon-pink.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_9 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'images'],
            [
                'price' => 1500.0,
                'material' => 'Premium Fabric',
                'description' => 'High-quality custom images tailored to perfection.',
                'garment_type' => 'other',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_9->id, 'image_url' => '/catalog/images.jpeg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_10 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'KobeBryant-Basketball-Jersey'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear KobeBryant-Basketball-Jersey designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_10->id, 'image_url' => '/catalog/KobeBryant-Basketball-Jersey.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_11 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Greed Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora'],
            [
                'price' => 1500.0,
                'material' => 'Premium Fabric',
                'description' => 'High-quality custom Greed Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora tailored to perfection.',
                'garment_type' => 'other',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_11->id, 'image_url' => '/catalog/mother-of-bride-dress-green.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_12 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Best Custom Tuxedos in NYC - Bespoke Groom Tuxedos'],
            [
                'price' => 12000.0,
                'material' => 'Premium Wool',
                'description' => 'Bespoke premium Best Custom Tuxedos in NYC - Bespoke Groom Tuxedos crafted for formal attire and weddings.',
                'garment_type' => 'suit',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_12->id, 'image_url' => '/catalog/bespoke-groom-tuxedo.jpeg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_13 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Bespoke_Suits2'],
            [
                'price' => 12000.0,
                'material' => 'Premium Wool',
                'description' => 'Bespoke premium Bespoke_Suits2 crafted for formal attire and weddings.',
                'garment_type' => 'suit',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_13->id, 'image_url' => '/catalog/Bespoke_Suits2.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_14 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Buy Luxury White Tail Wedding Gown with Champagne-Gold Embroidery - Elegant Bridal Dress with Corset Back - Floor-Length Wedding Dress for Women'],
            [
                'price' => 4500.0,
                'material' => 'Chiffon & Tulle',
                'description' => 'Elegant designer Buy Luxury White Tail Wedding Gown with Champagne-Gold Embroidery - Elegant Bridal Dress with Corset Back - Floor-Length Wedding Dress for Women made to order with custom sizing.',
                'garment_type' => 'gown',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_14->id, 'image_url' => '/catalog/wedding-gown-champagne-embroidery.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_15 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Traditional Ivory Color Barong Tagalog - Formal Fit'],
            [
                'price' => 4500.0,
                'material' => 'Pina Cocoon',
                'description' => 'Traditional Filipino Traditional Ivory Color Barong Tagalog - Formal Fit featuring delicate hand embroidery.',
                'garment_type' => 'barong',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_15->id, 'image_url' => '/catalog/barong-tagalog-ivory-formal.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_16 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Riders_Long_Sleeves'],
            [
                'price' => 1500.0,
                'material' => 'Premium Fabric',
                'description' => 'High-quality custom Riders_Long_Sleeves tailored to perfection.',
                'garment_type' => 'other',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_16->id, 'image_url' => '/catalog/Riders_Long_Sleeves.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_17 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'AllStar-Basketball-Jersey'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear AllStar-Basketball-Jersey designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_17->id, 'image_url' => '/catalog/AllStar-Basketball-Jersey.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_18 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'mens-custom-tuxedos-its-all-about-the-fit'],
            [
                'price' => 12000.0,
                'material' => 'Premium Wool',
                'description' => 'Bespoke premium mens-custom-tuxedos-its-all-about-the-fit crafted for formal attire and weddings.',
                'garment_type' => 'suit',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_18->id, 'image_url' => '/catalog/mens-custom-tuxedos-its-all-about-the-fit.jpeg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_19 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Volleyball Jersey_2'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear Volleyball Jersey_2 designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_19->id, 'image_url' => '/catalog/Volleyball Jersey_2.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_20 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Cycling_Jerseys_3'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear Cycling_Jerseys_3 designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_20->id, 'image_url' => '/catalog/Cycling_Jerseys_3.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_21 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Riders_Long_Sleeves_2'],
            [
                'price' => 1500.0,
                'material' => 'Premium Fabric',
                'description' => 'High-quality custom Riders_Long_Sleeves_2 tailored to perfection.',
                'garment_type' => 'other',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_21->id, 'image_url' => '/catalog/Riders_Long_Sleeves_2.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_22 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Vintage Dark Teal Mother Gowns for Wedding Women 2024 Lace Mother of the Groom Dress Long Sleeve ZXI'],
            [
                'price' => 4500.0,
                'material' => 'Chiffon & Tulle',
                'description' => 'Elegant designer Vintage Dark Teal Mother Gowns for Wedding Women 2024 Lace Mother of the Groom Dress Long Sleeve ZXI made to order with custom sizing.',
                'garment_type' => 'gown',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_22->id, 'image_url' => '/catalog/mother-of-groom-dress-teal-lace.jpeg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_23 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Cycling_Jerseys_2'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear Cycling_Jerseys_2 designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_23->id, 'image_url' => '/catalog/Cycling_Jerseys_2.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_24 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Bespoke_Suits'],
            [
                'price' => 12000.0,
                'material' => 'Premium Wool',
                'description' => 'Bespoke premium Bespoke_Suits crafted for formal attire and weddings.',
                'garment_type' => 'suit',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_24->id, 'image_url' => '/catalog/Bespoke_Suits.png'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_25 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Tailor Made Suits London - The Bespoke Tailor UK'],
            [
                'price' => 12000.0,
                'material' => 'Premium Wool',
                'description' => 'Bespoke premium Tailor Made Suits London - The Bespoke Tailor UK crafted for formal attire and weddings.',
                'garment_type' => 'suit',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_25->id, 'image_url' => '/catalog/tailor-made-suit-bespoke.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_26 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Custom_Tuxedos_men'],
            [
                'price' => 12000.0,
                'material' => 'Premium Wool',
                'description' => 'Bespoke premium Custom_Tuxedos_men crafted for formal attire and weddings.',
                'garment_type' => 'suit',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_26->id, 'image_url' => '/catalog/Custom_Tuxedos_men.jpeg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_27 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Luxury_Bridal_Gowns_Long_Tail'],
            [
                'price' => 4500.0,
                'material' => 'Chiffon & Tulle',
                'description' => 'Elegant designer Luxury_Bridal_Gowns_Long_Tail made to order with custom sizing.',
                'garment_type' => 'gown',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_27->id, 'image_url' => '/catalog/Luxury_Bridal_Gowns_Long_Tail.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_28 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Bears-Basketball-Jersey'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear Bears-Basketball-Jersey designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_28->id, 'image_url' => '/catalog/Bears-Basketball-Jersey.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_29 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'rashguard_1'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear rashguard_1 designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_29->id, 'image_url' => '/catalog/rashguard_1.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_30 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Lebron James-Lakers-Basketball-Jersey'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear Lebron James-Lakers-Basketball-Jersey designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_30->id, 'image_url' => '/catalog/Lebron James-Lakers-Basketball-Jersey.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_31 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => "Men's - Traditional Barong Tagalog - Page 1 - Barong At Bestida Australia"],
            [
                'price' => 4500.0,
                'material' => 'Pina Cocoon',
                'description' => "Traditional Filipino Men's - Traditional Barong Tagalog - Page 1 - Barong At Bestida Australia featuring delicate hand embroidery.",
                'garment_type' => 'barong',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_31->id, 'image_url' => '/catalog/mens-traditional-barong-tagalog.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_32 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Esports-Jersey-women'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear Esports-Jersey-women designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_32->id, 'image_url' => '/catalog/Esports-Jersey-women.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_33 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Custom Tuxedos for Memorable Events'],
            [
                'price' => 12000.0,
                'material' => 'Premium Wool',
                'description' => 'Bespoke premium Custom Tuxedos for Memorable Events crafted for formal attire and weddings.',
                'garment_type' => 'suit',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_33->id, 'image_url' => '/catalog/Custom Tuxedos for Memorable Events.jpeg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_34 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'rashguard_3'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear rashguard_3 designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_34->id, 'image_url' => '/catalog/rashguard_3.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_35 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Arsenal-Jersey'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear Arsenal-Jersey designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_35->id, 'image_url' => '/catalog/Arsenal-Jersey.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_36 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Traditional Barong Tagalog Polo Shirt for Men'],
            [
                'price' => 4500.0,
                'material' => 'Pina Cocoon',
                'description' => 'Traditional Filipino Traditional Barong Tagalog Polo Shirt for Men featuring delicate hand embroidery.',
                'garment_type' => 'barong',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_36->id, 'image_url' => '/catalog/Traditional Barong Tagalog Polo Shirt for Men.jpeg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_37 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'esport tshirt'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear esport tshirt designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_37->id, 'image_url' => '/catalog/esport tshirt.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_38 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Blue Tuxedo Belt Tuxedo Blue Suit Brown Belt Core Navy'],
            [
                'price' => 12000.0,
                'material' => 'Premium Wool',
                'description' => 'Bespoke premium Blue Tuxedo Belt Tuxedo Blue Suit Brown Belt Core Navy crafted for formal attire and weddings.',
                'garment_type' => 'suit',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_38->id, 'image_url' => '/catalog/navy-blue-tuxedo-suit.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_39 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Barong Tagalog For Sale - Traditional and Modern Filipino Attire for M - Tagged barong with lining'],
            [
                'price' => 4500.0,
                'material' => 'Pina Cocoon',
                'description' => 'Traditional Filipino Barong Tagalog For Sale - Traditional and Modern Filipino Attire for M - Tagged barong with lining featuring delicate hand embroidery.',
                'garment_type' => 'barong',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_39->id, 'image_url' => '/catalog/barong-tagalog-lined.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_40 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => '9 Luxury Designer Bridesmaid Dresses for the Bridal Crew'],
            [
                'price' => 4500.0,
                'material' => 'Chiffon & Tulle',
                'description' => 'Elegant designer 9 Luxury Designer Bridesmaid Dresses for the Bridal Crew made to order with custom sizing.',
                'garment_type' => 'gown',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_40->id, 'image_url' => '/catalog/bridesmaid-dresses-bridal-crew.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_41 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Red Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora'],
            [
                'price' => 1500.0,
                'material' => 'Premium Fabric',
                'description' => 'High-quality custom Red Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora tailored to perfection.',
                'garment_type' => 'other',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_41->id, 'image_url' => '/catalog/mother-of-bride-dress-red.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_42 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Lakers-Basketball-Jersey'],
            [
                'price' => 650.0,
                'material' => 'Drifit Mesh',
                'description' => 'Custom sublimation activewear Lakers-Basketball-Jersey designed for maximum breathability.',
                'garment_type' => 'uniform',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_42->id, 'image_url' => '/catalog/Lakers-Basketball-Jersey.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_43 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Light Pink Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora'],
            [
                'price' => 1500.0,
                'material' => 'Premium Fabric',
                'description' => 'High-quality custom Light Pink Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora tailored to perfection.',
                'garment_type' => 'other',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_43->id, 'image_url' => '/catalog/mother-of-bride-dress-pink.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_44 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Barong Tagalog Cloth- Traditional and Elegant Fabrics'],
            [
                'price' => 4500.0,
                'material' => 'Pina Cocoon',
                'description' => 'Traditional Filipino Barong Tagalog Cloth- Traditional and Elegant Fabrics featuring delicate hand embroidery.',
                'garment_type' => 'barong',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_44->id, 'image_url' => '/catalog/barong-tagalog-cloth-fabric.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_45 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'volleyballroundneckSET'],
            [
                'price' => 1500.0,
                'material' => 'Premium Fabric',
                'description' => 'High-quality custom volleyballroundneckSET tailored to perfection.',
                'garment_type' => 'other',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_45->id, 'image_url' => '/catalog/volleyballroundneckSET.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_46 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Elegant Sequined Off White Wedding Dresses with Puff Sleeves and Long Tail from Dhgate Ball Gown Wedding Gown'],
            [
                'price' => 4500.0,
                'material' => 'Chiffon & Tulle',
                'description' => 'Elegant designer Elegant Sequined Off White Wedding Dresses with Puff Sleeves and Long Tail from Dhgate Ball Gown Wedding Gown made to order with custom sizing.',
                'garment_type' => 'gown',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_46->id, 'image_url' => '/catalog/wedding-gown-sequined-puff-sleeve.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_47 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Riders_Long_Sleeves'],
            [
                'price' => 1500.0,
                'material' => 'Premium Fabric',
                'description' => 'High-quality custom Riders_Long_Sleeves tailored to perfection.',
                'garment_type' => 'other',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_47->id, 'image_url' => '/catalog/Riders_Long_Sleeves.jpg'],
            ['view_angle' => 'front', 'is_primary' => true]
        );
        $item_48 = CatalogItem::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'VBALL_PRE-2001_800x800'],
            [
                'price' => 1500.0,
                'material' => 'Premium Fabric',
                'description' => 'High-quality custom VBALL_PRE-2001_800x800 tailored to perfection.',
                'garment_type' => 'other',
                'listing_type' => 'made_to_order',
                'features' => ['Premium Quality', 'SUTURA Guaranteed'],
                'care_instructions' => 'Handle with care.',
            ]
        );
        CatalogImage::firstOrCreate(
            ['catalog_item_id' => $item_48->id, 'image_url' => '/catalog/VBALL_PRE-2001_800x800.webp'],
            ['view_angle' => 'front', 'is_primary' => true]
        );

        // Populate realistic fabric_image_url for all catalog items based on garment type & material
        foreach (CatalogItem::whereNull('fabric_image_url')->get() as $item) {
            $name = strtolower($item->name);
            $gt = strtolower($item->garment_type ?? '');
            $mat = strtolower($item->material ?? '');

            $fabric = '/catalog/fabrics/peach_twill_fabric.jpg';

            if (str_contains($name, 'barong') || $gt === 'barong' || str_contains($mat, 'pina')) {
                $fabric = '/catalog/fabrics/pina_cocoon_fabric.jpg';
            } elseif (str_contains($name, 'green') || str_contains($name, 'greed') || str_contains($name, 'teal')) {
                $fabric = '/catalog/fabrics/emerald_lace_fabric.jpg';
            } elseif (str_contains($name, 'red') && (str_contains($name, 'satin') || str_contains($name, 'dress') || str_contains($name, 'gown'))) {
                $fabric = '/catalog/fabrics/crimson_satin_fabric.jpg';
            } elseif (str_contains($name, 'satin') || str_contains($name, 'pink') || str_contains($name, 'maid') || str_contains($name, 'bridesmaid')) {
                $fabric = '/catalog/fabrics/satin_silk_fabric.jpg';
            } elseif ($gt === 'gown' || str_contains($name, 'gown') || str_contains($name, 'wedding') || str_contains($name, 'tulle') || str_contains($mat, 'chiffon')) {
                $fabric = '/catalog/fabrics/bridal_chiffon_fabric.jpg';
            } elseif ($gt === 'suit' || str_contains($name, 'suit') || str_contains($name, 'tuxedo') || str_contains($mat, 'wool')) {
                $fabric = '/catalog/fabrics/wool_twill_fabric.jpg';
            } elseif (str_contains($name, 'rashguard') || str_contains($name, 'riders')) {
                $fabric = '/catalog/fabrics/compression_spandex_fabric.jpg';
            } elseif ($gt === 'uniform' || str_contains($name, 'jersey') || str_contains($name, 'esport') || str_contains($name, 'volleyball') || str_contains($name, 'basketball') || str_contains($mat, 'drifit')) {
                $fabric = '/catalog/fabrics/drifit_mesh_fabric.jpg';
            }

            $item->update(['fabric_image_url' => $fabric]);
        }
    }
}
