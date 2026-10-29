<?php

namespace Tests\Feature\Api\V1;

use App\Models\JobOrder;
use App\Models\Measurement;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Service;
use App\Models\Store;
use App\Models\StoreBranch;
use App\Models\User;
use App\Support\OrderReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customer-facing online service order (POST /stores/{slug}/service-orders):
 * the service-page "Online Order" wizard's backend. The order lands in the
 * shop's normal confirm/quote/verify pipeline — nothing about production
 * readiness or payment verification changes because of it.
 */
class CustomerServiceOrderTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Store $store;
    protected User $customer;
    protected Service $service;
    protected StoreBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $ownerRole = Role::create(['name' => 'store_owner', 'description' => 'Store Owner']);
        $customerRole = Role::create(['name' => 'customer', 'description' => 'Customer']);

        $this->owner = User::factory()->create();
        $this->owner->roles()->attach($ownerRole);

        $this->store = Store::create([
            'owner_id' => $this->owner->id,
            'name' => 'Test Store',
            'slug' => 'svc-order-store',
            'address' => '123 Test St',
            'city' => 'Manila',
            'province' => 'Metro Manila',
            'status' => 'approved',
        ]);

        $this->customer = User::factory()->create();
        $this->customer->roles()->attach($customerRole);

        $this->branch = StoreBranch::create([
            'store_id' => $this->store->id,
            'name' => 'Main',
            'slug' => 'svc-main',
            'address' => '123 Test St',
            'city' => 'Davao City',
            'is_main' => true,
            'status' => 'active',
            'verification_status' => 'verified',
        ]);

        $this->service = Service::create([
            'store_id' => $this->store->id,
            'name' => 'Bespoke Shirt',
            'base_price' => 1000,
            'payment_policy' => 'deposit',
            'payment_policy_percent' => 50,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'service_id' => $this->service->id,
            'contact_phone' => '09171234567',
            'order_mode' => 'individual',
            // What the person gets is asked first: this service needs sizing, so a request without it is refused.
            'measure_area' => 'top',
            'quantity' => 1,
            'material_source' => 'store_supplied',
            'payment_term' => 'downpayment',
        ], $overrides);
    }

    public function test_an_individual_service_order_lands_pending_and_gated(): void
    {
        $response = $this->actingAs($this->customer)->postJson(
            "/api/v1/stores/{$this->store->slug}/service-orders",
            $this->payload(['size' => 'M']),
        );

        $response->assertStatus(201)->assertJsonPath('success', true);

        $job = JobOrder::where('id', $response->json('data.id'))->first();
        $this->assertNotNull($job);
        $this->assertSame('pending', $job->status);
        $this->assertSame('online', $job->intake_channel);
        $this->assertTrue((bool) $job->uses_confirmation_flow, 'The order must run the shop\'s normal confirm pipeline');
        // The service owns the order; the legacy 10-value garment taxonomy stays untouched.
        $this->assertNull($job->garment_category);
        // The chosen size lives where OrderReadiness reads it, and that is
        // enough for the shop to sign the measurements off — the order is
        // not left blocked for lack of a measurement it already carried.
        $this->assertSame('M', $job->custom_order_data['standard_size'] ?? null);
        $this->assertTrue(OrderReadiness::measurementsRecorded($job));
        // The customer landed in the shop's Client Book.
        $this->assertTrue($this->store->customers()->where('users.id', $this->customer->id)->exists());
    }

    public function test_individual_measurements_become_a_customer_sourced_profile(): void
    {
        $response = $this->actingAs($this->customer)->postJson(
            "/api/v1/stores/{$this->store->slug}/service-orders",
            $this->payload(['individual_measurements' => ['unit' => 'in', 'chest' => 40, 'waist' => 32]]),
        );

        $response->assertStatus(201);

        $jobId = $response->json('data.id');
        $this->assertDatabaseHas('measurements', [
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'source' => 'customer',
            'profile_name' => 'Bespoke Shirt Profile',
        ]);
        $this->assertNotNull(JobOrder::find($jobId)->measurement_id);
    }

    private function order(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->customer)->postJson("/api/v1/stores/{$this->store->slug}/service-orders", $this->payload($extra));
    }

    // Sizing, as an if / else: none → nothing asked · else Top/Bottom/Both first · then the sizes of that part.

    public function test_sizing_starts_with_what_the_person_gets(): void
    {
        $this->order(['measure_area' => null])->assertStatus(422)->assertJsonPath('errors.measure_area.0', 'Choose Top, Bottom or Top & Bottom.');
        $this->order(['measure_area' => 'sleeves'])->assertStatus(422);
        $this->assertSame(0, JobOrder::count());
    }

    public function test_a_service_that_needs_no_body_measurements_asks_nothing_about_sizing(): void
    {
        $this->service->update(['measurement_requirement' => 'none']);

        // No area is needed, and whatever sizing was sent anyway is dropped.
        $r = $this->order(['measure_area' => null])->assertStatus(201);
        $job = JobOrder::findOrFail($r->json('data.id'));
        $this->assertNull($job->custom_order_data['measure_area'] ?? null);

        $r = $this->order(['measure_area' => 'both', 'size' => 'M', 'size_bottom' => 'L', 'individual_measurements' => ['chest' => 40]])->assertStatus(201);
        $job = JobOrder::findOrFail($r->json('data.id'));
        $this->assertArrayNotHasKey('standard_size', $job->custom_order_data);
        $this->assertArrayNotHasKey('individual_measurements', $job->custom_order_data);
        $this->assertNull($job->measurement_id);
        // Payment follows the service's own payment rules, not its measurement rule.
        $this->assertSame('deposit', $job->payment_policy);
    }

    public function test_no_body_measurements_does_not_mean_no_service_information(): void
    {
        $this->service->update(['measurement_requirement' => 'none', 'custom_fields' => [['label' => 'Which button?', 'type' => 'text', 'required' => true]]]);

        // The service's own questions are still required...
        $this->order(['measure_area' => null])->assertStatus(422)->assertJsonPath('errors.answers.0', 'Some required questions are unanswered.');

        // ...and the details, photos and fabric still travel with the order.
        $r = $this->order([
            'measure_area' => null, 'answers' => ['Which button?' => 'Brass, 4 pieces'], 'notes' => 'Match the old buttons',
            'reference_images' => ['https://example.com/button.jpg'], 'material_source' => 'customer_supplied', 'material_description' => 'Own buttons',
        ])->assertStatus(201);
        $job = JobOrder::findOrFail($r->json('data.id'));
        $this->assertSame('Brass, 4 pieces', $job->custom_order_data['Which button?']);
        $this->assertSame(['https://example.com/button.jpg'], $job->reference_images);
        $this->assertStringContainsString('Match the old buttons', (string) $job->notes);

        // No body measurements to verify, but the design and details still have to be approved before work starts.
        $this->assertNotContains('measurements', array_column(OrderReadiness::startBlockers($job), 'key'));
        $this->assertContains('specs', array_column(OrderReadiness::confirmationBlockers($job), 'key'));
        $this->assertFalse(\App\Support\OrderPreProduction::steps($job)['steps'][1]['done'], 'Details & sizes is not done until the details are approved');
    }

    public function test_a_top_and_bottom_standard_size_needs_both_sizes_and_is_kept_as_one_line(): void
    {
        $this->order(['measure_area' => 'both', 'size' => 'M'])->assertStatus(422)->assertJsonPath('errors.size_bottom.0', 'Choose the bottom size.');

        $r = $this->order(['measure_area' => 'both', 'size' => 'M', 'size_bottom' => 'L'])->assertStatus(201);
        $job = JobOrder::findOrFail($r->json('data.id'));
        $this->assertSame('M / L', $job->custom_order_data['standard_size']);
        $this->assertSame('both', $job->custom_order_data['measure_area']);
        $this->assertTrue(OrderReadiness::measurementsRecorded($job));

        $top = $this->order(['measure_area' => 'top', 'size' => 'S', 'size_bottom' => 'XL'])->assertStatus(201);
        $this->assertSame('S', JobOrder::findOrFail($top->json('data.id'))->custom_order_data['standard_size']);
    }

    public function test_typed_measurements_only_keep_the_points_of_the_part_that_is_covered(): void
    {
        $r = $this->order(['measure_area' => 'top', 'individual_measurements' => ['unit' => 'cm', 'chest' => 96, 'waist' => 80]])->assertStatus(201);
        $job = JobOrder::findOrFail($r->json('data.id'));
        $this->assertSame(['unit' => 'cm', 'chest' => '96'], $job->custom_order_data['individual_measurements'], 'The waist belongs to the bottom');

        // The profile remembers the part and the unit, so a centimetre profile is never read as inches.
        $profile = Measurement::findOrFail($job->measurement_id);
        $this->assertSame('top', $profile->metrics['_area']);
        $this->assertSame('cm', $profile->metrics['_unit']);
    }

    public function test_a_top_and_bottom_bulk_order_counts_the_same_sets_for_both(): void
    {
        $bulk = ['order_mode' => 'bulk', 'measure_area' => 'both', 'size_breakdown' => ['M' => 3, 'L' => 2]];
        $this->order($bulk + ['size_breakdown_bottom' => ['M' => 5]])->assertStatus(201);
        $this->order($bulk + ['size_breakdown_bottom' => ['M' => 4]])->assertStatus(422)->assertJsonPath('errors.size_breakdown_bottom.0', 'Tops and bottoms must be the same number of pieces.');
        $this->order($bulk)->assertStatus(422);

        $job = JobOrder::latest('id')->firstOrFail();
        $this->assertSame(5, (int) $job->quantity, 'Five sets, not ten pieces');
        $this->assertSame(['M' => 5], $job->custom_order_data['size_breakdown_bottom']);
    }

    public function test_a_repair_keeps_where_and_how_much_to_change_without_any_body_sizing(): void
    {
        $repair = Service::create(['store_id' => $this->store->id, 'name' => 'Pants hemming', 'base_price' => 100, 'service_types' => [Service::TYPE_ALTERATION_REPAIR]]);
        $line = \App\Models\ServicePricing::create(['service_id' => $repair->id, 'label' => 'Hem', 'amount' => 150]);

        $r = $this->actingAs($this->customer)->postJson("/api/v1/stores/{$this->store->slug}/repair-requests", [
            'service_id' => $repair->id, 'garment_description' => 'Black slacks', 'pre_existing_damage_notes' => 'No visible damage',
            'local_adjustment' => 'Hem to 38 in', 'pricing_ids' => [$line->id],
        ])->assertStatus(201);

        $job = JobOrder::findOrFail($r->json('data.id'));
        $this->assertSame('Hem to 38 in', $job->custom_order_data['local_adjustment']);
        $this->assertSame(150.0, (float) $job->total_amount, 'Priced by its own line, whatever the sizing rule');
        $this->assertNull($job->measurement_id);
    }

    public function test_a_named_wearers_measurements_never_replace_the_customers_own(): void
    {
        $order = fn (array $extra) => $this->actingAs($this->customer)->postJson(
            "/api/v1/stores/{$this->store->slug}/service-orders",
            $this->payload($extra),
        )->assertStatus(201);

        // The customer orders for herself, then for her child: same garment, two different people.
        $order(['individual_measurements' => ['unit' => 'in', 'chest' => 40]]);
        $order(['wearer_name' => 'Mika', 'individual_measurements' => ['unit' => 'in', 'chest' => 28]]);

        $own = Measurement::where('profile_name', 'Bespoke Shirt Profile')->get();
        $child = Measurement::where('profile_name', 'Mika — Bespoke Shirt Profile')->get();
        $this->assertCount(1, $own);
        $this->assertCount(1, $child);
        $this->assertNull($own->first()->superseded_at, 'The child\'s order did not close out the parent\'s profile');
        $this->assertSame('40', $own->first()->metrics['chest']);
        $this->assertSame('28', $child->first()->metrics['chest']);

        // Measuring the same child again is the next version of the child's profile, and only that one.
        $order(['wearer_name' => 'Mika', 'individual_measurements' => ['unit' => 'in', 'chest' => 29]]);
        $this->assertSame(2, Measurement::where('profile_name', 'Mika — Bespoke Shirt Profile')->count());
        $this->assertSame(1, Measurement::where('profile_name', 'Bespoke Shirt Profile')->count());
        $this->assertNull(Measurement::where('profile_name', 'Bespoke Shirt Profile')->first()->superseded_at);
    }

    public function test_an_explicitly_linked_profile_is_never_reversioned(): void
    {
        $profile = Measurement::create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'profile_name' => 'Bespoke Shirt Profile',
            'metrics' => ['chest' => '40'],
        ]);

        $response = $this->actingAs($this->customer)->postJson(
            "/api/v1/stores/{$this->store->slug}/service-orders",
            $this->payload([
                'measurement_id' => $profile->id,
                // Different numbers typed in the wizard must not supersede the chosen profile.
                'individual_measurements' => ['chest' => 42],
            ]),
        );

        $response->assertStatus(201);
        $this->assertSame(1, Measurement::where('store_id', $this->store->id)->count());
        $this->assertSame($profile->id, JobOrder::find($response->json('data.id'))->measurement_id);
        $this->assertSame(['chest' => '40'], Measurement::find($profile->id)->metrics);
    }

    public function test_a_bulk_order_sums_the_size_breakdown_and_keeps_the_sign_off_gate(): void
    {
        // The shop takes the measurements itself: a plain size tally is not a sign-off.
        $this->service->update(['measurement_requirement' => 'shop']);

        $response = $this->actingAs($this->customer)->postJson(
            "/api/v1/stores/{$this->store->slug}/service-orders",
            $this->payload([
                'order_mode' => 'bulk',
                'size_breakdown' => ['XS' => 2, 'M' => 3, 'XL' => 0],
                'team_name' => 'Suntik Hi-Tech',
            ]),
        );

        $response->assertStatus(201);

        $job = JobOrder::find($response->json('data.id'));
        $this->assertSame(5, $job->quantity);
        $this->assertSame(['XS' => 2, 'M' => 3], $job->custom_order_data['size_breakdown']);
        $this->assertArrayNotHasKey('standard_size', $job->custom_order_data);

        // The tally alone does not satisfy the shop's measurement sign-off —
        // the existing gate is still standing, so the order stays blocked.
        $this->assertFalse(OrderReadiness::measurementsRecorded($job));
        $this->assertContains('measurements', array_column(OrderReadiness::startBlockers($job), 'key'));

        // Only when the shop links a recorded profile can it sign off.
        $profile = Measurement::create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'profile_name' => 'Bespoke Shirt Profile',
            'metrics' => ['chest' => '40'],
        ]);
        $this->actingAs($this->owner)->putJson(
            "/api/v1/stores/{$this->store->id}/jobs/{$job->id}",
            ['measurement_id' => $profile->id],
        )->assertStatus(200);

        $this->actingAs($this->owner)->postJson(
            "/api/v1/stores/{$this->store->id}/jobs/{$job->id}/measurements/verify",
        )->assertStatus(200);

        $job->refresh();
        $this->assertNotContains('measurements', array_column(OrderReadiness::startBlockers($job), 'key'));
    }

    public function test_a_bulk_order_below_the_minimum_is_rejected(): void
    {
        $this->service->update(['min_order_qty' => 10]);

        $this->actingAs($this->customer)->postJson(
            "/api/v1/stores/{$this->store->slug}/service-orders",
            $this->payload(['order_mode' => 'bulk', 'size_breakdown' => ['M' => 4]]),
        )->assertStatus(422);
    }

    public function test_repair_services_cannot_be_ordered_online(): void
    {
        $repair = Service::create([
            'store_id' => $this->store->id,
            'name' => 'Alterations & Repairs',
            'service_types' => [Service::TYPE_ALTERATION_REPAIR],
        ]);

        $this->actingAs($this->customer)->postJson(
            "/api/v1/stores/{$this->store->slug}/service-orders",
            $this->payload(['service_id' => $repair->id]),
        )->assertStatus(422);
    }

    public function test_unanswered_required_service_questions_are_rejected(): void
    {
        $this->service->update(['custom_fields' => [
            ['label' => 'Preferred collar', 'type' => 'text', 'required' => true],
        ]]);

        $this->actingAs($this->customer)->postJson(
            "/api/v1/stores/{$this->store->slug}/service-orders",
            $this->payload(),
        )->assertStatus(422);

        $this->actingAs($this->customer)->postJson(
            "/api/v1/stores/{$this->store->slug}/service-orders",
            $this->payload(['answers' => ['Preferred collar' => 'Band']]),
        )->assertStatus(201);
    }

    public function test_one_of_many_pricing_options_must_be_picked(): void
    {
        $this->service->pricing()->create(['label' => 'Regular', 'amount' => 1000]);
        $this->service->pricing()->create(['label' => 'Premium', 'amount' => 1500]);

        $this->actingAs($this->customer)->postJson(
            "/api/v1/stores/{$this->store->slug}/service-orders",
            $this->payload(),
        )->assertStatus(422);
    }

    public function test_a_request_asks_for_no_payment_even_when_the_shop_has_a_gcash_account(): void
    {
        PaymentMethod::create([
            'store_id' => $this->store->id,
            'kind' => 'gcash',
            'name' => 'GCash',
            'account_name' => 'Shop',
            'account_number' => '09171234567',
        ]);

        // No GCash account picked and no receipt: the request still goes through, nothing is owed yet.
        $response = $this->actingAs($this->customer)->postJson(
            "/api/v1/stores/{$this->store->slug}/service-orders",
            $this->payload(),
        );
        $response->assertStatus(201);

        $job = JobOrder::findOrFail($response->json('data.id'));
        $this->assertSame(0, $job->payments()->count(), 'Nothing is paid with a request');
        $this->assertSame('pending', $job->status);
        $this->assertSame('unpaid', $job->payment_status);
        $this->assertSame((float) $job->total_amount, (float) $job->balance);
        $this->assertSame('downpayment', $job->custom_order_data['payment_term'] ?? null, 'The preferred term is kept as a note');

        // A receipt sent anyway (an older client) is ignored, not recorded.
        $again = $this->actingAs($this->customer)->postJson(
            "/api/v1/stores/{$this->store->slug}/service-orders",
            $this->payload(['payment_method_id' => 1, 'payment_receipt' => 'gcash-receipt.png']),
        );
        $again->assertStatus(201);
        $this->assertSame(0, Payment::where('job_order_id', $again->json('data.id'))->count());

        // The deposit is paid later from My Orders, after the shop has quoted and the customer has accepted; the start gates stand.
        $blockers = array_column(OrderReadiness::startBlockers($job->fresh()), 'key');
        $this->assertContains('authorization', $blockers);
        $this->assertContains('measurements', $blockers, 'This service needs shop measurements, and none are signed off yet');
    }

    public function test_a_trusted_customer_needs_no_upfront_payment(): void
    {
        $this->store->customers()->syncWithoutDetaching([$this->customer->id => [
            'is_trusted' => true,
            'trusted_by' => $this->owner->id,
            'trusted_at' => now(),
        ]]);

        PaymentMethod::create([
            'store_id' => $this->store->id,
            'kind' => 'gcash',
            'name' => 'GCash',
            'account_name' => 'Shop',
            'account_number' => '09171234567',
        ]);

        // A trusted customer pays at pick-up, so the order goes through with no receipt at all.
        $response = $this->actingAs($this->customer)->postJson(
            "/api/v1/stores/{$this->store->slug}/service-orders",
            $this->payload(),
        );

        $response->assertStatus(201);
        $this->assertSame(0, Payment::where('job_order_id', $response->json('data.id'))->count());
        $this->assertSame('none', JobOrder::find($response->json('data.id'))->payment_policy, 'The trusted-customer rule must snapshot on the order');
    }

    public function test_the_payment_policy_endpoint_applies_the_trusted_customer_waiver(): void
    {
        $this->store->update(['default_payment_policy' => 'deposit', 'default_payment_percent' => 50]);
        $url = "/api/v1/stores/{$this->store->slug}/service-order/payment-policy?service_id={$this->service->id}";

        // Not trusted: the service's deposit policy stands, untouched.
        $this->actingAs($this->customer)->getJson($url)
            ->assertStatus(200)
            ->assertJsonPath('data.payment_policy', 'deposit')
            ->assertJsonPath('data.payment_policy_percent', 50);

        $stranger = User::factory()->create();
        $stranger->roles()->attach(Role::where('name', 'customer')->first());

        $this->store->customers()->syncWithoutDetaching([$this->customer->id => [
            'is_trusted' => true,
            'trusted_by' => $this->owner->id,
            'trusted_at' => now(),
        ]]);

        // Trusted: nothing is owed up front — the same waiver the order itself gets.
        $this->actingAs($this->customer)->getJson($url)
            ->assertStatus(200)
            ->assertJsonPath('data.payment_policy', 'none')
            ->assertJsonPath('data.payment_policy_percent', 0);

        // The trust is per customer: someone else at the same shop still sees the deposit.
        $this->actingAs($stranger)->getJson($url)
            ->assertStatus(200)
            ->assertJsonPath('data.payment_policy', 'deposit');
    }

    public function test_a_custom_fit_order_stays_blocked_until_the_shop_verifies_measurements(): void
    {
        // A bespoke service needs shop-taken measurements. An online order that
        // carries none must not start production on payment alone — the
        // measurement sign-off (verifyMeasurements) is still the gate.
        $this->service->update(['measurement_requirement' => 'shop']);

        $response = $this->actingAs($this->customer)->postJson(
            "/api/v1/stores/{$this->store->slug}/service-orders",
            $this->payload(['payment_term' => 'full']),
        );
        $response->assertStatus(201);

        $job = JobOrder::find($response->json('data.id'));
        $this->assertSame('shop', $job->measurement_requirement, 'The service requirement must snapshot onto the order');

        // No measurements recorded yet, so the sign-off gate is active.
        $this->assertFalse(OrderReadiness::measurementsRecorded($job));
        $blockers = array_column(OrderReadiness::startBlockers($job), 'key');
        $this->assertContains('measurements', $blockers, 'A custom-fit order without measurements cannot start production');

        // The shop records a standard size and signs it off through the existing endpoint…
        $job->update(['custom_order_data' => array_merge((array) $job->custom_order_data, ['standard_size' => 'M'])]);
        $this->actingAs($this->owner)->postJson(
            "/api/v1/stores/{$this->store->id}/jobs/{$job->id}/measurements/verify",
        )->assertStatus(200);

        $job->refresh();
        $this->assertNotNull($job->measurements_verified_at);
        $this->assertNotContains('measurements', array_column(OrderReadiness::startBlockers($job), 'key'),
            'Once the shop verifies the measurements the gate lifts');
    }

    public function test_a_fully_paid_order_still_cannot_start_production_on_money_alone(): void
    {
        // Settling the invoice is a separate axis from production readiness: a
        // verified full payment must not lift the measurement / authorization gates.
        $this->service->update(['measurement_requirement' => 'shop']);
        $response = $this->actingAs($this->customer)->postJson(
            "/api/v1/stores/{$this->store->slug}/service-orders",
            $this->payload(['payment_term' => 'full']),
        );
        $response->assertStatus(201);

        // The customer's receipt arrives later (My Orders), pending until the shop verifies it.
        $job = JobOrder::find($response->json('data.id'));
        $payment = $job->payments()->create([
            'amount' => $job->total_amount, 'payment_method' => 'gcash', 'source' => 'online',
            'type' => 'full', 'recorded_by' => $this->customer->id, 'receipt_path' => 'gcash-full.png',
        ]);
        $this->actingAs($this->owner)->postJson(
            "/api/v1/stores/{$this->store->id}/jobs/{$job->id}/payments/{$payment->id}/verify",
        )->assertStatus(200);

        $job->refresh();
        $this->assertSame('paid', $job->payment_status, 'A verified full payment settles the invoice');
        // But the order is still not production-ready: the money axis closed,
        // the measurement / authorization / tailoring axes did not.
        $blockers = array_column(OrderReadiness::startBlockers($job), 'key');
        $this->assertContains('measurements', $blockers);
        $this->assertContains('authorization', $blockers);
    }

    public function test_someone_elses_measurement_profile_cannot_be_linked(): void
    {
        $other = User::factory()->create();
        $profile = Measurement::create([
            'store_id' => $this->store->id,
            'customer_id' => $other->id,
            'profile_name' => 'Default',
            'metrics' => ['chest' => '40'],
        ]);

        $this->actingAs($this->customer)->postJson(
            "/api/v1/stores/{$this->store->slug}/service-orders",
            $this->payload(['measurement_id' => $profile->id]),
        )->assertStatus(422);
    }

    public function test_the_staff_endpoint_lists_only_the_store_s_measurements(): void
    {
        $stranger = User::factory()->create();
        $this->store->customers()->syncWithoutDetaching([$this->customer->id]);
        Measurement::create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'profile_name' => 'Bespoke Shirt Profile',
            'metrics' => ['chest' => '40'],
        ]);

        // A customer-role account is outside the route's role gate.
        $this->actingAs($this->customer)->getJson(
            "/api/v1/stores/{$this->store->id}/customers/{$this->customer->id}/measurements",
        )->assertStatus(403);

        // The owner sees the customer's own profiles.
        $this->actingAs($this->owner)->getJson(
            "/api/v1/stores/{$this->store->id}/customers/{$this->customer->id}/measurements",
        )->assertStatus(200)->assertJsonCount(1, 'data');

        // And nothing is reachable for someone who is not this shop's customer.
        $this->actingAs($this->owner)->getJson(
            "/api/v1/stores/{$this->store->id}/customers/{$stranger->id}/measurements",
        )->assertStatus(404);
    }

    public function test_my_measurements_backfills_unlinked_job_orders(): void
    {
        // A pre-sync legacy order: measurements still sit in custom_order_data, no profile yet.
        $job = JobOrder::create([
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'order_number' => 'SO-LEGACY-1',
            'tracking_code' => 'LGC1X',
            'intake_channel' => 'online',
            'status' => 'pending',
            'custom_order_data' => ['individual_measurements' => ['unit' => 'in', 'chest' => '40']],
        ]);
        $this->assertNull($job->measurement_id);

        $this->actingAs($this->customer)->getJson('/api/v1/my-measurements')
            ->assertStatus(200)->assertJsonCount(1, 'data');

        $this->assertDatabaseHas('measurements', [
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'source' => 'store_owner',
        ]);
        $this->assertNotNull($job->fresh()->measurement_id);
    }
}
