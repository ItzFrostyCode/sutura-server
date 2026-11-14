<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\OrderClaim;
use App\Models\Role;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\Store;
use App\Models\StoreBranch;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Report a problem": the after-sale claim flow, its customer API shape, the repair order and the refund rules. */
class OrderClaimFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $manager;

    private User $staff;

    private User $customer;

    private Store $store;

    private JobOrder $order;

    private StoreBranch $branch;

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
        $this->branch = StoreBranch::create(['store_id' => $this->store->id, 'name' => 'A', 'slug' => 'a', 'address' => 'A', 'city' => 'D', 'is_main' => true, 'status' => 'active', 'verification_status' => 'verified']);
        $member = function (string $role, bool $manager) use ($make) {
            $u = $make($role);
            StaffProfile::create(['store_id' => $this->store->id, 'user_id' => $u->id, 'store_branch_id' => $this->branch->id, 'role' => 'tailor', 'is_branch_manager' => $manager]);

            return $u;
        };
        $this->manager = $member('branch_manager', true);
        $this->staff = $member('staff', false);
        $this->customer = $make('customer');
        $this->store->customers()->syncWithoutDetaching([$this->customer->id => ['notes' => null]]);
        $this->order = $this->completedOrder(1000, 0);
    }

    private function completedOrder(float $total, float $balance, ?User $customer = null): JobOrder
    {
        $service = Service::firstOrCreate(['store_id' => $this->store->id, 'name' => 'Polo'], ['base_price' => 450, 'measurement_requirement' => 'none']);

        return JobOrder::create([
            'store_id' => $this->store->id, 'store_branch_id' => $this->branch->id, 'customer_id' => ($customer ?? $this->customer)->id, 'service_id' => $service->id,
            'order_number' => 'JO-'.random_int(100, 99999), 'total_amount' => $total, 'balance' => $balance, 'discount_amount' => 0,
            'status' => 'completed', 'payment_status' => 'paid', 'quantity' => 1,
        ]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'type' => 'repair', 'reason' => 'poor_stitching', 'description' => 'The seam on the left sleeve came apart.',
            'photos' => ['https://example.test/a.jpg', 'https://example.test/b.jpg'],
        ], $over);
    }

    private function file(?JobOrder $order = null, array $over = [])
    {
        return $this->actingAs($this->customer)->postJson('/api/v1/my-orders/'.($order ?? $this->order)->id.'/claims', $this->payload($over));
    }

    private function shop(User $as, string $method, string $path, array $data = [])
    {
        return $this->actingAs($as)->{$method.'Json'}("/api/v1/stores/{$this->store->id}$path", $data);
    }

    private function claim(string $type = 'repair'): OrderClaim
    {
        return OrderClaim::find($this->file(null, ['type' => $type])->assertCreated()->json('data.id'));
    }

    private function dueDate(): string
    {
        return now()->addDays(3)->toDateString();
    }

    public function test_completing_an_order_stamps_completed_at_and_leaving_it_clears_it(): void
    {
        $this->assertNotNull($this->order->fresh()->completed_at);
        $this->order->update(['status' => 'ready_for_pickup']);
        $this->assertNull($this->order->fresh()->completed_at);
        $this->order->update(['status' => 'completed']);
        $this->assertNotNull($this->order->fresh()->completed_at);
    }

    public function test_customer_files_a_claim_within_the_window_and_gets_the_shared_shape(): void
    {
        $res = $this->file()->assertCreated()->assertJsonPath('success', true);
        $expected = ['id', 'job_order_id', 'type', 'reason', 'description', 'photos', 'status', 'decline_reason', 'escalated_ticket_id', 'repair_offer',
            'decided_at', 'repair_order', 'garment_returned_at', 'refund_amount', 'refund_method', 'refund_reference', 'refund_proof_path',
            'refunded_at', 'resolved_at', 'created_at', 'filed_by', 'decided_by'];
        $this->assertEqualsCanonicalizing($expected, array_keys($res->json('data')));
        $this->assertSame('submitted', $res->json('data.status'));
        $this->assertCount(2, $res->json('data.photos'));

        $index = $this->actingAs($this->customer)->getJson("/api/v1/my-orders/{$this->order->id}/claims")->assertOk();
        $this->assertEqualsCanonicalizing(['can_file', 'window_ends_at', 'reason', 'window_days', 'paid_amount', 'claims'], array_keys($index->json('data')));
        $this->assertFalse($index->json('data.can_file'));
        $this->assertSame(3, $index->json('data.window_days'));
        $this->assertEquals(1000, $index->json('data.paid_amount'));
        $this->assertCount(1, $index->json('data.claims'));
    }

    public function test_the_owner_hears_about_a_new_claim(): void
    {
        $this->file()->assertCreated();
        foreach ([$this->owner, $this->manager] as $user) {
            $this->assertTrue($user->notifications->contains(fn ($n) => ($n->data['type'] ?? null) === 'claim_filed'));
        }
        $this->assertFalse($this->customer->notifications->contains(fn ($n) => ($n->data['type'] ?? null) === 'claim_filed'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'claim_filed', 'model_id' => $this->order->id]);
    }

    public function test_a_second_claim_in_the_same_window_is_blocked(): void
    {
        $this->file()->assertCreated();
        $this->file()->assertStatus(422);
        $this->assertSame(1, OrderClaim::count());
    }

    public function test_an_order_that_is_not_completed_cannot_be_claimed(): void
    {
        $this->order->update(['status' => 'sewing']);
        $this->file()->assertStatus(422);
    }

    public function test_a_claim_after_the_window_is_blocked(): void
    {
        $this->order->forceFill(['completed_at' => now()->subDays(4)])->save();
        $this->file()->assertStatus(422);
        $this->assertFalse($this->actingAs($this->customer)->getJson("/api/v1/my-orders/{$this->order->id}/claims")->json('data.can_file'));
    }

    public function test_photos_are_required_and_capped_at_five(): void
    {
        $this->file(null, ['photos' => []])->assertStatus(422);
        $this->file(null, ['photos' => array_fill(0, 6, 'https://example.test/x.jpg')])->assertStatus(422);
    }

    public function test_another_customer_gets_404(): void
    {
        $other = User::factory()->create();
        $other->roles()->attach(Role::where('name', 'customer')->first());
        $claim = $this->claim();
        $this->actingAs($other)->getJson("/api/v1/my-orders/{$this->order->id}/claims")->assertNotFound();
        $this->actingAs($other)->postJson("/api/v1/my-orders/{$this->order->id}/claims", $this->payload())->assertNotFound();
        $this->actingAs($other)->postJson("/api/v1/my-orders/{$this->order->id}/claims/{$claim->id}/respond", ['choice' => 'repair'])->assertNotFound();
        $this->actingAs($other)->postJson("/api/v1/my-orders/{$this->order->id}/claims/{$claim->id}/escalate", ['message' => 'This is unfair, please look.'])->assertNotFound();
    }

    public function test_accepting_a_repair_creates_a_free_fix_order_and_completing_it_resolves_the_claim(): void
    {
        $claim = $this->claim();
        $res = $this->shop($this->owner, 'post', "/claims/{$claim->id}/accept", ['due_date' => $this->dueDate(), 'assigned_staff_id' => $this->staff->id])->assertOk();
        $this->assertSame('repairing', $res->json('data.status'));

        $repair = JobOrder::findOrFail($claim->fresh()->repair_job_order_id);
        $this->assertSame($this->order->id, (int) $repair->follow_up_of_id);
        $this->assertSame('confirmed', $repair->status);
        $this->assertEquals(0, $repair->total_amount);
        $this->assertSame($claim->id, $repair->custom_order_data['warranty_claim_id']);
        $this->assertSame($this->order->order_number, $repair->custom_order_data['warranty_for_order']);
        $this->assertSame($this->dueDate(), $repair->due_date->toDateString());
        $this->assertSame(['https://example.test/a.jpg', 'https://example.test/b.jpg'], $repair->reference_images);
        $this->assertStringContainsString('Warranty repair for '.$this->order->order_number, $repair->notes);
        $this->assertSame([$this->staff->id], $repair->assignees()->pluck('user_id')->all());
        $this->assertNull($repair->completed_at);

        $this->travel(1)->minutes();
        $repair->update(['status' => 'completed']);

        $this->assertNotNull($repair->fresh()->completed_at);
        $this->assertSame('resolved', $claim->fresh()->status);
        $this->assertNotNull($claim->fresh()->resolved_at);
        // The repair opened a fresh claim window on the original order.
        $this->assertTrue($this->actingAs($this->customer)->getJson("/api/v1/my-orders/{$this->order->id}/claims")->json('data.can_file'));
    }

    public function test_a_repair_needs_a_due_date(): void
    {
        $claim = $this->claim();
        $this->shop($this->owner, 'post', "/claims/{$claim->id}/accept")->assertStatus(422);
        $this->assertSame('submitted', $claim->fresh()->status);
    }

    public function test_refund_path_accept_return_and_record(): void
    {
        $claim = $this->claim('refund');
        $this->shop($this->owner, 'post', "/claims/{$claim->id}/accept")->assertOk()->assertJsonPath('data.status', 'awaiting_return');

        $this->shop($this->owner, 'post', "/claims/{$claim->id}/refund", ['garment_returned' => true, 'amount' => 1500, 'method' => 'cash'])->assertStatus(422);
        $this->shop($this->owner, 'post', "/claims/{$claim->id}/refund", ['amount' => 500, 'method' => 'cash'])->assertStatus(422);
        $this->assertSame('awaiting_return', $claim->fresh()->status);

        $res = $this->shop($this->owner, 'post', "/claims/{$claim->id}/refund", ['garment_returned' => true, 'amount' => 800, 'method' => 'gcash', 'reference' => 'GC-123'])->assertOk();
        $this->assertSame('refunded', $res->json('data.status'));
        $this->assertEquals(800, $res->json('data.refund_amount'));
        $this->assertSame('refunded', $claim->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'claim_refunded']);
    }

    public function test_refund_is_limited_to_what_was_paid_after_a_balance_or_discount(): void
    {
        $order = $this->completedOrder(1000, 400);
        $claim = OrderClaim::find($this->file($order, ['type' => 'refund'])->assertCreated()->json('data.id'));
        $this->shop($this->owner, 'post', "/claims/{$claim->id}/accept")->assertOk();
        $this->shop($this->owner, 'post', "/claims/{$claim->id}/refund", ['garment_returned' => true, 'amount' => 601, 'method' => 'cash'])->assertStatus(422);
        $this->shop($this->owner, 'post', "/claims/{$claim->id}/refund", ['garment_returned' => true, 'amount' => 600, 'method' => 'cash'])->assertOk();
    }

    public function test_a_declined_customer_escalates_once_to_support(): void
    {
        $claim = $this->claim();
        $this->shop($this->owner, 'post', "/claims/{$claim->id}/decline", ['reason' => 'x'])->assertStatus(422);
        $this->shop($this->owner, 'post', "/claims/{$claim->id}/decline", ['reason' => 'Worn out through normal use.'])->assertOk()->assertJsonPath('data.status', 'declined');

        $url = "/api/v1/my-orders/{$this->order->id}/claims/{$claim->id}/escalate";
        $this->actingAs($this->customer)->postJson($url, ['message' => 'short'])->assertStatus(422);
        $res = $this->actingAs($this->customer)->postJson($url, ['message' => 'The stitching failed on day one, please review.'])->assertOk();
        $this->assertNotNull($res->json('data.escalated_ticket_id'));

        $ticket = SupportTicket::findOrFail($res->json('data.escalated_ticket_id'));
        $this->assertSame('problem', $ticket->type);
        $this->assertSame('open', $ticket->status);
        $this->assertSame($this->store->id, (int) $ticket->store_id);
        $this->assertSame($claim->photos, $ticket->attachments);

        $this->actingAs($this->customer)->postJson($url, ['message' => 'Sending this one more time please.'])->assertStatus(422);
        $this->assertSame(1, SupportTicket::count());
    }

    public function test_only_a_declined_claim_can_be_escalated(): void
    {
        $claim = $this->claim();
        $this->actingAs($this->customer)->postJson("/api/v1/my-orders/{$this->order->id}/claims/{$claim->id}/escalate", ['message' => 'Please look at this again.'])->assertStatus(422);
    }

    public function test_offering_a_repair_then_the_customer_keeps_the_refund(): void
    {
        $claim = $this->claim('refund');
        $this->shop($this->owner, 'post', "/claims/{$claim->id}/offer-repair", ['due_date' => $this->dueDate(), 'note' => 'We can fix the seam by Friday.'])
            ->assertOk()->assertJsonPath('data.status', 'repair_offered');
        $this->assertNotNull($claim->fresh()->repair_offered_at);
        // One offer only, and the shop cannot decide while it waits.
        $this->shop($this->owner, 'post', "/claims/{$claim->id}/offer-repair", ['due_date' => $this->dueDate()])->assertStatus(422);
        $this->shop($this->owner, 'post', "/claims/{$claim->id}/decline", ['reason' => 'Not covered.'])->assertStatus(422);

        $url = "/api/v1/my-orders/{$this->order->id}/claims/{$claim->id}/respond";
        $this->actingAs($this->customer)->postJson($url, ['choice' => 'nope'])->assertStatus(422);
        $this->actingAs($this->customer)->postJson($url, ['choice' => 'refund'])->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->assertNotNull($claim->fresh()->offer_declined_at);
        $this->shop($this->owner, 'post', "/claims/{$claim->id}/offer-repair", ['due_date' => $this->dueDate()])->assertStatus(422);
    }

    public function test_the_customer_can_take_an_offered_repair(): void
    {
        $claim = $this->claim('refund');
        $this->shop($this->owner, 'post', "/claims/{$claim->id}/offer-repair", ['due_date' => $this->dueDate()])->assertOk();
        $this->actingAs($this->customer)->postJson("/api/v1/my-orders/{$this->order->id}/claims/{$claim->id}/respond", ['choice' => 'repair'])
            ->assertOk()->assertJsonPath('data.status', 'repairing');
        $repair = JobOrder::findOrFail($claim->fresh()->repair_job_order_id);
        $this->assertSame($this->order->id, (int) $repair->follow_up_of_id);
        $this->assertSame('repair', $claim->fresh()->type);
    }

    public function test_plain_staff_get_no_money_fields_on_the_shop_payload(): void
    {
        $claim = $this->claim('refund');
        $this->shop($this->owner, 'post', "/claims/{$claim->id}/accept")->assertOk();
        $this->shop($this->owner, 'post', "/claims/{$claim->id}/refund", ['garment_returned' => true, 'amount' => 700, 'method' => 'cash'])->assertOk();

        $staff = $this->shop($this->staff, 'get', "/jobs/{$this->order->id}/claims")->assertOk();
        $this->assertNull($staff->json('data.paid_amount'));
        $this->assertNull($staff->json('data.claims.0.refund_amount'));
        $this->assertStringNotContainsString('700', $staff->getContent());

        $owner = $this->shop($this->owner, 'get', "/jobs/{$this->order->id}/claims")->assertOk();
        $this->assertEquals(1000, $owner->json('data.paid_amount'));
        $this->assertEquals(700, $owner->json('data.claims.0.refund_amount'));
    }

    public function test_staff_can_file_for_a_walk_in_but_cannot_decide(): void
    {
        $this->shop($this->staff, 'post', "/jobs/{$this->order->id}/claims", $this->payload(['type' => 'refund']))->assertCreated();
        $claim = OrderClaim::first();
        $this->shop($this->staff, 'post', "/claims/{$claim->id}/decline", ['reason' => 'No way.'])->assertForbidden();
        $this->assertSame('submitted', $claim->fresh()->status);
    }

    public function test_a_branch_manager_needs_the_owners_grant_to_decide(): void
    {
        $claim = $this->claim();
        $this->shop($this->manager, 'post', "/claims/{$claim->id}/decline", ['reason' => 'Not covered.'])
            ->assertForbidden()->assertJsonPath('code', 'permission_required');
    }

    public function test_a_claim_of_another_shop_is_not_found(): void
    {
        $claim = $this->claim();
        $otherOwner = User::factory()->create();
        $otherOwner->roles()->attach(Role::where('name', 'store_owner')->first());
        $other = Store::create(['owner_id' => $otherOwner->id, 'name' => 'T', 'slug' => 't', 'address' => '1', 'city' => 'D', 'province' => 'D', 'status' => 'approved']);
        $this->actingAs($otherOwner)->postJson("/api/v1/stores/{$other->id}/claims/{$claim->id}/decline", ['reason' => 'Not mine to decide.'])->assertNotFound();
    }

    public function test_the_owners_free_fix_button_still_builds_the_same_order(): void
    {
        $res = $this->shop($this->owner, 'post', "/jobs/{$this->order->id}/free-fix", ['description' => 'Hem came loose'])->assertCreated();
        $fix = JobOrder::findOrFail($res->json('data.id'));
        $this->assertSame($this->order->id, (int) $fix->follow_up_of_id);
        $this->assertSame('confirmed', $fix->status);
        $this->assertTrue($fix->uses_confirmation_flow);
        $this->assertEquals(0, $fix->total_amount);
        $this->assertTrue($fix->custom_order_data['free_fix']);
        $this->assertArrayNotHasKey('warranty_claim_id', $fix->custom_order_data);
        $this->assertNull($fix->due_date);
        $this->assertNotNull($fix->production_authorized_at);
        $this->assertSame("Free fix for {$this->order->order_number}: Hem came loose", $fix->notes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'job_order_free_fix', 'model_id' => $fix->id]);
    }
}
