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

/**
 * R1 lock-down: plain staff only work on production, branch accounts stay in their own branch, services are
 * read-only for branch managers, and a manager can never mint another manager.
 */
class RoleLockdownTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $manager;

    private User $staffA;

    private User $staffB;

    private User $customer;

    private Store $store;

    private StoreBranch $branchA;

    private StoreBranch $branchB;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['store_owner', 'branch_manager', 'staff', 'customer'] as $name) {
            Role::firstOrCreate(['name' => $name], ['description' => $name]);
        }

        $this->owner = $this->userWith('store_owner');
        $this->store = Store::create([
            'owner_id' => $this->owner->id, 'name' => 'Test Store', 'slug' => 'test-store',
            'address' => '1 Test St', 'city' => 'Davao', 'province' => 'Davao del Sur', 'status' => 'approved',
        ]);
        $this->branchA = StoreBranch::create(['store_id' => $this->store->id, 'name' => 'Branch A', 'address' => 'A St', 'city' => 'Davao City']);
        $this->branchB = StoreBranch::create(['store_id' => $this->store->id, 'name' => 'Branch B', 'address' => 'B St', 'city' => 'Davao City']);

        $this->manager = $this->member('branch_manager', $this->branchA, true);
        $this->staffA = $this->member('staff', $this->branchA);
        $this->staffB = $this->member('staff', $this->branchB);
        $this->customer = $this->userWith('customer');
        $this->service = Service::create(['store_id' => $this->store->id, 'name' => 'Bespoke Suit', 'base_duration_days' => 14]);
    }

    private function userWith(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role)->first());

        return $user;
    }

    private function member(string $role, StoreBranch $branch, bool $manager = false): User
    {
        $user = $this->userWith($role);
        StaffProfile::create([
            'store_id' => $this->store->id, 'user_id' => $user->id, 'store_branch_id' => $branch->id,
            'role' => $manager ? 'head_tailor' : 'tailor', 'is_branch_manager' => $manager,
        ]);

        return $user;
    }

    private function job(StoreBranch $branch, string $status = 'cutting'): JobOrder
    {
        return JobOrder::create([
            'store_id' => $this->store->id, 'store_branch_id' => $branch->id, 'customer_id' => $this->customer->id,
            'service_id' => $this->service->id, 'order_number' => 'JO-'.random_int(10000, 99999),
            'total_amount' => 5000, 'balance' => 5000, 'status' => $status,
        ]);
    }

    private function url(string $path): string
    {
        return "/api/v1/stores/{$this->store->id}{$path}";
    }

    public function test_staff_cannot_create_a_job_order(): void
    {
        $this->actingAs($this->staffA)->postJson($this->url('/jobs'), [
            'customer_id' => $this->customer->id, 'service_id' => $this->service->id, 'total_amount' => 0, 'balance' => 0,
        ])->assertStatus(403);

        $this->assertSame(0, JobOrder::count());
    }

    public function test_branch_manager_creates_orders_only_for_their_own_branch(): void
    {
        $body = ['customer_id' => $this->customer->id, 'service_id' => $this->service->id, 'total_amount' => 0, 'balance' => 0];

        $this->actingAs($this->manager)->postJson($this->url('/jobs'), $body + ['store_branch_id' => $this->branchB->id])->assertStatus(403);
        $this->actingAs($this->manager)->postJson($this->url('/jobs'), $body)->assertStatus(201);

        $this->assertSame($this->branchA->id, JobOrder::latest('id')->first()->store_branch_id);
    }

    public function test_staff_cannot_change_a_price_through_the_generic_update(): void
    {
        $job = $this->job($this->branchA);

        $this->actingAs($this->staffA)->putJson($this->url("/jobs/{$job->id}"), ['total_amount' => 1])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Staff can only update production.');

        $this->assertEquals(5000, $job->fresh()->total_amount);
    }

    public function test_staff_can_save_production_notes(): void
    {
        $job = $this->job($this->branchA);

        $this->actingAs($this->staffA)->putJson($this->url("/jobs/{$job->id}"), ['notes' => 'Collar done, sleeves next.'])->assertOk();

        $this->assertSame('Collar done, sleeves next.', $job->fresh()->notes);
    }

    public function test_staff_cannot_move_an_order_back_to_a_set_up_or_closed_status(): void
    {
        $job = $this->job($this->branchA);

        foreach (['confirmed', 'pending', 'rejected'] as $status) {
            $this->actingAs($this->staffA)->putJson($this->url("/jobs/{$job->id}"), ['status' => $status])->assertStatus(403);
        }
        $this->assertSame('cutting', $job->fresh()->status);
    }

    public function test_staff_cannot_use_the_set_up_sign_offs(): void
    {
        $job = $this->job($this->branchA, 'pending');

        foreach (['/specs/approve', '/measurements/verify', '/quotation/approve', '/materials', '/customer-items'] as $path) {
            $this->actingAs($this->staffA)->postJson($this->url("/jobs/{$job->id}{$path}"), [])->assertStatus(403);
        }
    }

    public function test_staff_can_move_an_order_of_their_own_branch_with_production_next(): void
    {
        $job = $this->job($this->branchA, 'cutting');

        $this->actingAs($this->staffA)->postJson($this->url("/jobs/{$job->id}/production/next"))->assertOk();

        $this->assertSame('sewing', $job->fresh()->status);
    }

    public function test_staff_cannot_touch_another_branchs_order(): void
    {
        $job = $this->job($this->branchB, 'cutting');

        $this->actingAs($this->staffA)->getJson($this->url("/jobs/{$job->id}"))->assertStatus(403);
        $this->actingAs($this->staffA)->putJson($this->url("/jobs/{$job->id}"), ['notes' => 'sneaky'])->assertStatus(403);
        $this->actingAs($this->staffA)->postJson($this->url("/jobs/{$job->id}/production/next"))->assertStatus(403);

        $this->assertSame('cutting', $job->fresh()->status);
        $this->assertNull($job->fresh()->notes);
    }

    public function test_branch_manager_cannot_read_another_branchs_order(): void
    {
        $other = $this->job($this->branchB);
        $mine = $this->job($this->branchA);

        $this->actingAs($this->manager)->getJson($this->url("/jobs/{$other->id}"))->assertStatus(403);
        $this->actingAs($this->manager)->getJson($this->url("/jobs/{$mine->id}"))->assertOk();

        // A branch_id in the query never widens the list.
        $list = $this->actingAs($this->manager)->getJson($this->url('/jobs?branch_id='.$this->branchB->id));
        $list->assertOk();
        $this->assertStringNotContainsString($other->order_number, $list->getContent());
        $this->assertStringContainsString($mine->order_number, $list->getContent());
    }

    public function test_branch_manager_cannot_write_services_or_designs_but_can_read_them(): void
    {
        $payload = ['name' => 'New Service', 'service_category' => 'custom_tailoring'];

        $this->actingAs($this->manager)->postJson($this->url('/services'), $payload)->assertStatus(403);
        $this->actingAs($this->manager)->putJson($this->url("/services/{$this->service->id}"), $payload)->assertStatus(403);
        $this->actingAs($this->manager)->deleteJson($this->url("/services/{$this->service->id}"))->assertStatus(403);
        $this->actingAs($this->manager)->postJson($this->url('/catalog'), ['name' => 'Design'])->assertStatus(403);

        $this->actingAs($this->manager)->getJson($this->url('/services'))->assertOk();
        $this->assertNotNull($this->service->fresh());
    }

    public function test_branch_manager_cannot_create_a_branch_manager(): void
    {
        $body = ['name' => 'New Hire', 'email' => 'hire@example.com', 'password' => 'secret123', 'role' => 'tailor'];

        $this->actingAs($this->manager)->postJson($this->url('/staff'), $body + ['is_branch_manager' => true])->assertStatus(403);
        $this->assertNull(User::where('email', 'hire@example.com')->first());

        // Even without the flag, a submitted other branch is refused.
        $this->actingAs($this->manager)->postJson($this->url('/staff'), $body + ['store_branch_id' => $this->branchB->id])->assertStatus(403);
    }

    public function test_branch_manager_cannot_promote_or_move_existing_staff(): void
    {
        $profile = StaffProfile::where('user_id', $this->staffA->id)->first();

        $this->actingAs($this->manager)->putJson($this->url("/staff/{$profile->id}"), ['is_branch_manager' => true])->assertStatus(403);
        $this->actingAs($this->manager)->putJson($this->url("/staff/{$profile->id}"), ['store_branch_id' => $this->branchB->id])->assertStatus(403);

        $this->assertFalse((bool) $profile->fresh()->is_branch_manager);
        $this->assertTrue($this->staffA->fresh()->hasRole('staff'));
        $this->assertFalse($this->staffA->fresh()->hasRole('branch_manager'));
    }

    public function test_public_sign_up_only_ever_makes_a_customer(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Cara Customer', 'email' => 'cara@example.com', 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!', 'role' => 'store_owner',
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Cara Customer', 'email' => 'cara@example.com', 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!', 'role' => 'customer',
        ])->assertStatus(201);

        $user = User::where('email', 'cara@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('customer'));
        $this->assertFalse($user->hasRole('store_owner'));
    }
}
