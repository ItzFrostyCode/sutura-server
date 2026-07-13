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

    public function test_catalog_item_stores_price_estimated_days_color_and_sizes(): void
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
            'price' => 2500,
            'estimated_days' => 10,
            'color' => 'Ivory',
            'sizes' => ['S', 'M', 'L'],
        ]);

        $response->assertStatus(201);
        $item = CatalogItem::find($response->json('data.id'));

        $this->assertEquals('Ivory', $item->color);
        $this->assertEquals(['S', 'M', 'L'], $item->sizes);
        $this->assertEquals(2500.0, (float) $item->price);
        $this->assertEquals(10, $item->estimated_days);
        // Made-to-order only — the approved thesis excludes ready-to-wear/rental listings.
        $this->assertEquals('made_to_order', $item->listing_type);
    }
}
