<?php

namespace Tests\Feature\Api\V1;

use App\Models\Appointment;
use App\Models\Role;
use App\Models\Service;
use App\Models\Store;
use App\Models\User;
use App\Notifications\AppointmentStatusNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected User $customer;

    protected Service $service;

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

        $this->customer = User::factory()->create();
        $this->customer->roles()->attach($role);

        $this->service = Service::create([
            'store_id' => $this->store->id,
            'name' => 'Bespoke Suit',
            'base_duration_days' => 14,
        ]);
    }

    public function test_fitting_notes_can_be_set_via_general_update()
    {
        $appointment = Appointment::create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'appointment_type' => 'consultation',
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 30,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)->putJson(
            "/api/v1/stores/{$this->store->id}/appointments/{$appointment->id}",
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
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'appointment_type' => 'consultation',
            'scheduled_at' => now()->subHour(),
            'duration_minutes' => 30,
            'status' => 'in_progress',
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/stores/{$this->store->id}/appointments/{$appointment->id}/complete",
            ['outcome' => 'completed', 'fitting_notes' => 'Customer wants a looser collar.']
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'fitting_notes' => 'Customer wants a looser collar.',
        ]);
    }

    public function test_online_booking_rejects_slot_if_another_pending_booking_exists()
    {
        $slotTime = now()->addDays(2)->setTime(14, 0, 0);

        // First customer books online -> creates pending appointment
        Appointment::create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'appointment_type' => 'consultation',
            'intake_channel' => 'online',
            'scheduled_at' => $slotTime,
            'duration_minutes' => 30,
            'status' => 'pending',
        ]);

        // Second online customer attempts to book the exact same slot
        $response = $this->postJson("/api/v1/catalog/{$this->store->slug}/book", [
            'name' => 'Second Customer',
            'email' => 'second@example.com',
            'phone' => '09123456789',
            'appointment_type' => 'consultation',
            'scheduled_at' => $slotTime->format('Y-m-d H:i:s'),
            'duration_minutes' => 30,
        ]);

        $response->assertStatus(409);
        $response->assertJson([
            'success' => false,
            'message' => 'This time slot is already reserved or currently requested. Please choose a different time.',
        ]);
    }

    public function test_walkin_appointment_takes_precedence_over_pending_online_booking_and_preempts_it()
    {
        Notification::fake();

        $slotTime = now()->addDays(2)->setTime(15, 0, 0);

        // Customer booked online -> pending
        $pendingAppointment = Appointment::create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'appointment_type' => 'consultation',
            'intake_channel' => 'online',
            'scheduled_at' => $slotTime,
            'duration_minutes' => 30,
            'status' => 'pending',
        ]);

        // Walk-in client arrives at the counter ("source of truth is ang walkin kung sino ang makauna")
        $walkInCustomer = User::factory()->create();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/stores/{$this->store->id}/appointments",
            [
                'customer_id' => $walkInCustomer->id,
                'appointment_type' => 'consultation',
                'scheduled_at' => $slotTime->format('Y-m-d H:i:s'),
                'duration_minutes' => 30,
            ]
        );

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'preempted_count' => 1,
        ]);

        // Verify walk-in is confirmed immediately
        $this->assertDatabaseHas('appointments', [
            'customer_id' => $walkInCustomer->id,
            'intake_channel' => 'walk_in',
            'status' => 'confirmed',
        ]);

        // Verify pending appointment was preempted and flagged with Walk-in Priority
        $pendingAppointment->refresh();
        $this->assertEquals('rescheduled', $pendingAppointment->outcome);
        $this->assertStringContainsString('[Walk-in Priority]', $pendingAppointment->notes);

        // Verify audit log
        $this->assertDatabaseHas('audit_logs', [
            'store_id' => $this->store->id,
            'action' => 'appointment_preempted_by_walk_in',
            'model_type' => Appointment::class,
            'model_id' => $pendingAppointment->id,
        ]);

        // Verify notification sent to online customer
        Notification::assertSentTo(
            $this->customer,
            AppointmentStatusNotification::class
        );
    }

    public function test_walkin_appointment_is_blocked_if_slot_is_already_confirmed()
    {
        $slotTime = now()->addDays(2)->setTime(16, 0, 0);

        // An already confirmed appointment exists on that slot
        Appointment::create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'appointment_type' => 'consultation',
            'intake_channel' => 'online',
            'scheduled_at' => $slotTime,
            'duration_minutes' => 30,
            'status' => 'confirmed',
        ]);

        $walkInCustomer = User::factory()->create();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/stores/{$this->store->id}/appointments",
            [
                'customer_id' => $walkInCustomer->id,
                'appointment_type' => 'consultation',
                'scheduled_at' => $slotTime->format('Y-m-d H:i:s'),
                'duration_minutes' => 30,
            ]
        );

        $response->assertStatus(409);
        $response->assertJson([
            'success' => false,
            'message' => 'This time slot is already booked by another confirmed appointment. Please choose a different time.',
        ]);
    }
}
