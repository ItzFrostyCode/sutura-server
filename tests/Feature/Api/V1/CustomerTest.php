<?php

namespace Tests\Feature\Api\V1;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTest extends TestCase
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
            'city' => 'Davao',
            'province' => 'Davao del Sur',
            'status' => 'approved',
        ]);
    }

    public function test_customer_notes_are_saved_on_creation_and_scoped_to_the_store()
    {
        $response = $this->actingAs($this->user)->postJson("/api/v1/stores/{$this->store->id}/customers", [
            'name' => 'Maria Santos',
            'phone' => '09171234567',
            'notes' => 'Allergic to synthetic fabric — cotton/linen only.',
        ]);

        $response->assertStatus(201);
        $customerId = $response->json('data.id');

        $this->assertDatabaseHas('store_customers', [
            'store_id' => $this->store->id,
            'user_id' => $customerId,
            'notes' => 'Allergic to synthetic fabric — cotton/linen only.',
        ]);

        // Notes must NOT leak onto the User row itself — they're store-specific.
        $customer = User::find($customerId);
        $this->assertArrayNotHasKey('notes', $customer->getAttributes());
    }

    public function test_updating_a_customer_without_the_notes_field_preserves_the_existing_note()
    {
        $create = $this->actingAs($this->user)->postJson("/api/v1/stores/{$this->store->id}/customers", [
            'name' => 'Juan Dela Cruz',
            'phone' => '09179876543',
            'notes' => 'Prefers a slim fit.',
        ]);
        $customerId = $create->json('data.id');

        $this->actingAs($this->user)->putJson("/api/v1/stores/{$this->store->id}/customers/{$customerId}", [
            'name' => 'Juan Dela Cruz',
            'phone' => '09179876543',
            // notes omitted entirely
        ])->assertStatus(200);

        $this->assertDatabaseHas('store_customers', [
            'store_id' => $this->store->id,
            'user_id' => $customerId,
            'notes' => 'Prefers a slim fit.',
        ]);
    }

    public function test_customer_index_surfaces_store_notes_per_customer()
    {
        $create = $this->actingAs($this->user)->postJson("/api/v1/stores/{$this->store->id}/customers", [
            'name' => 'Ana Reyes',
            'phone' => '09170001111',
            'notes' => 'Repeat suki, always pays in full upfront.',
        ]);
        $customerId = $create->json('data.id');

        $response = $this->actingAs($this->user)->getJson("/api/v1/stores/{$this->store->id}/customers");
        $response->assertStatus(200);

        $customerRow = collect($response->json('data'))->firstWhere('id', $customerId);
        $this->assertEquals('Repeat suki, always pays in full upfront.', $customerRow['store_notes']);
    }
}
