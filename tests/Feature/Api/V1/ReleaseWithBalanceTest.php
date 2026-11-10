<?php

namespace Tests\Feature\Api\V1;

use App\Models\JobOrder;
use App\Models\Role;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DSD-02 WF-7 W-F01 / W-F03, WD-16: Ready, Completed and Financially Settled are different events. A garment may be
 * released with a balance only when authorized, with the recipient, due date and the customer's acknowledgment
 * recorded; the order can then complete while the balance stays owed.
 */
class ReleaseWithBalanceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    private Store $store;

    private JobOrder $job;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->owner->roles()->attach(Role::create(['name' => 'store_owner', 'description' => 'Owner']));
        $this->staff = User::factory()->create();
        $this->staff->roles()->attach(Role::create(['name' => 'staff', 'description' => 'Staff']));
        $customer = User::factory()->create();
        $this->store = Store::create(['owner_id' => $this->owner->id, 'name' => 'Release Shop', 'slug' => 'release-shop', 'address' => '1 St', 'city' => 'Davao', 'province' => 'Davao del Sur', 'status' => 'approved']);
        StaffProfile::create(['store_id' => $this->store->id, 'user_id' => $this->staff->id, 'role' => 'tailor']);
        $service = Service::create(['store_id' => $this->store->id, 'name' => 'Barong']);
        $this->job = JobOrder::create([
            'order_number' => 'ORD-0100', 'tracking_code' => 'RELEASE1', 'store_id' => $this->store->id, 'customer_id' => $customer->id,
            'service_id' => $service->id, 'status' => 'balance_handover', 'uses_confirmation_flow' => true,
            'total_amount' => 10000, 'balance' => 3000, 'payment_status' => 'partial', 'quantity' => 1,
        ]);
    }

    private function url(string $p): string
    {
        return "/api/v1/stores/{$this->store->id}/jobs/{$this->job->id}{$p}";
    }

    public function test_release_with_a_balance_needs_authority_recipient_due_date_and_acknowledgment(): void
    {
        $body = ['quantity' => 1, 'release_with_balance' => true, 'received_by' => 'Ana Reyes', 'pay_by' => now()->addDays(7)->toDateString()];
        $this->actingAs($this->staff)->postJson($this->url('/handovers'), $body + ['customer_acknowledged' => true])->assertForbidden();
        $this->actingAs($this->owner)->postJson($this->url('/handovers'), ['quantity' => 1])->assertStatus(422); // no release chosen
        $this->actingAs($this->owner)->postJson($this->url('/handovers'), $body)->assertStatus(422)->assertJsonValidationErrors(['customer_acknowledged']);

        $this->actingAs($this->owner)->postJson($this->url('/handovers'), $body + ['customer_acknowledged' => true])->assertCreated();
        $job = $this->job->fresh();
        $this->assertNotNull($job->released_with_balance_at);
        $this->assertSame($this->owner->id, $job->released_with_balance_by);
        $this->assertNotNull($job->balance_acknowledged_at);
        $this->assertSame(now()->addDays(7)->toDateString(), $job->balance_due_by?->toDateString());
    }

    public function test_an_authorized_release_lets_the_order_complete_while_the_balance_stays_owed(): void
    {
        $this->assertNotNull(\App\Support\OrderReadiness::transitionError($this->job, 'balance_handover', 'completed'), 'not released: cannot complete');
        $this->actingAs($this->owner)->postJson($this->url('/handovers'), ['quantity' => 1, 'release_with_balance' => true, 'received_by' => 'Ana', 'pay_by' => now()->addDay()->toDateString(), 'customer_acknowledged' => true])->assertCreated();
        $this->assertNull(\App\Support\OrderReadiness::transitionError($this->job->fresh(), 'balance_handover', 'completed'));
        $this->assertEquals(3000, (float) $this->job->fresh()->balance, 'Completed is not Financially Settled');
    }
}
