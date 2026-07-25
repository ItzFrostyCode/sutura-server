<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Models\Shop;
use App\Models\Service;
use App\Models\Appointment;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Shop $shop;
    protected User $customer;
    protected Service $service;

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
    }

    public function test_fitting_notes_can_be_set_via_general_update()
    {
        $appointment = Appointment::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'appointment_type' => 'consultation',
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 30,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)->putJson(
            "/api/v1/shops/{$this->shop->id}/appointments/{$appointment->id}",
            ['fitting_notes' => 'Take in the waist, shorten sleeves by 1 inch.']
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'fitting_notes' => 'Take in the waist, shorten sleeves by 1 inch.',
        ]);
    }

    public function test_fitting_notes_can_be_set_when_completing_an_appointment()
    {
        $appointment = Appointment::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'appointment_type' => 'consultation',
            'scheduled_at' => now()->subHour(),
            'duration_minutes' => 30,
            'status' => 'in_progress',
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/shops/{$this->shop->id}/appointments/{$appointment->id}/complete",
            ['outcome' => 'completed', 'fitting_notes' => 'Customer wants a looser collar.']
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'fitting_notes' => 'Customer wants a looser collar.',
        ]);
    }
}
