<?php

namespace Tests\Feature\Api\V1;

use App\Models\Role;
use App\Models\Store;
use App\Models\StoreSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'store_owner', 'description' => 'Store Owner']);
        $this->user = User::factory()->create();
        $this->user->roles()->attach($role);

        $this->store = Store::create([
            'owner_id' => $this->user->id,
            'name' => 'Test Store',
            'slug' => 'test-store',
            'address' => '123 Test St',
            'city' => 'Manila',
            'province' => 'Metro Manila',
            'status' => 'approved',
        ]);
    }

    public function test_owner_can_set_store_specializations()
    {
        $response = $this->actingAs($this->user)->putJson("/api/v1/stores/{$this->store->id}", [
            'specializations' => ['custom_tailoring', 'formal'],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('stores', [
            'id' => $this->store->id,
        ]);
        $this->store->refresh();
        $this->assertEquals(['custom_tailoring', 'formal'], $this->store->specializations);
    }

    public function test_store_specializations_rejects_an_invalid_value()
    {
        $response = $this->actingAs($this->user)->putJson("/api/v1/stores/{$this->store->id}", [
            'specializations' => ['barong', 'not_a_real_garment_type'],
        ]);

        $response->assertStatus(422);
    }

    public function test_store_without_a_qualifying_plan_cannot_turn_on_featured()
    {
        // No subscription at all — plan/features resolve to empty. The owner can no longer flip is_featured on
        // their own (the field is not owner-writable; the system keeps it in sync with a Premium plan), so the
        // update simply leaves the store un-featured.
        $response = $this->actingAs($this->user)->putJson("/api/v1/stores/{$this->store->id}", [
            'is_featured' => true,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('stores', [
            'id' => $this->store->id,
            'is_featured' => false,
        ]);
    }

    public function test_store_on_premium_plan_can_turn_on_featured()
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Premium',
            'slug' => 'premium-test',
            'price_monthly' => 1999,
            'price_yearly' => 19990,
            'max_staff' => -1,
            'max_services' => -1,
            'max_appointments_per_month' => -1,
            'features' => ['All Pro Plan Features', 'Featured Store Visibility (Top Placement)'],
            'is_active' => true,
        ]);

        // is_featured is a system-managed Premium perk, not an owner toggle: subscribing to a plan that carries the
        // "Featured Store Visibility" feature switches it on via the subscription switcher (the owner's own PUT no
        // longer sets the column).
        \App\Support\SubscriptionSwitcher::apply($this->store, $plan, 'monthly', $this->user->id);

        $this->assertTrue((bool) $this->store->fresh()->is_featured);
    }
}
