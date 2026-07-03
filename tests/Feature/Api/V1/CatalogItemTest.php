<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Models\Shop;
use App\Models\Role;
use App\Models\CatalogItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_item_stores_sale_rent_pricing_color_and_sizes(): void
    {
        $role = Role::create(['name' => 'shop_owner', 'description' => 'Shop Owner']);
        $user = User::factory()->create();
        $user->roles()->attach($role);
        $shop = Shop::create([
            'owner_id' => $user->id, 'name' => 'Test Shop', 'slug' => 'test-shop',
            'address' => '1 St', 'city' => 'Davao', 'province' => 'Davao del Sur', 'status' => 'approved',
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/shops/{$shop->id}/catalog", [
            'name' => 'Barong Tagalog',
            'listing_type' => 'rent_or_sale',
            'sale_price' => 2500,
            'rental_price' => 500,
            'rental_deposit' => 1000,
            'color' => 'Ivory',
            'sizes' => ['S', 'M', 'L'],
        ]);

        $response->assertStatus(201);
        $item = CatalogItem::find($response->json('data.id'));

        $this->assertEquals('Ivory', $item->color);
        $this->assertEquals(['S', 'M', 'L'], $item->sizes);
        $this->assertEquals(2500.0, (float) $item->sale_price);
        $this->assertEquals(500.0, (float) $item->rental_price);
        $this->assertEquals(1000.0, (float) $item->rental_deposit);
        // Base price falls back to sale_price when not explicitly provided.
        $this->assertEquals(2500.0, (float) $item->price);
    }
}
