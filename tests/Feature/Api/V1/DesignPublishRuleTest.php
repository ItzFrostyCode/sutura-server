<?php

namespace Tests\Feature\Api\V1;

use App\Models\CatalogItem;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A design shown to customers needs a photo (reference-repos/Services/11 section 2, "Designs"). */
class DesignPublishRuleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->owner->roles()->attach(Role::create(['name' => 'store_owner', 'description' => 'Store Owner']));
        $this->store = Store::create(['owner_id' => $this->owner->id, 'name' => 'Design Shop', 'slug' => 'design-shop', 'address' => '1 St',
            'city' => 'Davao City', 'province' => 'Davao del Sur', 'status' => 'approved', 'is_hidden' => false]);
    }

    private function url(string $p = ''): string
    {
        return "/api/v1/stores/{$this->store->id}/catalog{$p}";
    }

    public function test_a_design_created_without_a_photo_is_saved_but_not_shown(): void
    {
        $r = $this->actingAs($this->owner)->postJson($this->url(), ['name' => 'Ivory Barong', 'is_active' => true])->assertCreated();
        $this->assertNotNull($r->json('notice'));
        $this->assertFalse(CatalogItem::find($r->json('data.id'))->is_active);
    }

    public function test_a_design_with_a_photo_is_shown_and_showing_one_without_is_refused(): void
    {
        $img = [['url' => '/catalog/x.jpg', 'angle' => 'front', 'is_primary' => true]];
        $this->actingAs($this->owner)->postJson($this->url(), ['name' => 'With Photo', 'is_active' => true, 'images' => $img])
            ->assertCreated()->assertJsonPath('data.is_active', true)->assertJsonPath('notice', null);

        $id = $this->postJson($this->url(), ['name' => 'No Photo'])->json('data.id');
        $this->putJson($this->url("/$id"), ['name' => 'No Photo', 'is_active' => true])->assertStatus(422)->assertJsonValidationErrors(['images']);
        $this->putJson($this->url("/$id"), ['name' => 'No Photo', 'is_active' => true, 'images' => $img])->assertOk()->assertJsonPath('data.is_active', true);
    }
}
