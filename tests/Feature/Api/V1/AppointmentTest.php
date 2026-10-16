<?php

namespace Tests\Feature\Api\V1;

use App\Models\Appointment;
use App\Models\Role;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\Store;
use App\Models\StoreBranch;
use App\Models\User;
use App\Notifications\AppointmentStatusNotification;
use App\Notifications\Channels\SmsChannel;
use App\Services\Sms\SmsTemplates;
use Carbon\Carbon;
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

    protected StoreBranch $branch;

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

        $this->branch = StoreBranch::create([
            'store_id' => $this->store->id, 'name' => 'Main', 'address' => 'A St', 'city' => 'Davao',
            'status' => 'active', 'verification_status' => 'verified',
        ]);

        $this->customer = User::factory()->create();
        $this->customer->roles()->attach(Role::create(['name' => 'customer', 'description' => 'Customer']));

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

    private function onlinePayload(array $over = []): array
    {
        return array_merge([
            'kind' => 'shop',
            'appointment_type' => 'consultation',
            'scheduled_at' => now()->addDays(3)->setTime(10, 0)->format('Y-m-d H:i:s'),
        ], $over);
    }

    private function walkInPayload(User $who, array $over = []): array
    {
        return array_merge([
            'customer_id' => $who->id,
            'kind' => 'shop',
            'appointment_type' => 'consultation',
            'scheduled_at' => now()->addDays(2)->setTime(15, 0)->format('Y-m-d H:i:s'),
        ], $over);
    }

    private function pendingAt(Carbon $at, array $over = []): Appointment
    {
        return Appointment::create(array_merge([
            'store_id' => $this->store->id, 'customer_id' => $this->customer->id, 'appointment_type' => 'consultation',
            'intake_channel' => 'online', 'scheduled_at' => $at, 'duration_minutes' => 30, 'status' => 'pending',
        ], $over));
    }

    private function staffUser(string $roleName, ?int $branchId, bool $manager = false): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => $roleName], ['description' => $roleName]));
        StaffProfile::create(['store_id' => $this->store->id, 'user_id' => $user->id, 'store_branch_id' => $branchId, 'role' => 'tailor', 'is_branch_manager' => $manager]);

        return $user;
    }

    public function test_kind_is_backfilled_style_on_create()
    {
        $service = $this->pendingAt(now()->addDays(5), ['service_id' => $this->service->id]);
        $shop = $this->pendingAt(now()->addDays(6));
        $fitting = $this->pendingAt(now()->addDays(7), ['appointment_type' => 'fitting']);

        $this->assertSame('service', $service->fresh()->kind);
        $this->assertSame('shop', $shop->fresh()->kind);
        $this->assertSame('production', $fitting->fresh()->kind);
    }

    public function test_online_booking_requires_a_signed_in_customer()
    {
        $this->postJson("/api/v1/catalog/{$this->store->slug}/book", $this->onlinePayload())->assertStatus(401);
    }

    public function test_online_booking_uses_the_account_takes_no_payment_and_uses_the_server_duration()
    {
        $res = $this->actingAs($this->customer)->postJson("/api/v1/catalog/{$this->store->slug}/book", $this->onlinePayload([
            'appointment_type' => 'measurement',
            'duration_minutes' => 15,
            'name' => 'Someone Else', 'email' => 'other@example.com',
            'payment_method' => 'gcash', 'payment_reference' => 'X1', 'payment_receipt_path' => 'r.png',
        ]));

        $res->assertStatus(201);
        $a = Appointment::latest('id')->first();
        $this->assertSame($this->customer->id, $a->customer_id);
        $this->assertSame('pending', $a->status);
        $this->assertSame('online', $a->intake_channel);
        $this->assertSame('shop', $a->kind);
        $this->assertSame(Appointment::TYPE_DEFAULT_DURATIONS['measurement'], $a->duration_minutes);
        $this->assertNull($a->payment_method);
        $this->assertNull($a->payment_receipt_path);
        $this->assertDatabaseMissing('users', ['email' => 'other@example.com']);
    }

    public function test_fitting_and_pickup_cannot_be_booked_online()
    {
        foreach (['fitting', 'pickup'] as $type) {
            $this->actingAs($this->customer)->postJson("/api/v1/catalog/{$this->store->slug}/book", $this->onlinePayload(['appointment_type' => $type]))
                ->assertStatus(422);
        }
        $this->assertSame(0, Appointment::count());
    }

    public function test_service_visit_needs_a_service_and_follows_it()
    {
        $this->actingAs($this->customer)->postJson("/api/v1/catalog/{$this->store->slug}/book", $this->onlinePayload(['kind' => 'service']))
            ->assertStatus(422);

        $alter = Service::create(['store_id' => $this->store->id, 'name' => 'Hemming', 'base_duration_days' => 1, 'service_category' => 'alterations_repairs']);
        $this->actingAs($this->customer)->postJson("/api/v1/catalog/{$this->store->slug}/book", $this->onlinePayload(['kind' => 'service', 'service_id' => $alter->id, 'appointment_type' => 'consultation']))
            ->assertStatus(201);

        $a = Appointment::latest('id')->first();
        $this->assertSame('service', $a->kind);
        $this->assertSame('alteration', $a->appointment_type);
    }

    public function test_online_booking_rejects_slot_if_another_pending_booking_exists()
    {
        $slot = now()->addDays(2)->setTime(14, 0, 0);
        $this->pendingAt($slot);
        $second = User::factory()->create();

        $this->actingAs($second)->postJson("/api/v1/catalog/{$this->store->slug}/book", $this->onlinePayload(['scheduled_at' => $slot->format('Y-m-d H:i:s')]))
            ->assertStatus(409)
            ->assertJson(['success' => false, 'message' => 'This time slot is already reserved or currently requested. Please choose a different time.']);
    }

    public function test_pending_production_placeholder_does_not_block_online_customers()
    {
        $slot = now()->addDays(2)->setTime(10, 0, 0);
        $this->pendingAt($slot, ['appointment_type' => 'fitting', 'kind' => 'production', 'duration_minutes' => 60]);

        $this->actingAs(User::factory()->create())->postJson("/api/v1/catalog/{$this->store->slug}/book", $this->onlinePayload(['scheduled_at' => $slot->format('Y-m-d H:i:s')]))
            ->assertStatus(201);
    }

    public function test_walkin_not_allowed_to_be_fitting_or_pickup()
    {
        $who = User::factory()->create();
        foreach (['fitting', 'pickup'] as $type) {
            $this->actingAs($this->user)->postJson("/api/v1/stores/{$this->store->id}/appointments", $this->walkInPayload($who, ['appointment_type' => $type]))
                ->assertStatus(422);
        }
    }

    public function test_walkin_clash_with_pending_online_request_is_reported_and_owner_chooses()
    {
        Notification::fake();
        $slot = now()->addDays(2)->setTime(15, 0, 0);
        $pending = $this->pendingAt($slot);
        $who = User::factory()->create();
        $url = "/api/v1/stores/{$this->store->id}/appointments";

        // No choice yet: the clash is reported and nothing is saved or moved.
        $this->actingAs($this->user)->postJson($url, $this->walkInPayload($who))
            ->assertStatus(409)
            ->assertJsonPath('code', 'pending_online_request')
            ->assertJsonPath('conflicts.0.id', $pending->id);
        $this->assertSame(1, Appointment::count());

        // Give the walk-in another time: still nothing saved.
        $this->actingAs($this->user)->postJson($url, $this->walkInPayload($who, ['on_conflict' => 'other_time']))
            ->assertStatus(409)->assertJsonPath('code', 'other_time');
        $this->assertSame(1, Appointment::count());

        // Serve the walk-in, ask the online customer to move.
        $this->actingAs($this->user)->postJson($url, $this->walkInPayload($who, ['on_conflict' => 'serve_walkin_reschedule_online']))
            ->assertStatus(201)->assertJson(['preempted_count' => 1]);

        $this->assertDatabaseHas('appointments', ['customer_id' => $who->id, 'intake_channel' => 'walk_in', 'status' => 'confirmed', 'kind' => 'shop']);
        $this->assertSame('rescheduled', $pending->fresh()->outcome);
        $this->assertDatabaseHas('audit_logs', ['action' => 'appointment_preempted_by_walk_in', 'model_id' => $pending->id]);
        Notification::assertSentTo($this->customer, AppointmentStatusNotification::class);
    }

    public function test_walkin_is_never_placed_over_a_confirmed_booking_even_when_owner_insists()
    {
        $slot = now()->addDays(2)->setTime(16, 0, 0);
        $this->pendingAt($slot, ['status' => 'confirmed']);
        $who = User::factory()->create();

        $this->actingAs($this->user)->postJson("/api/v1/stores/{$this->store->id}/appointments", $this->walkInPayload($who, ['scheduled_at' => $slot->format('Y-m-d H:i:s'), 'on_conflict' => 'serve_walkin_reschedule_online']))
            ->assertStatus(409)
            ->assertJson(['success' => false, 'message' => 'This time slot is already booked by another confirmed appointment. Please choose a different time.']);
    }

    public function test_walkin_uses_one_duration_table_and_a_service_visit_follows_its_service()
    {
        $who = User::factory()->create();
        $res = $this->actingAs($this->user)->postJson("/api/v1/stores/{$this->store->id}/appointments", $this->walkInPayload($who, ['kind' => 'service', 'service_id' => $this->service->id, 'appointment_type' => 'measurement']));
        $res->assertStatus(201);
        $a = Appointment::latest('id')->first();
        $this->assertSame('service', $a->kind);
        $this->assertSame('measurement', $a->appointment_type);
        $this->assertSame(Appointment::TYPE_DEFAULT_DURATIONS['measurement'], $a->duration_minutes);

        $this->actingAs($this->user)->postJson("/api/v1/stores/{$this->store->id}/appointments", $this->walkInPayload($who, ['kind' => 'service']))
            ->assertStatus(422);
    }

    public function test_branch_manager_walkins_stay_in_their_own_branch()
    {
        $other = StoreBranch::create(['store_id' => $this->store->id, 'name' => 'Other', 'address' => 'B St', 'city' => 'Davao', 'status' => 'active', 'verification_status' => 'verified']);
        $manager = $this->staffUser('branch_manager', $this->branch->id, true);
        $who = User::factory()->create();
        $url = "/api/v1/stores/{$this->store->id}/appointments";

        $this->actingAs($manager)->postJson($url, $this->walkInPayload($who, ['store_branch_id' => $other->id]))->assertStatus(403);
        $this->actingAs($manager)->postJson($url, $this->walkInPayload($who, ['store_branch_id' => $this->branch->id]))->assertStatus(201);
    }

    public function test_accepting_a_booking_notifies_the_customer()
    {
        Notification::fake();
        $a = $this->pendingAt(now()->addDays(3)->setTime(11, 0));

        $this->actingAs($this->user)->putJson("/api/v1/stores/{$this->store->id}/appointments/{$a->id}", ['status' => 'confirmed'])->assertStatus(200);

        Notification::assertSentTo($this->customer, AppointmentStatusNotification::class, fn ($n) => $n->statusType === 'confirmed');
    }

    public function test_confirmed_notice_also_goes_by_sms_through_the_confirmed_event()
    {
        $a = $this->pendingAt(now()->addDays(3)->setTime(11, 0));
        $notice = new AppointmentStatusNotification($a, 'confirmed');

        $this->assertContains(SmsChannel::class, $notice->via($this->customer));
        $this->assertSame('appointment_confirmed', $notice->toSms($this->customer)['event']);
        $this->assertContains('appointment_confirmed', SmsTemplates::enabledFor(null));
    }

    public function test_customer_arrived_starts_the_visit_in_one_call_and_done_has_three_results()
    {
        Notification::fake();
        $a = $this->pendingAt(now()->setTime(10, 0)->addDays(1), ['status' => 'confirmed', 'scheduled_at' => now()->subMinute()]);
        $base = "/api/v1/stores/{$this->store->id}/appointments/{$a->id}";

        $this->actingAs($this->user)->postJson("$base/arrive")->assertStatus(200)->assertJson(['started' => true]);
        $a->refresh();
        $this->assertSame('in_progress', $a->status);
        $this->assertNotNull($a->checked_in_at);
        $this->assertSame('on_time', $a->arrival_status);

        $this->actingAs($this->user)->postJson("$base/complete", ['outcome' => 'converted_to_job'])->assertStatus(422); // needs the order
        $this->actingAs($this->user)->postJson("$base/complete", ['outcome' => 'rescheduled'])->assertStatus(422); // not one of the three
        $this->actingAs($this->user)->postJson("$base/complete", ['outcome' => 'completed'])->assertStatus(200);
        $this->assertSame('completed', $a->fresh()->status);
        $this->assertSame('completed', $a->fresh()->outcome);
    }

    public function test_early_customer_waits_when_the_branch_is_busy()
    {
        $busy = $this->pendingAt(now()->addMinutes(5), ['status' => 'confirmed', 'duration_minutes' => 60]);
        $early = $this->pendingAt(now()->addMinutes(90), ['status' => 'confirmed', 'customer_id' => User::factory()->create()->id]);

        $this->actingAs($this->user)->postJson("/api/v1/stores/{$this->store->id}/appointments/{$early->id}/arrive")
            ->assertStatus(200)->assertJson(['started' => false]);
        $early->refresh();
        $this->assertSame('confirmed', $early->status);
        $this->assertSame('early', $early->arrival_status);
        $this->assertSame('waiting', $early->early_arrival_decision);
    }

    public function test_didnt_come_is_one_record_whichever_way_it_is_entered()
    {
        Notification::fake();
        $a = $this->pendingAt(now()->subHour(), ['status' => 'confirmed']);
        $b = $this->pendingAt(now()->subHours(3), ['status' => 'confirmed']);
        $base = "/api/v1/stores/{$this->store->id}/appointments";

        $this->actingAs($this->user)->postJson("$base/{$a->id}/complete", ['outcome' => 'no_show'])->assertStatus(200);
        $this->actingAs($this->user)->putJson("$base/{$b->id}", ['status' => 'no_show'])->assertStatus(200);

        foreach ([$a, $b] as $x) {
            $this->assertSame('no_show', $x->fresh()->status);
            $this->assertSame('no_show', $x->fresh()->outcome);
        }
    }

    public function test_creating_a_production_order_closes_the_appointment()
    {
        $a = $this->pendingAt(now()->addDay(), ['status' => 'confirmed', 'service_id' => $this->service->id]);

        $res = $this->actingAs($this->user)->postJson("/api/v1/stores/{$this->store->id}/jobs", [
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'appointment_id' => $a->id,
            'total_amount' => 5000,
            'balance' => 5000,
            'status' => 'pending',
        ]);

        $res->assertStatus(201);
        $a->refresh();
        $this->assertSame('completed', $a->status);
        $this->assertSame('converted_to_job', $a->outcome);
        $this->assertNotNull($a->job_order_id);
    }
}
