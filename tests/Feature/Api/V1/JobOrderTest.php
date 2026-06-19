<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Models\Shop;
use App\Models\Service;
use App\Models\Measurement;
use App\Models\StaffProfile;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobOrderTest extends TestCase
{
    use RefreshDatabase;

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
        
        $this->customer = User::factory()->create();
        $this->customer->roles()->attach($role);
        
        $this->service = Service::create([
            'shop_id' => $this->shop->id,
            'name' => 'Bespoke Suit',
            'base_duration_days' => 14
        ]);
        
        $this->staffProfile = StaffProfile::create([
            'shop_id' => $this->shop->id,
            'user_id' => $this->user->id,
            'role' => 'tailor'
        ]);

        $this->measurement = Measurement::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'profile_name' => 'Default',
            'metrics' => ['chest' => 40]
        ]);
    }

    public function test_can_create_job_order()
    {
        $response = $this->actingAs($this->user)->postJson("/api/v1/shops/{$this->shop->id}/jobs", [
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'assigned_staff_id' => $this->staffProfile->id,
            'measurement_id' => $this->measurement->id,
            'total_amount' => 5000,
            'balance' => 2500,
            'status' => 'pending'
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('success', true);

        $this->assertDatabaseHas('job_orders', [
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'total_amount' => 5000,
        ]);
    }
}
