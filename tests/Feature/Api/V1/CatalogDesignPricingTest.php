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

}
