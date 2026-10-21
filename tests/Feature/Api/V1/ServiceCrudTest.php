<?php

namespace Tests\Feature\Api\V1;

use App\Models\Role;
use App\Models\Service;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCrudTest extends TestCase
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
            'name' => 'Test Tailor Shop',
            'slug' => 'test-tailor-shop',
            'address' => '123 Tailor St',
            'city' => 'Davao City',
            'province' => 'Davao del Sur',
            'status' => 'approved',
            'is_hidden' => false,
        ]);
    }

    public function test_owner_can_create_service_with_all_fields(): void
    {
        $payload = [
            'name' => 'Custom Formal Barong',
            'description' => 'Fine piña barong tailoring',
            'service_category' => 'custom_tailoring',
            'garment_types' => ['barong_tagalog'],
            'capabilities' => ['made_to_measure'],
            'base_price' => 3500,
            'estimated_days' => 7,
            'estimated_days_max' => 14,
            'min_order_qty' => 1,
            'custom_fields' => [
                ['id' => 'c1', 'label' => 'Collar Style', 'type' => 'select', 'required' => true, 'options' => ['Classic', 'Mandarin']],
            ],
            'pricing_tiers' => [
                ['label' => 'Standard Piña', 'amount' => 3500],
                ['label' => 'Double Collar Add-on', 'amount' => 500],
            ],
            'measurement_requirement' => 'shop',
            'fitting_requirement' => 'required',
            'payment_policy' => 'deposit',
            'payment_policy_percent' => 50,
            'is_active' => true,
        ];

        $res = $this->actingAs($this->user)->postJson("/api/v1/stores/{$this->store->id}/services", $payload);

        $res->assertStatus(201);
        $res->assertJsonPath('success', true);
        $res->assertJsonPath('data.name', 'Custom Formal Barong');
        $serviceId = $res->json('data.id');

        $this->assertDatabaseHas('services', [
            'id' => $serviceId,
            'store_id' => $this->store->id,
            'name' => 'Custom Formal Barong',
            'measurement_requirement' => 'shop',
            'payment_policy' => 'deposit',
            'payment_policy_percent' => 50,
        ]);

        $this->assertDatabaseHas('service_pricing', [
            'service_id' => $serviceId,
            'label' => 'Standard Piña',
            'amount' => 3500.00,
        ]);
    }

    public function test_owner_can_view_single_service(): void
    {
        $service = Service::create([
            'store_id' => $this->store->id,
            'name' => 'Dress Alterations',
            'service_category' => 'alterations_repairs',
            'capabilities' => ['hemming_length_adjustment'],
            'is_active' => true,
            'base_price' => 200,
        ]);
        $service->pricing()->create(['label' => 'Hemming', 'amount' => 200]);

        $res = $this->actingAs($this->user)->getJson("/api/v1/stores/{$this->store->id}/services/{$service->id}");

        $res->assertStatus(200);
        $res->assertJsonPath('success', true);
        $res->assertJsonPath('data.id', $service->id);
        $res->assertJsonPath('data.pricing.0.label', 'Hemming');
    }

    public function test_updating_service_section_does_not_wipe_pricing_tiers(): void
    {
        $service = Service::create([
            'store_id' => $this->store->id,
            'name' => 'Basketball Uniforms',
            'service_category' => 'uniform_production',
            'capabilities' => ['bulk_orders'],
            'is_active' => true,
            'base_price' => 800,
            'tags' => ['Jersey Set', 'Shorts Only'],
        ]);
        $service->pricing()->create(['label' => 'Jersey Set', 'amount' => 800]);
        $service->pricing()->create(['label' => 'Shorts Only', 'amount' => 350]);

        // Partial update: changing description or name without sending pricing_tiers
        $updatePayload = [
            'name' => 'Basketball Uniforms (Updated Name)',
            'description' => 'Updated description text',
            'service_category' => 'uniform_production',
            'capabilities' => ['bulk_orders'],
            'base_price' => 800,
            'is_active' => true,
        ];

        $res = $this->actingAs($this->user)->putJson("/api/v1/stores/{$this->store->id}/services/{$service->id}", $updatePayload);

        $res->assertStatus(200);
        $res->assertJsonPath('data.name', 'Basketball Uniforms (Updated Name)');

        // Crucial regression check: pricing tiers must NOT have been wiped
        $this->assertDatabaseHas('service_pricing', [
            'service_id' => $service->id,
            'label' => 'Jersey Set',
            'amount' => 800.00,
        ]);
        $this->assertDatabaseHas('service_pricing', [
            'service_id' => $service->id,
            'label' => 'Shorts Only',
            'amount' => 350.00,
        ]);
    }

    public function test_owner_can_pause_and_delete_service(): void
    {
        $service = Service::create([
            'store_id' => $this->store->id,
            'name' => 'To Be Deleted',
            'service_category' => 'alterations_repairs',
            'is_active' => true,
        ]);

        // Pause
        $pauseRes = $this->actingAs($this->user)->putJson("/api/v1/stores/{$this->store->id}/services/{$service->id}", [
            'name' => 'To Be Deleted',
            'is_active' => false,
        ]);
        $pauseRes->assertStatus(200);
        $this->assertDatabaseHas('services', ['id' => $service->id, 'is_active' => false]);

        // Delete
        $delRes = $this->actingAs($this->user)->deleteJson("/api/v1/stores/{$this->store->id}/services/{$service->id}");
        $delRes->assertStatus(200);
        $this->assertSoftDeleted('services', ['id' => $service->id]);
    }

    public function test_bulk_service_saves_roster_fields_and_min_order_qty(): void
    {
        $payload = [
            'name' => 'High School Volley Uniforms',
            'service_category' => 'uniform_production',
            'capabilities' => ['bulk_orders'],
            'min_order_qty' => 12,
            'roster_fields' => [
                ['id' => 'r1', 'label' => 'Jersey Number', 'type' => 'number', 'required' => true],
                ['id' => 'r2', 'label' => 'Player Name', 'type' => 'text', 'required' => true],
                ['id' => 'r3', 'label' => 'Position', 'type' => 'text', 'required' => false],
            ],
            'size_chart_columns' => ['Chest', 'Length'],
            'size_chart_rows' => [
                ['size' => 'S', 'values' => ['36', '26']],
                ['size' => 'M', 'values' => ['38', '27']],
            ],
            'is_active' => true,
        ];

        $res = $this->actingAs($this->user)->postJson("/api/v1/stores/{$this->store->id}/services", $payload);
        $res->assertStatus(201);
        $id = $res->json('data.id');

        $service = Service::find($id);
        $this->assertEquals(12, $service->min_order_qty);
        $this->assertCount(3, $service->roster_fields);
        $this->assertEquals('Jersey Number', $service->roster_fields[0]['label']);
        $this->assertEquals(['Chest', 'Length'], $service->size_chart_columns);
        $this->assertCount(2, $service->size_chart_rows);
    }

    public function test_turnaround_depends_allows_null_estimated_days(): void
    {
        $payload = [
            'name' => 'Complex Bridal Gown Restoration',
            'service_category' => 'custom_tailoring',
            'estimated_days' => null,
            'estimated_days_max' => null,
            'base_price' => null,
            'is_active' => true,
        ];

        $res = $this->actingAs($this->user)->postJson("/api/v1/stores/{$this->store->id}/services", $payload);
        $res->assertStatus(201);
        $id = $res->json('data.id');

        $service = Service::find($id);
        $this->assertNull($service->estimated_days);
        $this->assertNull($service->estimated_days_max);
        $this->assertNull($service->base_price);
    }
}
