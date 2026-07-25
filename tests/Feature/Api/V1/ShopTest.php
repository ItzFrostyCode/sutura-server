<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Models\Shop;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'shop_owner', 'description' => 'Shop Owner']);
        $this->user = User::factory()->create();
        $this->user->roles()->attach($role);

        $this->shop = Shop::create([
            'owner_id' => $this->user->id,
            'name' => 'Test Shop',
            'slug' => 'test-shop',
            'address' => '123 Test St',
            'city' => 'Manila',
            'province' => 'Metro Manila',
            'status' => 'approved'
        ]);
    }

    public function test_owner_can_set_shop_specializations()
    {
        $response = $this->actingAs($this->user)->putJson("/api/v1/shops/{$this->shop->id}", [
            'specializations' => ['barong', 'gown'],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('shops', [
            'id' => $this->shop->id,
        ]);
        $this->shop->refresh();
        $this->assertEquals(['barong', 'gown'], $this->shop->specializations);
    }

    public function test_shop_specializations_rejects_an_invalid_value()
    {
        $response = $this->actingAs($this->user)->putJson("/api/v1/shops/{$this->shop->id}", [
            'specializations' => ['barong', 'not_a_real_garment_type'],
        ]);

        $response->assertStatus(422);
    }

    public function test_shop_without_a_qualifying_plan_cannot_turn_on_featured()
    {
        // No subscription at all — plan/features resolve to empty, so this
        // should be rejected the same way as a Basic/Pro shop would be.
        $response = $this->actingAs($this->user)->putJson("/api/v1/shops/{$this->shop->id}", [
            'is_featured' => true,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('shops', [
            'id' => $this->shop->id,
            'is_featured' => false,
        ]);
    }

    public function test_shop_on_premium_plan_can_turn_on_featured()
    {
        $plan = \App\Models\SubscriptionPlan::create([
            'name' => 'Premium',
            'slug' => 'premium-test',
            'price_monthly' => 1999,
            'price_yearly' => 19990,
            'max_staff' => -1,
            'max_services' => -1,
            'max_appointments_per_month' => -1,
            'features' => ['All Pro Plan Features', 'Featured Shop Visibility (Top Placement)'],
            'is_active' => true,
        ]);

        \App\Models\ShopSubscription::create([
            'shop_id' => $this->shop->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->user)->putJson("/api/v1/shops/{$this->shop->id}", [
            'is_featured' => true,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('shops', [
            'id' => $this->shop->id,
            'is_featured' => true,
        ]);
    }
}
