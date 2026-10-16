<?php

namespace Tests\Feature\Api\V1;

use App\Models\Appointment;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Only the shop marks "Customer arrived". The customer, online or walk-in, tells the shop and the shop taps it. */
class ArrivalAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_cannot_mark_their_own_arrival_but_the_shop_can(): void
    {
        $ownerRole = Role::create(['name' => 'store_owner', 'description' => 'Owner']);
        $customerRole = Role::create(['name' => 'customer', 'description' => 'Customer']);
        $owner = User::factory()->create();
        $owner->roles()->attach($ownerRole);
        $store = Store::create([
            'owner_id' => $owner->id, 'name' => 'Test Store', 'slug' => 'test-store',
            'address' => '1 Test St', 'city' => 'Davao', 'province' => 'Davao del Sur', 'status' => 'approved',
        ]);
        $customer = User::factory()->create();
        $customer->roles()->attach($customerRole);

        $appointment = Appointment::create([
            'store_id' => $store->id, 'customer_id' => $customer->id, 'kind' => 'shop', 'appointment_type' => 'consultation',
            'intake_channel' => 'online', 'scheduled_at' => now(), 'duration_minutes' => 30, 'status' => 'confirmed',
        ]);
        $url = "/api/v1/stores/{$store->id}/appointments/{$appointment->id}/arrive";

        $this->actingAs($customer)->postJson($url)->assertStatus(403);
        $this->assertNull($appointment->fresh()->checked_in_at);

        $this->actingAs($owner)->postJson($url)->assertOk();
        $this->assertNotNull($appointment->fresh()->checked_in_at);
    }
}
