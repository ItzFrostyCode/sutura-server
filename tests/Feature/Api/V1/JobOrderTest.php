<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Models\Shop;
use App\Models\Service;
use App\Models\Measurement;
use App\Models\StaffProfile;
use App\Models\Role;
use App\Models\ShopBranch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class JobOrderTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Shop $shop;
    protected User $customer;
    protected Service $service;
    protected StaffProfile $staffProfile;
    protected Measurement $measurement;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'shop_owner', 'description' => 'Shop Owner']);
        $this->user = User::factory()->create();
        $this->user->roles()->attach($role);
        
        $this->shop = Shop::create([
            'owner_id' => $this->user->id,
            'name' => 'Test Shop',
            'slug' => 'test-shop',
            'address' => '123 Test St',
            'city' => 'Manila',
            'province' => 'Metro Manila',
            'status' => 'approved'
        ]);
        
        $this->customer = User::factory()->create();
        $this->customer->roles()->attach($role);
        
        $this->service = Service::create([
            'shop_id' => $this->shop->id,
            'name' => 'Bespoke Suit',
            'base_duration_days' => 14
        ]);
        
        $this->staffProfile = StaffProfile::create([
            'shop_id' => $this->shop->id,
            'user_id' => $this->user->id,
            'role' => 'tailor'
        ]);

        $this->measurement = Measurement::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'profile_name' => 'Default',
            'metrics' => ['chest' => 40]
        ]);
    }

    public function test_can_create_job_order()
    {
        $response = $this->actingAs($this->user)->postJson("/api/v1/shops/{$this->shop->id}/jobs", [
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'assigned_staff_id' => $this->staffProfile->id,
            'measurement_id' => $this->measurement->id,
            'total_amount' => 5000,
            'balance' => 2500,
            'status' => 'pending'
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('success', true);

        $this->assertDatabaseHas('job_orders', [
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'total_amount' => 5000,
        ]);
    }

    public function test_can_create_service_with_custom_fields_and_job_order_with_custom_data()
    {
        // 1. Create a service with custom_fields
        $customFields = [
            ['id' => 'f1', 'label' => 'Name on Jersey', 'type' => 'text', 'required' => true],
            ['id' => 'f2', 'label' => 'Number on Jersey', 'type' => 'number', 'required' => false]
        ];

        $serviceResponse = $this->actingAs($this->user)->postJson("/api/v1/shops/{$this->shop->id}/services", [
            'name' => 'Custom Jersey Set',
            'base_price' => 1200,
            'estimated_days' => 10,
            'custom_fields' => $customFields,
            // Required — the real Create Service form disables submission
            // until at least one pricing tier row exists (its amount, not
            // the tier itself, is what's allowed to be left blank/optional).
            'pricing_tiers' => [
                ['label' => 'Standard', 'amount' => 1200],
            ],
        ]);

        $serviceId = $serviceResponse->json('data.id');

        $this->assertDatabaseHas('services', [
            'id' => $serviceId,
            'name' => 'Custom Jersey Set'
        ]);

        // Verify custom_fields is stored
        $service = Service::find($serviceId);
        $this->assertEquals($customFields, $service->custom_fields);
        // Regression: tags must persist (previously dropped — missing from $fillable/$casts).
        // ServiceController@store deliberately derives tags from the pricing
        // tier labels (not a client-supplied value — StoreServiceRequest
        // doesn't even validate a "tags" input) so service cards and the
        // job-order service picker keep working without joining pricing on
        // every read.
        $this->assertEquals(['Standard'], $service->tags);

        // 2. Create job order with custom_order_data
        $customOrderData = [
            'Name on Jersey' => 'Frosty',
            'Number on Jersey' => '7'
        ];

        $jobResponse = $this->actingAs($this->user)->postJson("/api/v1/shops/{$this->shop->id}/jobs", [
            'customer_id' => $this->customer->id,
            'service_id' => $serviceId,
            'total_amount' => 1200,
            'balance' => 1200,
            'custom_order_data' => $customOrderData
        ]);

        $jobResponse->assertStatus(201);
        $jobId = $jobResponse->json('data.id');

        $this->assertDatabaseHas('job_orders', [
            'id' => $jobId,
            'total_amount' => 1200
        ]);

        // Verify custom_order_data is stored and retrieved
        $jobOrder = \App\Models\JobOrder::find($jobId);
        $this->assertEquals($customOrderData, $jobOrder->custom_order_data);
    }

    public function test_cancelling_job_order_requires_a_reason()
    {
        $jobOrder = \App\Models\JobOrder::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'order_number' => 'JO-2026-9001',
            'total_amount' => 5000,
            'balance' => 5000,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)->putJson("/api/v1/shops/{$this->shop->id}/jobs/{$jobOrder->id}", [
            'status' => 'cancelled',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors('cancellation_reason');
    }

    public function test_cancelling_job_order_with_forfeited_deposit_reason_persists()
    {
        $jobOrder = \App\Models\JobOrder::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'order_number' => 'JO-2026-9002',
            'total_amount' => 5000,
            'balance' => 2500,
            'payment_status' => 'partial',
            'status' => 'cutting',
        ]);

        $response = $this->actingAs($this->user)->putJson("/api/v1/shops/{$this->shop->id}/jobs/{$jobOrder->id}", [
            'status' => 'cancelled',
            'cancellation_reason' => 'forfeited_deposit_abandoned',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('job_orders', [
            'id' => $jobOrder->id,
            'status' => 'cancelled',
            'cancellation_reason' => 'forfeited_deposit_abandoned',
        ]);
    }

    public function test_owner_can_reject_a_payment_and_balance_is_reversed()
    {
        $jobOrder = \App\Models\JobOrder::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'order_number' => 'JO-2026-9010',
            'total_amount' => 5000,
            'balance' => 3000,
            'payment_status' => 'partial',
            'status' => 'cutting',
        ]);

        $payment = $jobOrder->payments()->create([
            'amount' => 2000,
            'payment_method' => 'gcash',
            'recorded_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/shops/{$this->shop->id}/jobs/{$jobOrder->id}/payments/{$payment->id}/reject",
            ['reason' => 'GCash reference number does not match our transaction history.']
        );

        $response->assertStatus(200)->assertJsonPath('success', true);

        $this->assertDatabaseHas('job_orders', [
            'id' => $jobOrder->id,
            'balance' => 5000.00,
            'payment_status' => 'unpaid',
        ]);

        $payment->refresh();
        $this->assertNotNull($payment->rejected_at);
        $this->assertEquals('GCash reference number does not match our transaction history.', $payment->rejected_reason);
        $this->assertEquals($this->user->id, $payment->rejected_by);
    }

    public function test_rejecting_a_payment_on_an_already_completed_job_still_reverses_balance()
    {
        $jobOrder = \App\Models\JobOrder::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'order_number' => 'JO-2026-9011',
            'total_amount' => 5000,
            'balance' => 0,
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);

        $payment = $jobOrder->payments()->create([
            'amount' => 5000,
            'payment_method' => 'bank_transfer',
            'recorded_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/shops/{$this->shop->id}/jobs/{$jobOrder->id}/payments/{$payment->id}/reject",
            ['reason' => 'Bank transfer receipt was a reused screenshot from a different customer.']
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('job_orders', [
            'id' => $jobOrder->id,
            'status' => 'completed',
            'balance' => 5000.00,
            'payment_status' => 'unpaid',
        ]);
    }

    public function test_cannot_reject_an_already_rejected_payment()
    {
        $jobOrder = \App\Models\JobOrder::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'order_number' => 'JO-2026-9012',
            'total_amount' => 5000,
            'balance' => 5000,
            'status' => 'pending',
        ]);

        $payment = $jobOrder->payments()->create([
            'amount' => 1000,
            'payment_method' => 'cash',
            'recorded_by' => $this->user->id,
            'rejected_at' => now(),
            'rejected_reason' => 'Already flagged once.',
            'rejected_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/shops/{$this->shop->id}/jobs/{$jobOrder->id}/payments/{$payment->id}/reject",
            ['reason' => 'Trying again.']
        );

        $response->assertStatus(400);
    }

    public function test_staff_cannot_reject_a_payment()
    {
        $staffRole = Role::firstOrCreate(['name' => 'staff'], ['description' => 'Staff']);
        $staffUser = User::factory()->create();
        $staffUser->roles()->attach($staffRole);
        StaffProfile::create([
            'shop_id' => $this->shop->id,
            'user_id' => $staffUser->id,
            'role' => 'tailor',
        ]);

        $jobOrder = \App\Models\JobOrder::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'order_number' => 'JO-2026-9013',
            'total_amount' => 5000,
            'balance' => 3000,
            'status' => 'cutting',
        ]);
        $payment = $jobOrder->payments()->create([
            'amount' => 2000,
            'payment_method' => 'cash',
            'recorded_by' => $this->user->id,
        ]);

        $response = $this->actingAs($staffUser)->postJson(
            "/api/v1/shops/{$this->shop->id}/jobs/{$jobOrder->id}/payments/{$payment->id}/reject",
            ['reason' => 'test']
        );

        $response->assertStatus(403);
    }

    public function test_rejecting_a_payment_writes_an_audit_log_entry()
    {
        $jobOrder = \App\Models\JobOrder::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'order_number' => 'JO-2026-9014',
            'total_amount' => 5000,
            'balance' => 3000,
            'status' => 'cutting',
        ]);
        $payment = $jobOrder->payments()->create([
            'amount' => 2000,
            'payment_method' => 'cash',
            'recorded_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)->postJson(
            "/api/v1/shops/{$this->shop->id}/jobs/{$jobOrder->id}/payments/{$payment->id}/reject",
            ['reason' => 'Fake receipt.']
        );

        $this->assertDatabaseHas('audit_logs', [
            'shop_id' => $this->shop->id,
            'user_id' => $this->user->id,
            'action' => 'payment_rejected',
            'model_type' => \App\Models\Payment::class,
            'model_id' => $payment->id,
        ]);
    }

    public function test_branch_manager_rejecting_a_payment_notifies_the_shop_owner()
    {
        Notification::fake();

        $branch = ShopBranch::create([
            'shop_id' => $this->shop->id,
            'name' => 'Matina Branch',
            'address' => 'Matina, Davao City',
            'city' => 'Davao City',
        ]);
        $managerRole = Role::firstOrCreate(['name' => 'branch_manager'], ['description' => 'Branch Manager']);
        $manager = User::factory()->create();
        $manager->roles()->attach($managerRole);
        StaffProfile::create([
            'shop_id' => $this->shop->id,
            'shop_branch_id' => $branch->id,
            'user_id' => $manager->id,
            'role' => 'head_tailor',
            'is_branch_manager' => true,
        ]);

        $jobOrder = \App\Models\JobOrder::create([
            'shop_id' => $this->shop->id,
            'shop_branch_id' => $branch->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'order_number' => 'JO-2026-9015',
            'total_amount' => 5000,
            'balance' => 3000,
            'status' => 'cutting',
        ]);
        $payment = $jobOrder->payments()->create([
            'amount' => 2000,
            'payment_method' => 'cash',
            'recorded_by' => $manager->id,
        ]);

        $response = $this->actingAs($manager)->postJson(
            "/api/v1/shops/{$this->shop->id}/jobs/{$jobOrder->id}/payments/{$payment->id}/reject",
            ['reason' => 'Cash count came up short at closing.']
        );

        $response->assertStatus(200);
        Notification::assertSentTo($this->user, \App\Notifications\PaymentRejectedNotification::class);
    }
}
