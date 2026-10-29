<?php

namespace Tests\Feature\Api\V1;

use App\Models\JobOrder;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\Store;
use App\Models\User;
use App\Notifications\OrderUpdateNotification;
use App\Support\OrderProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Steps before production: price versions, the customer's decision, locked terms, authorization, progress. */
class PreProductionFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    private User $customer;

    private Store $store;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $ownerRole = Role::create(['name' => 'store_owner', 'description' => 'Owner']);
        $staffRole = Role::create(['name' => 'staff', 'description' => 'Staff']);
        $customerRole = Role::create(['name' => 'customer', 'description' => 'Customer']);

        $this->owner = User::factory()->create();
        $this->owner->roles()->attach($ownerRole);
        $this->store = Store::create([
            'owner_id' => $this->owner->id, 'name' => 'Test Store', 'slug' => 'test-store',
            'address' => '1 Test St', 'city' => 'Davao', 'province' => 'Davao del Sur', 'status' => 'approved',
        ]);
        $this->staff = User::factory()->create();
        $this->staff->roles()->attach($staffRole);
        StaffProfile::create(['store_id' => $this->store->id, 'user_id' => $this->staff->id, 'role' => 'tailor']);
        $this->customer = User::factory()->create();
        $this->customer->roles()->attach($customerRole);
        $this->service = Service::create(['store_id' => $this->store->id, 'name' => 'Bespoke Suit', 'base_duration_days' => 14]);
    }

    private function order(): JobOrder
    {
        $this->actingAs($this->owner)->postJson("/api/v1/stores/{$this->store->id}/jobs", [
            'customer_id' => $this->customer->id, 'service_id' => $this->service->id,
            'total_amount' => 0, 'balance' => 0, 'measurement_requirement' => 'none',
        ])->assertStatus(201);

        $job = JobOrder::latest('id')->firstOrFail();
        $job->forceFill(['specs_approved_at' => now(), 'measurement_requirement' => 'none', 'fitting_requirement' => 'none', 'payment_policy' => 'deposit', 'payment_policy_percent' => 50])->save();

        return $job->fresh();
    }

    private function quote(JobOrder $job, array $extra = [], string $status = 'final')
    {
        return $this->actingAs($this->owner)->putJson("/api/v1/stores/{$this->store->id}/jobs/{$job->id}/quotation", $extra + [
            'base_price' => 5000, 'due_date' => now()->addDays(10)->toDateString(), 'status' => $status,
        ]);
    }

    public function test_sending_a_price_records_a_version_and_tells_the_customer(): void
    {
        Notification::fake();
        $job = $this->order();

        $this->quote($job)->assertOk();

        $job->refresh();
        $this->assertSame('final', $job->quotation_status);
        $this->assertNotNull($job->quotation_sent_at);
        $this->assertCount(1, $job->quotationVersions);
        $this->assertSame(1, $job->quotationVersions->first()->version);
        Notification::assertSentTo($this->customer, OrderUpdateNotification::class, fn ($n) => $n->key === 'quotation_ready');
    }

    public function test_changing_a_sent_price_needs_a_reason_and_voids_the_earlier_answer(): void
    {
        $job = $this->order();
        $this->quote($job)->assertOk();
        $this->actingAs($this->customer)->postJson("/api/v1/my-orders/{$job->id}/decision", ['decision' => 'accept', 'accepted_terms' => true])->assertOk();
        $this->assertNotNull($job->fresh()->payment_terms_locked_at);

        $this->quote($job, ['base_price' => 6000])->assertStatus(422);
        $this->quote($job, ['base_price' => 6000, 'reason' => 'Added lining'])->assertOk();

        $job->refresh();
        $this->assertNull($job->customer_approved_at);
        $this->assertNull($job->payment_terms_locked_at);
        $this->assertCount(2, $job->quotationVersions);
    }

    public function test_changing_payment_terms_after_acceptance_needs_a_new_answer(): void
    {
        $job = $this->order();
        $this->quote($job)->assertOk();
        $this->actingAs($this->customer)->postJson("/api/v1/my-orders/{$job->id}/decision", ['decision' => 'accept', 'accepted_terms' => true])->assertOk();

        $this->quote($job, ['payment_policy' => 'full', 'reason' => 'Customer will pay in full'])->assertOk();

        $this->assertNull($job->fresh()->customer_approved_at);
        $this->assertSame('full', $job->fresh()->payment_policy);
    }

    public function test_pay_on_pickup_needs_a_reason(): void
    {
        $job = $this->order();
        $this->quote($job, ['payment_policy' => 'none'])->assertStatus(422);
        $this->quote($job, ['payment_policy' => 'none', 'reason' => 'Regular customer'])->assertOk();
    }

    public function test_customer_can_ask_for_changes_and_the_shop_answers_by_resending(): void
    {
        $job = $this->order();
        $this->quote($job)->assertOk();

        $this->actingAs($this->customer)->postJson("/api/v1/my-orders/{$job->id}/decision", ['decision' => 'changes'])->assertStatus(422);
        $this->actingAs($this->customer)->postJson("/api/v1/my-orders/{$job->id}/decision", ['decision' => 'changes', 'note' => 'Lower price please'])->assertOk();
        $this->assertSame('changes_requested', $job->fresh()->customer_decision);

        $this->quote($job, ['base_price' => 4500, 'reason' => 'Discount'])->assertOk();
        $this->assertNull($job->fresh()->customer_decision);
    }

    public function test_customer_can_decline_before_any_payment(): void
    {
        $job = $this->order();
        $this->quote($job)->assertOk();

        $this->actingAs($this->customer)->postJson("/api/v1/my-orders/{$job->id}/decision", ['decision' => 'decline'])->assertOk();

        $job->refresh();
        $this->assertSame('cancelled', $job->status);
        $this->assertSame('quotation_declined', $job->cancellation_reason);
        $this->assertSame('declined', OrderProgress::for($job)['closed']);
    }

    public function test_customer_cannot_send_payment_before_approving_the_price(): void
    {
        $job = $this->order();
        $this->quote($job)->assertOk();
        $method = PaymentMethod::create(['store_id' => $this->store->id, 'kind' => 'gcash', 'name' => 'GCash', 'account_name' => 'Shop', 'account_number' => '0917']);

        $this->actingAs($this->customer)->postJson("/api/v1/my-orders/{$job->id}/payments", [
            'payment_method_id' => $method->id, 'amount' => 100, 'receipt_path' => 'x.png',
        ])->assertStatus(422);
    }

    public function test_an_expired_price_cannot_be_accepted(): void
    {
        $job = $this->order();
        $this->quote($job)->assertOk();
        $job->forceFill(['quotation_valid_until' => now()->subDay()->toDateString()])->save();

        $this->actingAs($this->customer)->postJson("/api/v1/my-orders/{$job->id}/decision", ['decision' => 'accept', 'accepted_terms' => true])->assertStatus(422);
    }

    public function test_staff_cannot_confirm_or_cancel_and_a_closed_order_stays_closed(): void
    {
        $job = $this->order();

        $this->actingAs($this->staff)->putJson("/api/v1/stores/{$this->store->id}/jobs/{$job->id}", ['status' => 'confirmed'])->assertStatus(403);
        $this->actingAs($this->staff)->putJson("/api/v1/stores/{$this->store->id}/jobs/{$job->id}", ['status' => 'cancelled', 'cancellation_reason' => 'other'])->assertStatus(403);

        $this->actingAs($this->owner)->putJson("/api/v1/stores/{$this->store->id}/jobs/{$job->id}", ['status' => 'cancelled', 'cancellation_reason' => 'other'])->assertOk();
        $this->actingAs($this->owner)->putJson("/api/v1/stores/{$this->store->id}/jobs/{$job->id}", ['status' => 'confirmed'])->assertStatus(422);
    }

    public function test_confirming_records_the_authorization(): void
    {
        $job = $this->order();
        $job->forceFill(['payment_policy' => 'none'])->save();
        $this->quote($job)->assertOk();
        $this->actingAs($this->customer)->postJson("/api/v1/my-orders/{$job->id}/decision", ['decision' => 'accept', 'accepted_terms' => true])->assertOk();

        $this->actingAs($this->owner)->postJson("/api/v1/stores/{$this->store->id}/jobs/{$job->id}/confirm")->assertOk();

        $job->refresh();
        $this->assertSame('confirmed', $job->status);
        $this->assertSame($this->owner->id, (int) $job->production_authorized_by);
    }

    public function test_changing_a_tailor_after_work_started_is_recorded_with_a_reason(): void
    {
        $job = $this->order();
        $job->forceFill(['production_started_at' => now(), 'status' => 'sewing'])->save();
        $other = User::factory()->create();
        StaffProfile::create(['store_id' => $this->store->id, 'user_id' => $other->id, 'role' => 'tailor']);

        $url = "/api/v1/stores/{$this->store->id}/jobs/{$job->id}/assignees";
        $this->actingAs($this->owner)->putJson($url, ['assignees' => [['user_id' => $this->staff->id, 'role' => 'tailor']]])->assertOk();
        $this->actingAs($this->owner)->putJson($url, ['assignees' => [['user_id' => $other->id, 'role' => 'tailor']]])->assertStatus(422);
        $this->actingAs($this->owner)->putJson($url, ['assignees' => [['user_id' => $other->id, 'role' => 'tailor']], 'reason' => 'Sick leave'])->assertOk();

        $event = $job->assigneeEvents()->reorder('id', 'desc')->first();
        $this->assertSame('replaced', $event->action);
        $this->assertSame($this->staff->id, (int) $event->from_user_id);
        $this->assertSame($other->id, (int) $event->to_user_id);
        $this->assertSame('Sick leave', $event->reason);
    }

    public function test_progress_has_five_plain_stages_and_never_goes_backwards(): void
    {
        $job = $this->order();
        $job->forceFill(['shop_accepted_at' => now(), 'fitting_requirement' => 'optional'])->save();

        $expect = [
            'pending' => 'request', 'confirmed' => 'confirmed', 'awaiting_materials' => 'confirmed',
            'design' => 'making', 'pattern_making' => 'making', 'cutting' => 'making', 'sewing' => 'making', 'qc_ironing' => 'making',
            'ready_for_pickup' => 'ready', 'ready_for_fitting' => 'ready', 'balance_handover' => 'ready', 'completed' => 'done',
        ];
        foreach ($expect as $status => $stage) {
            $job->forceFill(['status' => $status])->save();
            $progress = OrderProgress::for($job->fresh());
            $this->assertCount(5, $progress['stages'], $status);
            $this->assertSame($stage, $progress['current'] ?? 'done', $status);
        }

        // A problem found at the final check stays inside "Being made".
        $job->forceFill(['status' => 'final_adjustments'])->save();
        $job->corrections()->create(['kind' => 'defect', 'description' => 'loose thread', 'status' => 'open']);
        $this->assertSame('making', OrderProgress::for($job->fresh())['current']);

        // An adjustment after the customer's own fitting stays inside "Ready".
        $job->corrections()->update(['status' => 'done']);
        $job->corrections()->create(['kind' => 'fitting_issue', 'description' => 'too long', 'status' => 'open']);
        $this->assertSame('ready', OrderProgress::for($job->fresh())['current']);
    }

    public function test_progress_for_a_guest_never_asks_them_to_act(): void
    {
        $job = $this->order();
        $this->quote($job)->assertOk();

        $this->assertSame('review_price', OrderProgress::for($job->fresh())['action']['key']);
        $this->assertNull(OrderProgress::for($job->fresh(), forGuest: true)['action']);

        $code = $job->fresh()->tracking_code;
        $this->getJson("/api/v1/track/{$code}")->assertOk()->assertJsonPath('data.progress.current', 'request');
    }

    public function test_online_order_request_waits_for_the_shop_and_starts_nothing(): void
    {
        $this->actingAs($this->customer)->postJson('/api/v1/stores/test-store/order-requests', [
            'service_id' => $this->service->id, 'garment_description' => 'A barong for my wedding in December',
        ])->assertStatus(201);

        $job = JobOrder::latest('id')->firstOrFail();
        $this->assertSame('pending', $job->status);
        $this->assertSame('online', $job->intake_channel);
        $this->assertNull($job->shop_accepted_at);

        $this->actingAs($this->owner)->postJson("/api/v1/stores/{$this->store->id}/jobs/{$job->id}/accept-request")->assertOk();
        $this->assertNotNull($job->fresh()->shop_accepted_at);
        $this->actingAs($this->owner)->postJson("/api/v1/stores/{$this->store->id}/jobs/{$job->id}/accept-request")->assertStatus(422);
    }

    public function test_a_part_that_was_already_paid_is_not_asked_for_again(): void
    {
        $job = $this->order();
        $this->quote($job)->assertOk();
        $this->actingAs($this->customer)->postJson("/api/v1/my-orders/{$job->id}/decision", ['decision' => 'accept', 'accepted_terms' => true])->assertOk();

        // The deposit is 50% of 5,000 = 2,500; 1,000 of it is in.
        $this->actingAs($this->owner)->postJson("/api/v1/stores/{$this->store->id}/jobs/{$job->id}/pay", ['amount' => 1000, 'payment_method' => 'cash', 'cash_tendered' => 1000])->assertStatus(200);

        $detail = $this->actingAs($this->customer)->getJson("/api/v1/my-orders/{$job->id}")->json('data.progress.detail');
        $this->assertStringContainsString('₱1,500.00 more', $detail, 'The customer is told what is left, not the whole deposit again');
        $this->assertStringContainsString('₱1,000.00 of ₱2,500.00 received', $detail);

        $note = collect($this->actingAs($this->owner)->getJson("/api/v1/stores/{$this->store->id}/jobs/{$job->id}/readiness")->json('data.pre_production.steps'))->firstWhere('key', 'payment')['note'] ?? '';
        $this->assertStringContainsString('₱1,000.00 of the ₱2,500.00 deposit is paid; ₱1,500.00 to go.', $note, 'The shop sees the same');
    }

    public function test_a_mis_clicked_details_approval_can_be_undone_until_the_customer_answers(): void
    {
        $job = $this->order();
        $base = "/api/v1/stores/{$this->store->id}/jobs/{$job->id}";
        $this->actingAs($this->owner)->postJson("$base/specs/approve")->assertOk();
        $this->assertNotNull($job->fresh()->specs_approved_at);

        $this->actingAs($this->owner)->deleteJson("$base/details-approval")->assertOk();
        $this->assertNull($job->fresh()->specs_approved_at);

        // After the customer accepted the price the details are what they agreed to.
        $this->actingAs($this->owner)->postJson("$base/specs/approve")->assertOk();
        $job->forceFill(['customer_approved_at' => now()])->save();
        $this->actingAs($this->owner)->deleteJson("$base/details-approval")->assertStatus(422);
        $this->assertNotNull($job->fresh()->specs_approved_at);

        // Plain staff cannot undo a sign-off.
        $this->actingAs($this->staff)->deleteJson("$base/details-approval")->assertForbidden();
    }

    public function test_an_order_made_by_mistake_is_cancelled_without_the_customer_and_without_telling_them(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $job = $this->order();
        $url = "/api/v1/stores/{$this->store->id}/jobs/{$job->id}/cancel";

        // Without the mistake reason the customer's agreement is still required.
        $this->actingAs($this->owner)->postJson($url, ['reason' => 'other'])->assertStatus(422);

        $this->actingAs($this->owner)->postJson($url, ['reason' => 'entered_by_mistake', 'note' => 'Wrong service'])->assertOk();
        $this->assertSame('cancelled', $job->fresh()->status);
        $this->assertSame('entered_by_mistake', $job->fresh()->cancellation_reason);
        \Illuminate\Support\Facades\Notification::assertNotSentTo($this->customer, \App\Notifications\JobStatusUpdatedNotification::class);
    }

    public function test_a_wrong_order_lets_go_of_its_consultation(): void
    {
        $job = $this->order();
        $visit = \App\Models\Appointment::create([
            'store_id' => $this->store->id, 'customer_id' => $this->customer->id, 'service_id' => $this->service->id,
            'scheduled_at' => now()->subHour(), 'status' => 'completed', 'job_order_id' => $job->id,
        ]);

        $this->actingAs($this->owner)->postJson("/api/v1/stores/{$this->store->id}/jobs/{$job->id}/cancel", ['reason' => 'entered_by_mistake'])->assertOk();
        $this->assertNull($visit->fresh()->job_order_id, 'The visit can be written up again');
    }

    public function test_made_by_mistake_is_refused_once_money_was_taken(): void
    {
        $job = $this->order();
        $job->forceFill(['total_amount' => 500, 'balance' => 250])->save();

        $this->actingAs($this->owner)->postJson("/api/v1/stores/{$this->store->id}/jobs/{$job->id}/cancel", ['reason' => 'entered_by_mistake'])->assertStatus(422);
        $this->assertNotSame('cancelled', $job->fresh()->status);
    }

    public function test_the_customer_is_told_when_the_shop_writes_up_their_order(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $job = $this->order();

        \Illuminate\Support\Facades\Notification::assertSentTo($this->customer, \App\Notifications\OrderUpdateNotification::class,
            fn ($n) => $n->key === 'created' && $n->jobOrder->id === $job->id && str_contains($n->message, $job->tracking_code));
    }
}
