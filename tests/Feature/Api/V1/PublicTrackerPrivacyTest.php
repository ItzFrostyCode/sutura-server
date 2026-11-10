<?php

namespace Tests\Feature\Api\V1;

use App\Models\JobOrder;
use App\Models\Role;
use App\Models\Service;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DSD-02 WF-8 / WD-11 / WD-20 (B5-O4, B8-O06): the public tracker answers with the customer-visible stage and the date
 * only, and only for an order authorized for production. Anything else looks like an unknown code.
 */
class PublicTrackerPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private JobOrder $job;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create();
        $owner->roles()->attach(Role::create(['name' => 'store_owner', 'description' => 'Owner']));
        $customer = User::factory()->create(['name' => 'Secret Customer']);
        $store = Store::create(['owner_id' => $owner->id, 'name' => 'Track Shop', 'slug' => 'track-shop', 'address' => '1 St', 'city' => 'Davao', 'province' => 'Davao del Sur', 'status' => 'approved']);
        $service = Service::create(['store_id' => $store->id, 'name' => 'Barong']);
        $this->job = JobOrder::create([
            'order_number' => 'ORD-9001', 'tracking_code' => 'ABCD-1234', 'store_id' => $store->id, 'customer_id' => $customer->id,
            'service_id' => $service->id, 'status' => 'pending', 'total_amount' => 9876.5, 'balance' => 4321, 'payment_status' => 'partial',
            'custom_order_data' => ['repair_note' => 'private note'],
        ]);
    }

    public function test_a_preliminary_order_answers_like_an_unknown_code(): void
    {
        $pending = $this->getJson('/api/v1/track/ABCD-1234')->assertNotFound();
        $unknown = $this->getJson('/api/v1/track/ZZZZ-9999')->assertNotFound();
        $this->assertSame($unknown->json(), $pending->json());
        $this->assertStringNotContainsString('ZZZZ', $unknown->getContent(), 'the sent code is never echoed');
    }

    public function test_an_authorized_order_shows_only_the_stage_and_the_date(): void
    {
        $this->job->update(['status' => 'sewing']);
        $r = $this->getJson('/api/v1/track/abcd1234')->assertOk();
        $this->assertSame(['stage', 'updated_on'], array_keys($r->json('data')));
        $this->assertSame('In Production · Stitching', $r->json('data.stage'));
        foreach (['9876', '4321', 'partial', 'private note', 'Secret Customer', 'ORD-9001'] as $leak) {
            $this->assertStringNotContainsString($leak, $r->getContent());
        }
    }

    public function test_stage_names_follow_the_approved_customer_milestones(): void
    {
        foreach (['confirmed' => 'Accepted', 'cutting' => 'In Production · Cutting', 'qc_ironing' => 'Quality Check', 'ready_for_fitting' => 'Customer Fitting',
            'final_adjustments' => 'Adjustment', 'ready_for_pickup' => 'Ready for Customer', 'completed' => 'Completed'] as $status => $label) {
            $this->job->update(['status' => $status]);
            $this->getJson('/api/v1/track/ABCD-1234')->assertOk()->assertJsonPath('data.stage', $label);
        }
        foreach (['cancelled', 'rejected'] as $closed) {
            $this->job->update(['status' => $closed]);
            $this->getJson('/api/v1/track/ABCD-1234')->assertNotFound();
        }
    }
}
