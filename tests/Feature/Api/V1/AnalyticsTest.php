<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Models\Shop;
use App\Models\ShopBranch;
use App\Models\Service;
use App\Models\JobOrder;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Shop $shop;
    protected User $customer;
    protected Service $service;
    protected ShopBranch $branchA;
    protected ShopBranch $branchB;

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
            'city' => 'Davao',
            'province' => 'Davao del Sur',
            'status' => 'approved',
        ]);

        $this->customer = User::factory()->create();
        $this->service = Service::create(['shop_id' => $this->shop->id, 'name' => 'Bespoke Suit']);

        $this->branchA = ShopBranch::create(['shop_id' => $this->shop->id, 'name' => 'Branch A', 'address' => 'A St', 'city' => 'Davao']);
        $this->branchB = ShopBranch::create(['shop_id' => $this->shop->id, 'name' => 'Branch B', 'address' => 'B St', 'city' => 'Davao']);
    }

    private function makeOverdueJob(?int $branchId): JobOrder
    {
        return JobOrder::create([
            'order_number'   => 'JO-' . Str::random(10),
            'shop_id'        => $this->shop->id,
            'shop_branch_id' => $branchId,
            'customer_id'    => $this->customer->id,
            'service_id'     => $this->service->id,
            'status'         => 'pending',
            'payment_status' => 'unpaid',
            'due_date'       => now()->subDay()->toDateString(),
            'total_amount'   => 1000,
            'balance'        => 1000,
        ]);
    }

    public function test_triage_kpis_respect_the_branch_filter(): void
    {
        $this->makeOverdueJob($this->branchA->id);
        $this->makeOverdueJob($this->branchB->id);

        // No branch filter → both overdue/unpaid jobs are counted shop-wide.
        $this->actingAs($this->user)
            ->getJson("/api/v1/shops/{$this->shop->id}/analytics")
            ->assertStatus(200)
            ->assertJsonPath('data.overdue_jobs', 2)
            ->assertJsonPath('data.pending_deposit_jobs', 2);

        // Branch A filter → only Branch A's job. (Previously these KPIs ignored branch_id.)
        $this->actingAs($this->user)
            ->getJson("/api/v1/shops/{$this->shop->id}/analytics?branch_id={$this->branchA->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.overdue_jobs', 1)
            ->assertJsonPath('data.pending_deposit_jobs', 1);
    }

    public function test_branch_comparison_and_kpis_report_rejected_and_forfeited_amounts(): void
    {
        // Branch A: a completed job with a payment later rejected as fraudulent.
        $completedJob = JobOrder::create([
            'order_number' => 'JO-' . Str::random(10),
            'shop_id' => $this->shop->id,
            'shop_branch_id' => $this->branchA->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'status' => 'completed',
            'payment_status' => 'unpaid',
            'total_amount' => 5000,
            'balance' => 5000,
        ]);
        $completedJob->payments()->create([
            'amount' => 5000,
            'payment_method' => 'gcash',
            'rejected_at' => now(),
            'rejected_reason' => 'Fake receipt',
            'rejected_by' => $this->user->id,
        ]);

        // Branch B: a cancelled job with a forfeited deposit already collected.
        $forfeitedJob = JobOrder::create([
            'order_number' => 'JO-' . Str::random(10),
            'shop_id' => $this->shop->id,
            'shop_branch_id' => $this->branchB->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'status' => 'cancelled',
            'cancellation_reason' => 'forfeited_deposit_abandoned',
            'payment_status' => 'partial',
            'total_amount' => 8000,
            'balance' => 5000,
        ]);
        $forfeitedJob->payments()->create([
            'amount' => 3000,
            'payment_method' => 'cash',
        ]);

        $indexResponse = $this->actingAs($this->user)
            ->getJson("/api/v1/shops/{$this->shop->id}/analytics?branch_id={$this->branchA->id}");

        // assertJsonPath() compares with assertSame(): PHP's json_encode()
        // drops the trailing .0 on whole-number floats (5000.0 -> "5000" in
        // the wire payload), so json_decode() hands it back as an int and
        // trips the strict type check even though the computed amount is
        // correct. assertEquals() (loose) checks the same value without
        // that false-negative.
        $indexResponse->assertStatus(200);
        $this->assertEquals(5000.0, $indexResponse->json('data.rejected_payments_amount'));
        $this->assertEquals(0.0, $indexResponse->json('data.forfeited_deposit_amount'));

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/shops/{$this->shop->id}/analytics/branches");

        $response->assertStatus(200);
        $rows = collect($response->json('data'));
        $branchARow = $rows->firstWhere('branch_id', $this->branchA->id);
        $branchBRow = $rows->firstWhere('branch_id', $this->branchB->id);

        $this->assertEquals(5000.0, $branchARow['rejected_payments_amount']);
        $this->assertEquals(3000.0, $branchBRow['forfeited_deposit_amount']);
    }
}
