<?php

namespace Tests\Feature\Api\V1;

use App\Models\JobOrder;
use App\Models\Role;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\Store;
use App\Models\StoreBranch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Only the owner and a branch manager see what an order costs and what was paid; plain staff never get an amount from the API. */
class StaffMoneyVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $manager;

    private User $staff;

    private User $customer;

    private Store $store;

    private JobOrder $job;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['store_owner', 'branch_manager', 'staff', 'customer'] as $name) {
            Role::firstOrCreate(['name' => $name], ['description' => $name]);
        }
        $make = function (string $role) {
            $u = User::factory()->create();
            $u->roles()->attach(Role::where('name', $role)->first());

            return $u;
        };
        $this->owner = $make('store_owner');
        $this->store = Store::create(['owner_id' => $this->owner->id, 'name' => 'S', 'slug' => 's', 'address' => '1', 'city' => 'D', 'province' => 'D', 'status' => 'approved']);
        $branch = StoreBranch::create(['store_id' => $this->store->id, 'name' => 'A', 'slug' => 'a', 'address' => 'A', 'city' => 'D', 'is_main' => true, 'status' => 'active', 'verification_status' => 'verified']);
        $member = function (string $role, bool $manager) use ($make, $branch) {
            $u = $make($role);
            StaffProfile::create(['store_id' => $this->store->id, 'user_id' => $u->id, 'store_branch_id' => $branch->id, 'role' => 'tailor', 'is_branch_manager' => $manager]);

            return $u;
        };
        $this->manager = $member('branch_manager', true);
        $this->staff = $member('staff', false);
        $this->customer = $make('customer');
        $this->store->customers()->syncWithoutDetaching([$this->customer->id => ['notes' => null]]);
        $service = Service::create(['store_id' => $this->store->id, 'name' => 'Polo', 'base_price' => 450, 'measurement_requirement' => 'none']);
        $this->job = JobOrder::create([
            'store_id' => $this->store->id, 'store_branch_id' => $branch->id, 'customer_id' => $this->customer->id, 'service_id' => $service->id,
            'order_number' => 'JO-1', 'total_amount' => 12345.67, 'balance' => 6000.5, 'discount_amount' => 100, 'rush_fee' => 250, 'status' => 'balance_handover', 'uses_confirmation_flow' => true,
            'quotation' => ['base_price' => 12345.67, 'additional_charges' => [['label' => 'x', 'amount' => 999]]], 'quotation_status' => 'final',
            'custom_order_data' => ['item_rate' => '450'],
        ]);
        $this->job->payments()->create(['amount' => 6345.17, 'payment_method' => 'cash', 'source' => 'walk_in', 'type' => 'deposit', 'verified_at' => now(), 'recorded_by' => $this->owner->id]);
    }

    private function seen(User $as, string $path): string
    {
        return $this->actingAs($as)->getJson("/api/v1/stores/{$this->store->id}$path")->assertOk()->getContent();
    }

    private const AMOUNTS = ['12345', '6000', '6345', '999', '12,345', '6,000'];

    private function paths(): array
    {
        return ['/jobs', "/jobs/{$this->job->id}", "/jobs/{$this->job->id}/readiness", '/customers', "/customers/{$this->customer->id}"];
    }

    public function test_the_owner_and_a_branch_manager_still_see_the_amounts(): void
    {
        foreach ([$this->owner, $this->manager] as $user) {
            $job = json_decode($this->seen($user, "/jobs/{$this->job->id}"), true)['data'];
            $this->assertEquals(12345.67, $job['total_amount']);
            $this->assertEquals(6000.5, $job['balance']);
            $this->assertNotEmpty($job['payments']);
            $this->assertStringContainsString('12345', $this->seen($user, '/customers'), 'Customers show what was spent');
        }
    }

    public function test_staff_get_no_amount_from_any_job_or_customer_endpoint(): void
    {
        foreach ($this->paths() as $path) {
            $body = $this->seen($this->staff, $path);
            foreach (self::AMOUNTS as $amount) {
                $this->assertStringNotContainsString($amount, $body, "$path leaks $amount to staff");
            }
        }
        $job = json_decode($this->seen($this->staff, "/jobs/{$this->job->id}"), true)['data'];
        foreach (JobOrder::MONEY_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $job);
        }
        $this->assertArrayNotHasKey('payments', $job);
        $this->assertArrayNotHasKey('item_rate', $job['custom_order_data']);
        $this->assertTrue($job['has_balance'], 'Staff still learn that something is owed, without the amount');
    }

    public function test_the_saved_order_is_untouched_by_hiding_it(): void
    {
        $this->seen($this->staff, "/jobs/{$this->job->id}");
        $fresh = $this->job->fresh();
        $this->assertEquals(6000.5, (float) $fresh->balance);
        $this->assertSame('450', $fresh->custom_order_data['item_rate']);
        $this->assertSame(1, $fresh->payments()->count());
    }

    public function test_staff_are_told_to_collect_the_balance_without_being_told_how_much(): void
    {
        $staff = $this->actingAs($this->staff)->postJson("/api/v1/stores/{$this->store->id}/jobs/{$this->job->id}/handovers", ['quantity' => 1]);
        $staff->assertStatus(422);
        $this->assertStringNotContainsString('₱', $staff->json('message'));
        $this->assertStringContainsString('Collect the remaining balance first', $staff->json('message'));

        $owner = $this->actingAs($this->owner)->postJson("/api/v1/stores/{$this->store->id}/jobs/{$this->job->id}/handovers", ['quantity' => 1]);
        $this->assertStringContainsString('₱6,000.50', $owner->json('message'), 'The owner is told the amount');
    }
}
