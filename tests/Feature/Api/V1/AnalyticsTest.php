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
}
