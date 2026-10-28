<?php

namespace Tests\Feature\Api\V1;

use App\Models\JobOrder;
use App\Models\Measurement;
use App\Models\Role;
use App\Models\Service;
use App\Models\Store;
use App\Models\User;
use App\Support\OrderReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customer who orders, the people who wear the garments, their measurement profiles and the number of garments are
 * four different things. These tests pin down that they are not confused with one another:
 *   A  one person, one measurement, several garments
 *   B  one person, several garment types, a profile each
 *   C  a team: every wearer keeps their own size
 *   D  a team where wearers need different numbers of garments
 *   E  measurements taken later never count as done
 */
class WearerQuantityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $customer;

    private Store $store;

    private Service $shirt;

    private Service $teamService;

    protected function setUp(): void
    {
        parent::setUp();
        $ownerRole = Role::create(['name' => 'store_owner', 'description' => 'Owner']);
        $customerRole = Role::create(['name' => 'customer', 'description' => 'Customer']);

        $this->owner = User::factory()->create();
        $this->owner->roles()->attach($ownerRole);
        $this->customer = User::factory()->create();
        $this->customer->roles()->attach($customerRole);

        $this->store = Store::create([
            'owner_id' => $this->owner->id, 'name' => 'Test Store', 'slug' => 'wearer-store',
            'address' => '1 Test St', 'city' => 'Davao', 'province' => 'Davao del Sur', 'status' => 'approved',
        ]);
        $this->shirt = Service::create(['store_id' => $this->store->id, 'name' => 'Polo Shirt', 'base_price' => 500]);
        $this->teamService = Service::create([
            'store_id' => $this->store->id, 'name' => 'Team Uniform', 'base_price' => 400,
            'service_types' => [Service::TYPE_BULK_SUBLIMATION],
        ]);
    }

    private function createJob(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)->postJson("/api/v1/stores/{$this->store->id}/jobs", array_merge([
            'customer_id' => $this->customer->id,
            'service_id' => $this->shirt->id,
            'total_amount' => 1500,
            'balance' => 1500,
        ], $overrides));
    }

    private function team(array $rows, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->createJob(array_merge([
            'service_id' => $this->teamService->id,
            'custom_order_data' => ['team_roster' => $rows],
        ], $overrides));
    }

    private function profile(string $name, array $metrics = ['chest' => '40']): Measurement
    {
        return Measurement::create([
            'store_id' => $this->store->id, 'customer_id' => $this->customer->id,
            'profile_name' => $name, 'version' => 1, 'metrics' => $metrics,
        ]);
    }

    // A ─ one person, three polos ──────────────────────────────────────────────────────────────────

    public function test_one_person_with_three_polos_is_one_measurement_and_three_garments(): void
    {
        $polo = $this->profile('Polo Profile');

        $response = $this->createJob(['quantity' => 3, 'measurement_id' => $polo->id])->assertStatus(201);

        $job = JobOrder::findOrFail($response->json('data.id'));
        $this->assertSame(3, $job->quantity);
        $this->assertSame($polo->id, $job->measurement_id);
        $this->assertSame(3, $job->garmentCount(), 'Three polos for one person are three garments');
        $this->assertSame(0, $job->peopleCount(), 'An individual order is not a group order');
        $this->assertSame(1, Measurement::where('customer_id', $this->customer->id)->count(), 'No second measurement record was made');
        $this->assertTrue(OrderReadiness::measurementsRecorded($job));
    }

    // B ─ one person, several garment types ────────────────────────────────────────────────────────

    public function test_editing_one_measurement_profile_leaves_the_others_alone(): void
    {
        $polo = $this->profile('Polo Profile', ['chest' => '40']);
        $pants = $this->profile('Formal Pants Profile', ['waist' => '32']);
        $suit = $this->profile('Suit Profile', ['chest' => '42', 'shoulders' => '18']);

        $poloJob = $this->createJob(['measurement_id' => $polo->id])->assertStatus(201)->json('data.id');
        $pantsJob = $this->createJob(['measurement_id' => $pants->id])->assertStatus(201)->json('data.id');

        $this->actingAs($this->owner)->putJson("/api/v1/stores/{$this->store->id}/measurements/{$polo->id}", ['metrics' => ['chest' => '41']])
            ->assertOk()->assertJsonPath('data.version', 2);

        $this->assertNotNull($polo->fresh()->superseded_at, 'Only the edited profile is closed out');
        $this->assertNull($pants->fresh()->superseded_at);
        $this->assertNull($suit->fresh()->superseded_at);
        $this->assertSame(['waist' => '32'], $pants->fresh()->metrics);
        $this->assertSame(['chest' => '42', 'shoulders' => '18'], $suit->fresh()->metrics);
        // The pants order keeps the exact snapshot it was made with.
        $this->assertSame($pants->id, JobOrder::find($pantsJob)->measurement_id);
        $this->assertSame($polo->id, JobOrder::find($poloJob)->measurement_id, 'A past order keeps the version it was made with');
    }

    public function test_extra_services_are_their_own_orders_and_do_not_borrow_the_first_profile(): void
    {
        $polo = $this->profile('Polo Profile');
        $trousers = Service::create(['store_id' => $this->store->id, 'name' => 'Trousers', 'base_price' => 800]);

        $this->createJob([
            'measurement_id' => $polo->id,
            'extra_services' => [['service_id' => $trousers->id, 'quantity' => 2]],
        ])->assertStatus(201);

        $extra = JobOrder::where('service_id', $trousers->id)->firstOrFail();
        $this->assertNull($extra->measurement_id, 'Trousers are not cut from the polo measurements');
        $this->assertSame(2, $extra->garmentCount());
        $this->assertSame(2, JobOrder::where('customer_id', $this->customer->id)->count());
    }

    // C / D ─ a team ─────────────────────────────────────────────────────────────────────────────

    public function test_a_team_list_counts_wearers_and_pieces_separately(): void
    {
        $response = $this->team([
            ['name' => 'Ana', 'size' => 'M', 'qty' => 2],
            ['name' => 'Ben', 'size' => 'L', 'qty' => 3],
        ])->assertStatus(201);

        $job = JobOrder::findOrFail($response->json('data.id'));
        $this->assertSame(2, $job->peopleCount(), 'Two wearers');
        $this->assertSame(5, $job->garmentCount(), 'Two + three garments');
        $this->assertSame(1, $job->quantity, 'A group order keeps quantity 1: the team list holds the wearers');
        // Each wearer keeps their own size and quantity.
        $this->assertSame(['M', 'L'], array_column($job->custom_order_data['team_roster'], 'size'));
        $this->assertSame([2, 3], array_map('intval', array_column($job->custom_order_data['team_roster'], 'qty')));
    }

    public function test_two_people_with_two_polos_each_are_two_wearers_and_four_garments(): void
    {
        $job = JobOrder::findOrFail($this->team([
            ['name' => 'Ana', 'measure_area' => 'top', 'size' => 'M', 'qty' => 2],
            ['name' => 'Ben', 'measure_area' => 'top', 'size' => 'L', 'qty' => 2],
        ])->assertStatus(201)->json('data.id'));

        $this->assertSame(2, $job->peopleCount());
        $this->assertSame(4, $job->garmentCount());
        // Handover, price share and the minimum all count these four garments (see the tests above).
    }

    public function test_a_top_and_bottom_person_is_one_set_one_piece_unless_given_a_quantity(): void
    {
        $job = JobOrder::findOrFail($this->team([
            ['name' => 'Ana', 'measure_area' => 'both', 'size' => 'M', 'bottom_size' => 'L'],
            ['name' => 'Ben', 'measure_area' => 'both', 'size' => 'S', 'bottom_size' => 'S', 'qty' => 2],
        ])->assertStatus(201)->json('data.id'));

        $this->assertSame(2, $job->peopleCount());
        $this->assertSame(3, $job->garmentCount(), 'Ana is one set; Ben asked for two sets');
    }

    public function test_people_without_a_quantity_count_one_piece_each(): void
    {
        $job = JobOrder::findOrFail($this->team([['name' => 'Ana', 'size' => 'M'], ['name' => 'Ben', 'size' => 'L'], ['name' => 'Cy', 'size' => 'S']])
            ->assertStatus(201)->json('data.id'));

        $this->assertSame(3, $job->peopleCount());
        $this->assertSame(3, $job->garmentCount());
    }

    public function test_a_wearers_quantity_must_be_a_whole_number_of_at_least_one(): void
    {
        $this->team([['name' => 'Ana', 'size' => 'M', 'qty' => 0]])->assertStatus(422);
        $this->team([['name' => 'Ana', 'size' => 'M', 'qty' => 'two']])->assertStatus(422);
    }

    public function test_the_minimum_for_a_team_order_is_counted_in_pieces(): void
    {
        $this->teamService->update(['min_order_qty' => 10]);

        // 5 people with 2 pieces each is 10 pieces: it meets the minimum.
        $five = array_map(fn ($n) => ['name' => "P{$n}", 'size' => 'M', 'qty' => 2], range(1, 5));
        $this->team($five)->assertStatus(201);

        // 5 people with 1 piece each is only 5 pieces: below the minimum.
        $one = array_map(fn ($n) => ['name' => "P{$n}", 'size' => 'M', 'qty' => 1], range(1, 5));
        $this->team($one)->assertStatus(422)->assertJsonPath('code', 'below_minimum')->assertJsonPath('quantity', 5);
    }

    public function test_every_piece_is_handed_over_not_only_one_per_person(): void
    {
        $job = JobOrder::findOrFail($this->team([
            ['name' => 'Ana', 'size' => 'M', 'qty' => 2],
            ['name' => 'Ben', 'size' => 'L', 'qty' => 3],
        ])->assertStatus(201)->json('data.id'));
        $job->forceFill(['status' => 'balance_handover', 'balance' => 0, 'payment_status' => 'paid', 'total_amount' => 1000])->save();
        $url = "/api/v1/stores/{$this->store->id}/jobs/{$job->id}/handovers";

        $this->actingAs($this->owner)->postJson($url, ['quantity' => 6])->assertStatus(422);
        $this->actingAs($this->owner)->postJson($url, ['quantity' => 5])->assertStatus(201);
        $this->assertSame(0, max(0, $job->fresh()->garmentCount() - OrderReadiness::handedOver($job->fresh())));
        $this->actingAs($this->owner)->postJson($url, ['quantity' => 1])->assertStatus(422);
    }

    public function test_splitting_a_team_order_shares_the_price_by_pieces_not_heads(): void
    {
        $job = JobOrder::findOrFail($this->team([
            ['name' => 'Ana', 'size' => 'M', 'qty' => 1],
            ['name' => 'Ben', 'size' => 'L', 'qty' => 3],
        ], ['total_amount' => 1000, 'balance' => 1000])->assertStatus(201)->json('data.id'));

        // Ben (3 of the 4 pieces) pays for his own batch, with no price typed: his share follows his pieces.
        $this->actingAs($this->owner)->postJson("/api/v1/stores/{$this->store->id}/jobs/{$job->id}/split", [
            'groups' => [['people' => [1], 'paid_by_name' => 'Ben']],
        ])->assertStatus(201);

        $child = JobOrder::where('parent_order_id', $job->id)->firstOrFail();
        $this->assertEquals(750.00, (float) $child->total_amount);
        $this->assertEquals(250.00, (float) $job->fresh()->total_amount);
        $this->assertSame(3, $child->garmentCount());
        $this->assertSame(1, $job->fresh()->garmentCount());
    }

    // C ─ every wearer keeps their own profile ───────────────────────────────────────────────────

    public function test_each_custom_fit_wearer_gets_their_own_profile(): void
    {
        $job = JobOrder::findOrFail($this->team([
            ['name' => 'Ana', 'size' => 'Custom Fit', 'qty' => 2, 'fit' => 'Chest 40 · Waist 32', 'fit_metrics' => ['Chest' => '40', 'Waist' => '32']],
            ['name' => 'Ben', 'size' => 'M', 'qty' => 1],
            ['name' => 'Cy', 'size' => 'Custom Fit', 'qty' => 3, 'fit' => 'Chest 44', 'fit_metrics' => ['Chest' => '44']],
        ])->assertStatus(201)->json('data.id'));

        $ana = Measurement::where('profile_name', 'Ana — Team Uniform Profile')->get();
        $cy = Measurement::where('profile_name', 'Cy — Team Uniform Profile')->get();
        $this->assertCount(1, $ana);
        $this->assertCount(1, $cy);
        $this->assertSame($this->customer->id, $ana->first()->customer_id);
        $this->assertSame('40', $ana->first()->metrics['Chest']);
        $this->assertSame('44', $cy->first()->metrics['Chest']);
        $this->assertSame(0, Measurement::where('profile_name', 'like', 'Ben%')->count(), 'A chart size makes no profile');

        // Each row points at its own wearer's profile, and the working points are not left on the order.
        $roster = $job->fresh()->custom_order_data['team_roster'];
        $this->assertSame($ana->first()->id, (int) $roster[0]['measurement_id']);
        $this->assertArrayNotHasKey('measurement_id', $roster[1]);
        $this->assertSame($cy->first()->id, (int) $roster[2]['measurement_id']);
        $this->assertArrayNotHasKey('fit_metrics', $roster[0]);
        $this->assertSame(6, $job->fresh()->garmentCount(), 'Three wearers, six pieces, two profiles');
    }

    public function test_measuring_the_same_wearer_again_is_the_next_version_of_their_profile_only(): void
    {
        $order = fn (array $chest) => $this->team([
            ['name' => 'Ana', 'size' => 'Custom Fit', 'fit' => 'x', 'fit_metrics' => ['Chest' => $chest[0]]],
            ['name' => 'Cy', 'size' => 'Custom Fit', 'fit' => 'x', 'fit_metrics' => ['Chest' => $chest[1]]],
        ])->assertStatus(201);

        $order(['40', '44']);
        $order(['41', '44']); // Ana changed, Cy did not

        $ana = Measurement::where('profile_name', 'Ana — Team Uniform Profile')->orderBy('version')->get();
        $this->assertCount(2, $ana);
        $this->assertNotNull($ana[0]->superseded_at);
        $this->assertNull($ana[1]->superseded_at);
        $this->assertSame('41', $ana[1]->metrics['Chest']);
        $cy = Measurement::where('profile_name', 'Cy — Team Uniform Profile')->get();
        $this->assertCount(1, $cy, 'An unchanged wearer is not given a new version');
        $this->assertNull($cy->first()->superseded_at);
    }

    public function test_custom_fit_counts_as_measured_only_with_measurements_or_a_profile(): void
    {
        $job = JobOrder::findOrFail($this->team([['name' => 'Ana', 'size' => 'Custom Fit']])->assertStatus(201)->json('data.id'));
        $this->assertFalse(OrderReadiness::measurementsRecorded($job->fresh()), 'The size label alone is not a measurement');

        $data = $job->custom_order_data;
        $data['team_roster'][0]['fit'] = 'Chest 40';
        $job->forceFill(['custom_order_data' => $data])->save();
        $this->assertTrue(OrderReadiness::measurementsRecorded($job->fresh()));

        $data['team_roster'][0] = ['name' => 'Ana', 'size' => 'Custom Fit', 'measurement_id' => $this->profile('Ana — Team Uniform Profile')->id];
        $job->forceFill(['custom_order_data' => $data])->save();
        $this->assertTrue(OrderReadiness::measurementsRecorded($job->fresh()));
    }

    public function test_a_wearer_cannot_be_linked_to_another_customers_measurements(): void
    {
        $stranger = User::factory()->create();
        $stranger->roles()->attach(Role::where('name', 'customer')->first());
        $theirs = Measurement::create(['store_id' => $this->store->id, 'customer_id' => $stranger->id, 'profile_name' => 'Their Profile', 'version' => 1, 'metrics' => ['chest' => '30']]);

        $this->team([['name' => 'Ana', 'size' => 'Custom Fit', 'fit' => 'x', 'measurement_id' => $theirs->id]])->assertStatus(422);
        $this->assertSame(0, JobOrder::count());
    }

    // E ─ measured later ─────────────────────────────────────────────────────────────────────────

    public function test_an_order_taken_before_measuring_cannot_be_marked_measured(): void
    {
        $this->shirt->update(['measurement_requirement' => 'shop']);
        $job = JobOrder::findOrFail($this->createJob()->assertStatus(201)->json('data.id'));
        $job->forceFill(['measurement_requirement' => 'shop'])->save();
        $job = $job->fresh();

        $this->assertFalse(OrderReadiness::measurementsRecorded($job));
        $this->assertContains('measurements', array_column(OrderReadiness::startBlockers($job), 'key'));
        $this->actingAs($this->owner)->postJson("/api/v1/stores/{$this->store->id}/jobs/{$job->id}/measurements/verify")->assertStatus(422);

        // Recording a measurement is what lets the shop sign it off, not the order or its payment.
        $polo = $this->profile('Polo Profile');
        $this->actingAs($this->owner)->putJson("/api/v1/stores/{$this->store->id}/jobs/{$job->id}", ['measurement_id' => $polo->id])->assertOk();
        $this->actingAs($this->owner)->postJson("/api/v1/stores/{$this->store->id}/jobs/{$job->id}/measurements/verify")->assertOk();
        $this->assertNotContains('measurements', array_column(OrderReadiness::startBlockers($job->fresh()), 'key'));
    }

    public function test_a_team_is_not_measured_until_every_person_has_a_size(): void
    {
        $job = JobOrder::findOrFail($this->team([
            ['name' => 'Ana', 'size' => 'M', 'qty' => 1],
            ['name' => 'Ben', 'size' => '', 'qty' => 1],
        ])->assertStatus(201)->json('data.id'));
        $job->forceFill(['measurement_requirement' => 'shop'])->save();

        $this->assertFalse(OrderReadiness::measurementsRecorded($job->fresh()));
        $this->actingAs($this->owner)->postJson("/api/v1/stores/{$this->store->id}/jobs/{$job->id}/measurements/verify")->assertStatus(422);

        $data = $job->custom_order_data;
        $data['team_roster'][1]['size'] = 'L';
        $job->forceFill(['custom_order_data' => $data])->save();

        $this->assertTrue(OrderReadiness::measurementsRecorded($job->fresh()));
        $this->actingAs($this->owner)->postJson("/api/v1/stores/{$this->store->id}/jobs/{$job->id}/measurements/verify")->assertOk();
    }

    // F ─ editing the team list on the order page ─────────────────────────────────────────────────

    private function editRoster(JobOrder $job, array $rows): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)->putJson("/api/v1/stores/{$this->store->id}/jobs/{$job->id}", [
            'custom_order_data' => ['team_roster' => $rows],
        ]);
    }

    public function test_the_people_count_follows_an_edited_team_list_while_the_order_is_a_request(): void
    {
        $job = JobOrder::findOrFail($this->team([
            ['name' => 'Ana', 'size' => 'M', 'qty' => 1],
            ['name' => 'Ben', 'size' => 'L', 'qty' => 2],
        ])->assertStatus(201)->json('data.id'));
        $this->assertSame('pending', $job->status);

        $this->editRoster($job, [
            ['name' => 'Ana', 'size' => 'M', 'qty' => 1],
            ['name' => 'Ben', 'size' => 'L', 'qty' => 2],
            ['name' => 'Cy', 'size' => 'S', 'qty' => 3],
        ])->assertOk();

        $job = $job->fresh();
        $this->assertSame(3, $job->peopleCount());
        $this->assertSame(6, $job->garmentCount());
    }

    public function test_people_and_pieces_cannot_change_once_the_order_is_confirmed(): void
    {
        $job = JobOrder::findOrFail($this->team([
            ['name' => 'Ana', 'size' => 'M', 'qty' => 1],
            ['name' => 'Ben', 'size' => 'L', 'qty' => 2],
        ])->assertStatus(201)->json('data.id'));
        $job->forceFill(['status' => 'confirmed'])->save();

        // One more person, and more pieces for an existing person: both are refused and nothing is saved.
        $this->editRoster($job, [
            ['name' => 'Ana', 'size' => 'M', 'qty' => 1],
            ['name' => 'Ben', 'size' => 'L', 'qty' => 2],
            ['name' => 'Cy', 'size' => 'S', 'qty' => 1],
        ])->assertStatus(422);
        $this->editRoster($job, [
            ['name' => 'Ana', 'size' => 'M', 'qty' => 4],
            ['name' => 'Ben', 'size' => 'L', 'qty' => 2],
        ])->assertStatus(422);

        $job = $job->fresh();
        $this->assertSame(2, $job->peopleCount());
        $this->assertSame(3, $job->garmentCount());
    }

    public function test_a_size_or_name_can_still_be_corrected_after_the_order_is_confirmed(): void
    {
        $job = JobOrder::findOrFail($this->team([
            ['name' => 'Ana', 'size' => 'M', 'qty' => 1],
            ['name' => 'Ben', 'size' => 'L', 'qty' => 2],
        ])->assertStatus(201)->json('data.id'));
        $job->forceFill(['status' => 'confirmed'])->save();

        $this->editRoster($job, [
            ['name' => 'Ana', 'size' => 'L', 'qty' => 1],
            ['name' => 'Benjamin', 'size' => 'L', 'qty' => 2],
        ])->assertOk();

        $roster = $job->fresh()->custom_order_data['team_roster'];
        $this->assertSame('L', $roster[0]['size']);
        $this->assertSame('Benjamin', $roster[1]['name']);
    }

    public function test_editing_the_team_list_keeps_each_persons_pieces_and_profile_link_but_not_another_customers(): void
    {
        $ana = $this->profile('Ana — Team Uniform Profile');
        $job = JobOrder::findOrFail($this->team([['name' => 'Ana', 'size' => 'Custom Fit', 'qty' => 2, 'measurement_id' => $ana->id]])->assertStatus(201)->json('data.id'));

        $this->editRoster($job, [['name' => 'Ana', 'size' => 'Custom Fit', 'qty' => 3, 'measurement_id' => $ana->id]])->assertOk();
        $row = $job->fresh()->custom_order_data['team_roster'][0];
        $this->assertSame(3, $row['qty']);
        $this->assertSame($ana->id, $row['measurement_id']);

        $stranger = User::factory()->create();
        $theirs = Measurement::create(['store_id' => $this->store->id, 'customer_id' => $stranger->id, 'profile_name' => 'Theirs', 'version' => 1, 'metrics' => ['chest' => '30']]);
        $this->editRoster($job, [['name' => 'Ana', 'size' => 'Custom Fit', 'qty' => 3, 'measurement_id' => $theirs->id]])->assertStatus(422);
        $this->assertSame($ana->id, $job->fresh()->custom_order_data['team_roster'][0]['measurement_id']);
    }

    public function test_what_is_measured_for_a_custom_fit_person_is_kept_on_their_profile(): void
    {
        $this->team([
            ['name' => 'Ana', 'size' => 'Custom Fit', 'measure_area' => 'bottom', 'fit' => 'Waist 30', 'fit_metrics' => ['Waist' => '30', 'Hips' => '38']],
            ['name' => 'Ben', 'size' => 'Custom Fit', 'fit' => 'Chest 40', 'fit_metrics' => ['Chest' => '40']],
        ])->assertStatus(201);

        $this->assertSame('bottom', Measurement::where('profile_name', 'Ana — Team Uniform Profile')->firstOrFail()->metrics['_area']);
        $this->assertSame('both', Measurement::where('profile_name', 'Ben — Team Uniform Profile')->firstOrFail()->metrics['_area'], 'No choice made keeps the old behaviour');
    }

    public function test_a_top_and_bottom_person_needs_a_size_for_each_part(): void
    {
        $job = JobOrder::findOrFail($this->team([
            ['name' => 'Ana', 'measure_area' => 'both', 'size' => 'M'],
            ['name' => 'Ben', 'measure_area' => 'top', 'size' => 'L'],
        ])->assertStatus(201)->json('data.id'));
        $this->assertFalse(OrderReadiness::measurementsRecorded($job->fresh()), 'Ana has a top size but no bottom size');

        $data = $job->custom_order_data;
        $data['team_roster'][0]['bottom_size'] = 'L';
        $job->forceFill(['custom_order_data' => $data])->save();
        $this->assertTrue(OrderReadiness::measurementsRecorded($job->fresh()));
    }

    public function test_custom_fit_on_only_the_bottom_still_needs_measurements(): void
    {
        $job = JobOrder::findOrFail($this->team([
            ['name' => 'Ana', 'measure_area' => 'both', 'size' => 'M', 'bottom_size' => 'Custom Fit'],
        ])->assertStatus(201)->json('data.id'));
        $this->assertFalse(OrderReadiness::measurementsRecorded($job->fresh()), 'The bottom is Custom Fit and unmeasured');

        $data = $job->custom_order_data;
        $data['team_roster'][0]['fit'] = 'Waist 30';
        $job->forceFill(['custom_order_data' => $data])->save();
        $this->assertTrue(OrderReadiness::measurementsRecorded($job->fresh()));
    }

    public function test_the_two_sizes_and_what_a_person_gets_are_saved_on_the_order(): void
    {
        $job = JobOrder::findOrFail($this->team([['name' => 'Ana', 'measure_area' => 'both', 'size' => 'M', 'bottom_size' => 'L']])->assertStatus(201)->json('data.id'));
        $row = $job->custom_order_data['team_roster'][0];
        $this->assertSame('both', $row['measure_area']);
        $this->assertSame('L', $row['bottom_size']);
    }

    public function test_a_service_that_needs_no_measuring_is_not_blocked_on_measurements(): void
    {
        $this->shirt->update(['measurement_requirement' => 'none']);
        $job = JobOrder::findOrFail($this->createJob()->assertStatus(201)->json('data.id'));
        $job->forceFill(['measurement_requirement' => 'none'])->save();

        $this->assertNotContains('measurements', array_column(OrderReadiness::startBlockers($job->fresh()), 'key'));
    }
}
