<?php

namespace Tests\Feature\Api\V1;

use App\Models\Measurement;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeasurementSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_measurement_source_defaults_to_store_owner_and_accepts_customer(): void
    {
        $role = Role::create(['name' => 'store_owner', 'description' => 'Store Owner']);
        $owner = User::factory()->create();
        $owner->roles()->attach($role);
        $store = Store::create([
            'owner_id' => $owner->id, 'name' => 'Test Store', 'slug' => 'test-store',
            'address' => '1 St', 'city' => 'Davao', 'province' => 'Davao del Sur', 'status' => 'approved',
        ]);
        $customer = User::factory()->create();

        // No source provided -> defaults to the tailor's own format.
        $r1 = $this->actingAs($owner)->postJson("/api/v1/stores/{$store->id}/measurements", [
            'customer_id' => $customer->id,
            'profile_name' => 'Owner Format',
            'metrics' => ['chest' => 40],
        ]);
        $r1->assertStatus(201);
        $this->assertEquals('store_owner', Measurement::find($r1->json('data.id'))->source);

        // Customer-encoded record keeps its source.
        $r2 = $this->actingAs($owner)->postJson("/api/v1/stores/{$store->id}/measurements", [
            'customer_id' => $customer->id,
            'source' => 'customer',
            'profile_name' => 'Customer Encoded',
            'metrics' => ['waist' => 32],
        ]);
        $r2->assertStatus(201);
        $this->assertEquals('customer', Measurement::find($r2->json('data.id'))->source);
    }
}
