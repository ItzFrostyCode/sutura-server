<?php

namespace Tests\Feature\Api\V1;

use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\Store;
use App\Models\StoreBranch;
use App\Models\StoreSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The owner makes branches and their managers; staff are added to the branch the owner runs. */
class BranchManagerFlowTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): array
    {
        foreach (['store_owner', 'branch_manager', 'staff', 'customer'] as $r) {
            Role::firstOrCreate(['name' => $r], ['description' => $r]);
        }
        $owner = User::factory()->create();
        $owner->roles()->attach(Role::where('name', 'store_owner')->first());
        $store = Store::create(['owner_id' => $owner->id, 'name' => 'Test Store', 'slug' => 'test-store', 'address' => '1 St', 'city' => 'Davao', 'province' => 'Davao del Sur', 'status' => 'approved']);

        return [$owner, $store];
    }

    /** An unlimited-plan subscription so the staff-count limit never interferes with the flows under test. */
    private function unlimitedPlan(Store $store): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Premium', 'slug' => 'premium-bmf', 'price_monthly' => 0,
            'max_staff' => -1, 'max_branches' => -1, 'is_active' => true,
        ]);
        StoreSubscription::create(['store_id' => $store->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDay()]);
    }

    private function branchPayload(array $extra = []): array
    {
        return $extra + ['name' => 'Main', 'address' => '1 Test St', 'city' => 'Davao City', 'latitude' => 7.07, 'longitude' => 125.6];
    }

    public function test_owner_creates_a_branch_with_its_manager_login_and_the_manager_can_log_in_as_manager(): void
    {
        [$owner, $store] = $this->owner();

        $this->actingAs($owner)->postJson("/api/v1/stores/{$store->id}/branches", $this->branchPayload([
            'manager' => ['name' => 'Mia Manager', 'email' => 'mia@example.com', 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!'],
        ]))->assertOk();

        $manager = User::where('email', 'mia@example.com')->firstOrFail();
        $this->assertTrue($manager->hasRole('branch_manager'));
        $profile = StaffProfile::where('user_id', $manager->id)->firstOrFail();
        $this->assertTrue((bool) $profile->is_branch_manager);
        $this->assertSame(StoreBranch::where('store_id', $store->id)->value('id'), $profile->store_branch_id);
    }

    public function test_owner_adds_staff_to_the_branch_they_run_even_after_appointing_a_manager(): void
    {
        [$owner, $store] = $this->owner();
        $this->unlimitedPlan($store);
        $main = StoreBranch::create($this->branchPayload(['store_id' => $store->id, 'name' => 'Main', 'slug' => 'b-main', 'is_main' => true, 'status' => 'active', 'verification_status' => 'verified']));
        $staff = ['name' => 'Sam Staff', 'email' => 'sam@example.com', 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!', 'role' => 'tailor'];

        // One branch auto-selects, so the owner's first staff lands on it.
        $this->actingAs($owner)->postJson("/api/v1/stores/{$store->id}/staff", $staff)->assertStatus(201);
        $this->assertSame($main->id, StaffProfile::where(['store_id' => $store->id])->value('store_branch_id'));

        // Appointing a manager for that branch does not restrict the owner.
        $this->actingAs($owner)->putJson("/api/v1/stores/{$store->id}/branches/{$main->id}", $this->branchPayload([
            'manager' => ['name' => 'Mia Manager', 'email' => 'mia@example.com', 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!'],
        ]))->assertOk();

        // The owner still adds a second staff member to the same branch; only a branch MANAGER is
        // confined to their own branch (that restriction is asserted in RoleLockdownTest).
        $this->actingAs($owner)->postJson("/api/v1/stores/{$store->id}/staff", ['email' => 'sue@example.com', 'name' => 'Sue'] + $staff)->assertStatus(201);
    }

    public function test_store_page_edits_are_owner_only_even_for_the_branch_manager(): void
    {
        // 148aeb2 moved PUT /stores/{store} out of the owner+manager group into "Store Management
        // (Owner Only)" — the shop page is the store's identity, a bigger decision than a branch's
        // day-to-day info (branch managers still edit their own branch via PUT /branches/{branch}).
        [$owner, $store] = $this->owner();
        $this->actingAs($owner)->postJson("/api/v1/stores/{$store->id}/branches", $this->branchPayload([
            'manager' => ['name' => 'Mia Manager', 'email' => 'mia@example.com', 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!'],
        ]))->assertOk();
        $manager = User::where('email', 'mia@example.com')->firstOrFail();
        // Owner-created managers start with must_change_password=true (the forced first-login change), which
        // CheckRole blocks every store-scoped route behind. Represent the manager having completed that step so
        // the 403 below is the role guard, not the forced-change lock. (must_change_password is
        // not fillable, so it is set on the instance directly, not via update()).
        $manager->must_change_password = false;

        $this->actingAs($owner)->putJson("/api/v1/stores/{$store->id}", ['description' => 'Made by the owner'])->assertOk();
        $this->assertSame('Made by the owner', $store->fresh()->description);

        $this->actingAs($manager)->putJson("/api/v1/stores/{$store->id}", ['description' => 'Made by Mia'])->assertStatus(403);
        $this->assertSame('Made by the owner', $store->fresh()->description);

        $staffUser = User::factory()->create();
        $staffUser->roles()->attach(Role::where('name', 'staff')->first());
        StaffProfile::create(['user_id' => $staffUser->id, 'store_id' => $store->id, 'role' => 'tailor', 'store_branch_id' => StoreBranch::where(['store_id' => $store->id])->value('id')]);
        $this->actingAs($staffUser)->putJson("/api/v1/stores/{$store->id}", ['description' => 'Hacked'])->assertStatus(403);
    }
}
