<?php

namespace Tests\Feature\Api\V1;

use App\Models\JobOrder;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServicePricing;
use App\Models\Store;
use App\Models\StoreBranch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A pricing option of a service says what it covers (top, bottom, both) and what ONE ordered unit is (a garment, or a set of
 * one top and one bottom). They are two separate settings, read by the server from the option itself. These tests keep the
 * five numbers apart: wearers, per-wearer quantity, ordered quantity, price and physical garments for handover.
 */
class PricingOptionUnitTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $customer;

    private Store $store;

    private Service $service;

    private ServicePricing $top;

    private ServicePricing $set;

    private ServicePricing $legacy;

    protected function setUp(): void
    {
        parent::setUp();
        $ownerRole = Role::create(['name' => 'store_owner', 'description' => 'Owner']);
        $customerRole = Role::create(['name' => 'customer', 'description' => 'Customer']);
        $this->owner = User::factory()->create();
        $this->owner->roles()->attach($ownerRole);
        $this->customer = User::factory()->create();
        $this->customer->roles()->attach($customerRole);
        $this->store = Store::create(['owner_id' => $this->owner->id, 'name' => 'Uniform Shop', 'slug' => 'uniform-shop', 'address' => '1 St', 'city' => 'Davao', 'province' => 'Davao del Sur', 'status' => 'approved']);
        StoreBranch::create(['store_id' => $this->store->id, 'name' => 'Main', 'slug' => 'main', 'address' => '1 St', 'city' => 'Davao City', 'is_main' => true, 'status' => 'active', 'verification_status' => 'verified']);
        $this->service = Service::create(['store_id' => $this->store->id, 'name' => 'School Uniform', 'base_price' => 300, 'measurement_requirement' => 'shop']);
        $this->top = $this->service->pricing()->create(['label' => 'Top only', 'amount' => 350, 'covers' => 'top', 'sold_as' => 'garment']);
        $this->set = $this->service->pricing()->create(['label' => 'Uniform set', 'amount' => 600, 'covers' => 'both', 'sold_as' => 'set']);
        $this->legacy = $this->service->pricing()->create(['label' => 'Plain option', 'amount' => 400]);
    }

    private function order(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->customer)->postJson("/api/v1/stores/{$this->store->slug}/service-orders", array_merge([
            'service_id' => $this->service->id, 'contact_phone' => '09171234567', 'order_mode' => 'individual', 'quantity' => 1,
            'material_source' => 'store_supplied', 'payment_term' => 'downpayment', 'measure_area' => 'top',
        ], $extra));
    }

    private function shopOrder(array $extra = [], array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)->postJson("/api/v1/stores/{$this->store->id}/jobs", array_merge([
            'customer_id' => $this->customer->id, 'service_id' => $this->service->id, 'total_amount' => 1200, 'balance' => 1200,
            'custom_order_data' => $data,
        ], $extra));
    }

    // The service editor ───────────────────────────────────────────────────────────────────────────

    public function test_each_option_keeps_what_it_covers_and_how_it_is_sold_and_legacy_options_stay_empty(): void
    {
        $this->assertSame(['top', 'garment'], [$this->top->fresh()->covers, $this->top->fresh()->sold_as]);
        $this->assertSame([null, null], [$this->legacy->fresh()->covers, $this->legacy->fresh()->sold_as]);

        $r = $this->actingAs($this->owner)->putJson("/api/v1/stores/{$this->store->id}/services/{$this->service->id}", [
            'name' => 'School Uniform', 'base_price' => 300,
            'pricing_tiers' => [['label' => 'Bottom only', 'amount' => 380, 'covers' => 'bottom', 'sold_as' => 'garment'], ['label' => 'Old option', 'amount' => 100]],
        ]);
        $r->assertOk();
        $rows = $this->service->fresh()->pricing->keyBy('label');
        $this->assertSame(['bottom', 'garment'], [$rows['Bottom only']->covers, $rows['Bottom only']->sold_as]);
        $this->assertSame([null, null], [$rows['Old option']->covers, $rows['Old option']->sold_as]);
    }

    public function test_a_set_must_cover_a_top_and_a_bottom(): void
    {
        $bad = fn (array $tier) => $this->actingAs($this->owner)->putJson("/api/v1/stores/{$this->store->id}/services/{$this->service->id}", [
            'name' => 'School Uniform', 'base_price' => 300, 'pricing_tiers' => [$tier + ['label' => 'X', 'amount' => 1]],
        ]);
        $bad(['covers' => 'top', 'sold_as' => 'set'])->assertStatus(422);
        $bad(['sold_as' => 'set'])->assertStatus(422);
        $bad(['covers' => 'shoes'])->assertStatus(422);
        $bad(['sold_as' => 'pair'])->assertStatus(422);
        // Covering both is allowed for a garment too (one garment that is a top and a bottom): the two settings are independent.
        $bad(['covers' => 'both', 'sold_as' => 'garment'])->assertOk();
    }

    // The customer's online order ──────────────────────────────────────────────────────────────────

    public function test_two_sets_are_two_ordered_units_priced_once_each_and_four_garments(): void
    {
        $r = $this->order(['pricing_id' => $this->set->id, 'quantity' => 2, 'measure_area' => 'top', 'size' => 'M', 'size_bottom' => 'L'])->assertStatus(201);
        $job = JobOrder::findOrFail($r->json('data.id'));

        $this->assertSame(1200.0, (float) $job->total_amount, '2 sets × ₱600: never doubled for the two garments in a set');
        $this->assertSame(2, $job->orderedQuantity());
        $this->assertSame(4, $job->garmentCount(), 'Handover counts the physical garments');
        $this->assertSame(1, $job->peopleCount() ?: 1);
        // The option decides what is covered: the customer's own "top" was replaced by what the set covers, and the label, coverage and unit are kept.
        $this->assertSame('both', $job->custom_order_data['measure_area']);
        $this->assertSame('M / L', $job->custom_order_data['standard_size']);
        $this->assertSame('Uniform set', $job->custom_order_data['option']);
        $this->assertSame(['both', 'set'], [$job->custom_order_data['option_covers'], $job->custom_order_data['option_sold_as']]);
    }

    public function test_top_only_is_one_garment_per_unit_and_covers_only_the_top(): void
    {
        $r = $this->order(['pricing_id' => $this->top->id, 'quantity' => 3, 'measure_area' => 'both', 'size' => 'M', 'individual_measurements' => ['chest' => 40, 'waist' => 32]])->assertStatus(201);
        $job = JobOrder::findOrFail($r->json('data.id'));

        $this->assertSame(1050.0, (float) $job->total_amount);
        $this->assertSame([3, 3], [$job->orderedQuantity(), $job->garmentCount()]);
        $this->assertSame('top', $job->custom_order_data['measure_area']);
        $this->assertSame(['unit' => 'in', 'chest' => '40'], $job->custom_order_data['individual_measurements'], 'A top has no waist');
    }

    public function test_an_option_with_nothing_configured_behaves_as_before(): void
    {
        $r = $this->order(['pricing_id' => $this->legacy->id, 'quantity' => 2, 'measure_area' => 'both', 'size' => 'M', 'size_bottom' => 'L'])->assertStatus(201);
        $job = JobOrder::findOrFail($r->json('data.id'));

        $this->assertSame(800.0, (float) $job->total_amount);
        $this->assertSame([2, 2], [$job->orderedQuantity(), $job->garmentCount()], 'No unit configured: one unit is one garment');
        $this->assertSame('both', $job->custom_order_data['measure_area'], 'No coverage configured: the customer chose it');
        $this->assertSame('Plain option', $job->custom_order_data['option']);
        $this->assertArrayNotHasKey('option_sold_as', $job->custom_order_data);
    }

    public function test_the_minimum_is_in_the_unit_of_the_chosen_option(): void
    {
        $this->service->update(['min_order_qty' => 3]);
        $this->order(['pricing_id' => $this->set->id, 'quantity' => 2])->assertStatus(422);
        $ok = $this->order(['pricing_id' => $this->set->id, 'quantity' => 3, 'size' => 'M', 'size_bottom' => 'L'])->assertStatus(201);
        $job = JobOrder::findOrFail($ok->json('data.id'));
        $this->assertSame([3, 6], [$job->orderedQuantity(), $job->garmentCount()], '3 sets meet the minimum of 3 even though they are 6 garments');
    }

    // The shop's New Job Order ─────────────────────────────────────────────────────────────────────

    public function test_the_shop_order_snapshots_the_option_on_the_server_and_ignores_what_the_client_claims(): void
    {
        // Claimed, but no option chosen: nothing is kept.
        $claimed = $this->shopOrder([], ['option' => 'Uniform set', 'option_covers' => 'both', 'option_sold_as' => 'set'])->assertStatus(201);
        $plain = JobOrder::findOrFail($claimed->json('data.id'));
        $this->assertSame([1, 1], [$plain->orderedQuantity(), $plain->garmentCount()]);
        $this->assertArrayNotHasKey('option_sold_as', $plain->custom_order_data ?? []);

        // Chosen: the server reads it from the option, whatever the client said about it.
        $r = $this->shopOrder(['pricing_id' => $this->set->id, 'quantity' => 2], ['option_sold_as' => 'garment', 'option_covers' => 'top'])->assertStatus(201);
        $job = JobOrder::findOrFail($r->json('data.id'));
        $this->assertSame(['Uniform set', 'both', 'set'], [$job->custom_order_data['option'], $job->custom_order_data['option_covers'], $job->custom_order_data['option_sold_as']]);
        $this->assertSame('both', $job->custom_order_data['measure_area']);
        $this->assertSame([2, 4], [$job->orderedQuantity(), $job->garmentCount()]);

        // Another service's option cannot be used.
        $other = Service::create(['store_id' => $this->store->id, 'name' => 'Other', 'base_price' => 1]);
        $foreign = $other->pricing()->create(['label' => 'Foreign', 'amount' => 1, 'covers' => 'both', 'sold_as' => 'set']);
        $this->shopOrder(['pricing_id' => $foreign->id])->assertStatus(422);
    }

    public function test_editing_the_order_or_the_option_later_never_changes_what_it_was_made_from(): void
    {
        $job = JobOrder::findOrFail($this->shopOrder(['pricing_id' => $this->set->id, 'quantity' => 2])->assertStatus(201)->json('data.id'));

        $this->actingAs($this->owner)->putJson("/api/v1/stores/{$this->store->id}/jobs/{$job->id}", ['custom_order_data' => ['option_sold_as' => 'garment', 'option_covers' => 'top', 'option' => 'x']])->assertOk();
        $this->assertSame('set', $job->fresh()->custom_order_data['option_sold_as']);

        $this->set->update(['sold_as' => 'garment', 'covers' => 'top', 'label' => 'Renamed']);
        $this->assertSame([2, 4], [$job->fresh()->orderedQuantity(), $job->fresh()->garmentCount()], 'The old order keeps the unit it was sold in');
        $this->assertSame('Uniform set', $job->fresh()->custom_order_data['option']);
    }

    public function test_the_order_carries_its_own_counts_so_no_screen_has_to_work_them_out(): void
    {
        $team = [['name' => 'Ana', 'size' => 'M', 'bottom_size' => 'L', 'qty' => 2], ['name' => 'Ben', 'size' => 'S', 'bottom_size' => 'S', 'qty' => 2]];
        $id = $this->shopOrder(['pricing_id' => $this->set->id], ['team_roster' => $team])->assertStatus(201)->json('data.id');

        $this->actingAs($this->owner)->getJson("/api/v1/stores/{$this->store->id}/jobs/{$id}")
            ->assertOk()
            ->assertJsonPath('data.ordered_quantity', 4)
            ->assertJsonPath('data.garment_count', 8)
            ->assertJsonPath('data.sold_as', 'set');

        $plain = $this->shopOrder(['quantity' => 3])->assertStatus(201)->json('data.id');
        $this->actingAs($this->owner)->getJson("/api/v1/stores/{$this->store->id}/jobs/{$plain}")
            ->assertJsonPath('data.ordered_quantity', 3)->assertJsonPath('data.garment_count', 3)->assertJsonPath('data.sold_as', 'garment');
    }

    public function test_wearers_per_wearer_quantity_ordered_quantity_and_garments_are_four_separate_numbers(): void
    {
        // Two people, two sets each.
        $team = [['name' => 'Ana', 'size' => 'M', 'bottom_size' => 'L', 'qty' => 2], ['name' => 'Ben', 'size' => 'S', 'bottom_size' => 'S', 'qty' => 2]];
        $job = JobOrder::findOrFail($this->shopOrder(['pricing_id' => $this->set->id], ['team_roster' => $team])->assertStatus(201)->json('data.id'));

        $this->assertSame(2, $job->peopleCount(), 'Two wearers');
        $this->assertSame(4, $job->orderedQuantity(), 'Two sets each = 4 ordered sets');
        $this->assertSame(8, $job->garmentCount(), '8 physical garments to hand over');
        $this->assertSame(['both', 'both'], array_column($job->custom_order_data['team_roster'], 'measure_area'), 'The option sets the coverage of every person');
    }
}
