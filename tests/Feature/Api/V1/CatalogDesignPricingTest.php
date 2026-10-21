<?php

namespace Tests\Feature\Api\V1;

use App\Models\CatalogItem;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServicePricing;
use App\Models\Store;
use App\Models\User;
use App\Support\DesignPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogDesignPricingTest extends TestCase
{
    use RefreshDatabase;

    private function createStore(): Store
    {
        $role = Role::firstOrCreate(['name' => 'store_owner'], ['description' => 'Store Owner']);
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return Store::create([
            'owner_id' => $user->id,
            'name' => 'Test Store',
            'slug' => 'test-store-'.uniqid(),
            'address' => '123 Main St',
            'city' => 'Davao',
            'province' => 'Davao del Sur',
            'status' => 'approved',
        ]);
    }

    public function test_pricing_modes_and_addons_and_size_extras(): void
    {
        $store = $this->createStore();

        $service = Service::create([
            'store_id' => $store->id,
            'name' => 'Custom Tailoring Service',
            'base_price' => 500.00,
        ]);
        ServicePricing::create([
            'service_id' => $service->id,
            'label' => 'Service Logo Embroidery',
            'amount' => 80.00,
        ]);

        // Mode: SAME
        $sameItem = CatalogItem::create([
            'store_id' => $store->id,
            'service_id' => $service->id,
            'name' => 'Same Design',
            'pricing_mode' => 'same',
            'price' => 0,
            'sizes' => ['S', 'M', '2XL (+₱50.00)'],
            'add_ons' => [
                ['name' => 'Chest Pocket', 'price' => 40.00],
            ],
        ]);
        $pricingSame = DesignPricing::resolve($sameItem, $service);
        $this->assertEquals(500.00, $pricingSame['amount']);
        $this->assertEquals('same', $pricingSame['mode']);

        // Mode: SET
        $setItem = CatalogItem::create([
            'store_id' => $store->id,
            'service_id' => $service->id,
            'name' => 'Set Design',
            'pricing_mode' => 'set',
            'price' => 750.00,
        ]);
        $pricingSet = DesignPricing::resolve($setItem, $service);
        $this->assertEquals(750.00, $pricingSet['amount']);
        $this->assertEquals('set', $pricingSet['mode']);

        // Mode: EXTRA
        $extraItem = CatalogItem::create([
            'store_id' => $store->id,
            'service_id' => $service->id,
            'name' => 'Extra Design',
            'pricing_mode' => 'extra',
            'price' => 0,
            'extra_charge' => 150.00,
        ]);
        $pricingExtra = DesignPricing::resolve($extraItem, $service);
        $this->assertEquals(650.00, $pricingExtra['amount']);
        $this->assertEquals('extra', $pricingExtra['mode']);

        // Mode: QUOTE
        $quoteItem = CatalogItem::create([
            'store_id' => $store->id,
            'service_id' => $service->id,
            'name' => 'Quote Design',
            'pricing_mode' => 'quote',
            'price' => 0,
        ]);
        $pricingQuote = DesignPricing::resolve($quoteItem, $service);
        $this->assertNull($pricingQuote['amount']);
        $this->assertTrue($pricingQuote['needs_quote']);

        // Size Extra Parsing
        $this->assertEquals(50.00, DesignPricing::parseSizeExtra('2XL (+₱50.00)'));
        $this->assertEquals(120.50, DesignPricing::parseSizeExtra('3XL (+₱120.50)'));
        $this->assertEquals(0.00, DesignPricing::parseSizeExtra('Medium'));

        // Add-ons resolution with safeguards against duplicate double charges
        $resolved = DesignPricing::resolveAddOns($sameItem, $service->fresh(['pricing']), [
            'Chest Pocket',
            'Service Logo Embroidery',
            'chest pocket', // duplicate: should be ignored
        ]);
        $this->assertCount(2, $resolved['items']);
        $this->assertEquals(120.00, $resolved['total']);
        $this->assertEquals('design', $resolved['items'][0]['level']);
        $this->assertEquals('service', $resolved['items'][1]['level']);
    }

    public function test_customer_made_to_order_with_size_extra_and_addons(): void
    {
        $customer = User::factory()->create();
        $store = $this->createStore();
        $service = Service::create([
            'store_id' => $store->id,
            'name' => 'Bespoke Tailoring',
            'base_price' => 400.00,
            'service_types' => ['custom_tailoring'],
        ]);
        ServicePricing::create([
            'service_id' => $service->id,
            'label' => 'Standard Patch',
            'amount' => 50.00,
        ]);

        $item = CatalogItem::create([
            'store_id' => $store->id,
            'service_id' => $service->id,
            'name' => 'Custom Polo',
            'pricing_mode' => 'same',
            'price' => 0,
            'sizes' => ['M', '2XL (+₱60.00)'],
            'add_ons' => [
                ['name' => 'Metallic Buttons', 'price' => 100.00],
            ],
        ]);

        $response = $this->actingAs($customer)->postJson("/api/v1/stores/{$store->slug}/made-to-order", [
            'catalog_item_id' => $item->id,
            'size' => '2XL (+₱60.00)',
            'selected_add_ons' => ['Metallic Buttons', 'Standard Patch'],
        ]);

        $response->assertCreated();
        $data = $response->json('data');

        // Base 400 + size extra 60 + add-ons (100 + 50) = 610.00
        $this->assertEquals(610.00, (float) $data['total_amount']);
        $this->assertEquals(610.00, (float) $data['balance']);

        // Check pre-populated quotation snapshot
        $this->assertNotNull($data['quotation']);
        $this->assertEquals(400.00, (float) $data['quotation']['base_price']);
        $this->assertCount(3, $data['quotation']['additional_charges']);

        // Check custom_order_data pricing snapshot
        $snapshot = $data['custom_order_data']['pricing_snapshot'];
        $this->assertEquals(400.00, $snapshot['base_unit_price']);
        $this->assertEquals(60.00, $snapshot['size_extra']);
        $this->assertEquals(150.00, $snapshot['add_ons_total']);
        $this->assertEquals(610.00, $snapshot['total']);
    }

    public function test_customer_bulk_order_with_roster_size_extras_and_addons(): void
    {
        $customer = User::factory()->create();
        $store = $this->createStore();
        $service = Service::create([
            'store_id' => $store->id,
            'name' => 'Sublimation Uniforms',
            'base_price' => 300.00,
            'service_types' => ['bulk_sublimation'],
            'min_order_qty' => 3,
        ]);

        $item = CatalogItem::create([
            'store_id' => $store->id,
            'service_id' => $service->id,
            'name' => 'Team Jersey',
            'pricing_mode' => 'same',
            'price' => 0,
            'sizes' => ['S', 'M', 'L', '2XL (+₱50.00)', '3XL (+₱100.00)'],
            'add_ons' => [
                ['name' => 'Player Nickname Print', 'price' => 40.00],
            ],
        ]);

        $response = $this->actingAs($customer)->postJson("/api/v1/stores/{$store->slug}/bulk-orders", [
            'catalog_item_id' => $item->id,
            'organization_name' => 'Barangay Warriors',
            'roster' => [
                ['name' => 'Player 1', 'size' => 'M'],
                ['name' => 'Player 2', 'size' => 'M'],
                ['name' => 'Player 3', 'size' => 'L'],
                ['name' => 'Player 4', 'size' => '2XL (+₱50.00)'],
                ['name' => 'Player 5', 'size' => '3XL (+₱100.00)'],
            ],
            'selected_add_ons' => ['Player Nickname Print'],
        ]);

        $response->assertCreated();
        $data = $response->json('data');

        // 5 pcs * 300 base = 1500
        // size extras: 50 + 100 = 150
        // add-ons: 5 pcs * 40 = 200
        // Total = 1850.00
        $this->assertEquals(1850.00, (float) $data['total_amount']);
        $this->assertEquals(1850.00, (float) $data['balance']);

        // Quotation additional charges
        $this->assertEquals(1500.00, (float) $data['quotation']['base_price']);
        $this->assertCount(2, $data['quotation']['additional_charges']);
        $this->assertEquals(150.00, (float) $data['quotation']['additional_charges'][0]['amount']);
        $this->assertEquals(200.00, (float) $data['quotation']['additional_charges'][1]['amount']);

        // Custom order data snapshot
        $snapshot = $data['custom_order_data']['pricing_snapshot'];
        $this->assertEquals(300.00, $snapshot['base_unit_price']);
        $this->assertEquals(5, $snapshot['quantity']);
        $this->assertEquals(1500.00, $snapshot['base_total']);
        $this->assertEquals(150.00, $snapshot['size_surcharges_total']);
        $this->assertEquals(200.00, $snapshot['add_ons_total']);
        $this->assertEquals(1850.00, $snapshot['total']);
    }
}
