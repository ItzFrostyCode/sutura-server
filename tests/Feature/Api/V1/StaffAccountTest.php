<?php

namespace Tests\Feature\Api\V1;

use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\Store;
use App\Models\StoreBranch;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\ShopAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R2 — Add Staff / Add Branch / manager assignment feature tests.
 *
 * Covers:
 *  - Manager can create staff in own branch, cannot pick another branch.
 *  - Owner adds a branch with a new manager login (already tested in BranchManagerFlowTest).
 *  - Email already in use → 422 with ShopAccounts::EMAIL_TAKEN message.
 *  - Staff limit counts branch managers (Basic plan default).
 *  - assignManager: owner gives a managerless branch a manager via staff profile id.
 *  - assignManager: owner gives a managerless branch a new account.
 *  - A branch that already has a manager is rejected by assignManager.
 */
class StaffAccountTest extends TestCase
{
    use RefreshDatabase;

    /** Sets up roles, an approved store and returns [owner, store, mainBranch]. */
    private function ownerWithBranch(): array
    {
        foreach (['store_owner', 'branch_manager', 'staff', 'customer'] as $r) {
            Role::firstOrCreate(['name' => $r], ['description' => $r]);
        }
        $owner = User::factory()->create();
        $owner->roles()->attach(Role::where('name', 'store_owner')->first());
        $store = Store::create([
            'owner_id' => $owner->id, 'name' => 'Test Store', 'slug' => 'test-store',
            'address' => '1 St', 'city' => 'Davao', 'province' => 'Davao del Sur', 'status' => 'approved',
        ]);
        $branch = StoreBranch::create([
            'store_id' => $store->id, 'name' => 'Main', 'slug' => 'b-main',
            'address' => '1 Test St', 'city' => 'Davao City',
            'latitude' => 7.07, 'longitude' => 125.6,
            'is_main' => true, 'status' => 'active', 'verification_status' => 'verified',
        ]);

        return [$owner, $store, $branch];
    }

    /** Creates a branch manager pinned to $branch. */
    private function makeManager(Store $store, StoreBranch $branch): User
    {
        $managerUser = User::factory()->create();
        $managerUser->roles()->attach(Role::where('name', 'branch_manager')->first());
        StaffProfile::create([
            'user_id' => $managerUser->id,
            'store_id' => $store->id,
            'store_branch_id' => $branch->id,
            'role' => 'head_tailor',
            'is_branch_manager' => true,
            'is_active' => true,
        ]);

        return $managerUser;
    }

    /** Creates a plain staff member pinned to $branch. */
    private function makeStaff(Store $store, StoreBranch $branch): User
    {
        $staffUser = User::factory()->create();
        $staffUser->roles()->attach(Role::where('name', 'staff')->first());
        StaffProfile::create([
            'user_id' => $staffUser->id,
            'store_id' => $store->id,
            'store_branch_id' => $branch->id,
            'role' => 'tailor',
            'is_branch_manager' => false,
            'is_active' => true,
        ]);

        return $staffUser;
    }

    private function staffPayload(array $extra = []): array
    {
        return $extra + [
            'name' => 'New Staff',
            'email' => 'newstaff@example.com',
            'password' => 'Secret@1234',
            'password_confirmation' => 'Secret@1234',
        ];
    }

    private function branchPayload(array $extra = []): array
    {
        return $extra + [
            'name' => 'Branch B', 'address' => '2 Test St',
            'city' => 'Davao City', 'latitude' => 7.08, 'longitude' => 125.61,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Add Staff
    // ─────────────────────────────────────────────────────────────────────────

    public function test_branch_manager_creates_staff_in_own_branch(): void
    {
        [$owner, $store, $branch] = $this->ownerWithBranch();
        $manager = $this->makeManager($store, $branch);

        $this->actingAs($manager)
            ->postJson("/api/v1/stores/{$store->id}/staff", $this->staffPayload())
            ->assertStatus(201);

        $newProfile = StaffProfile::where('store_id', $store->id)
            ->where('is_branch_manager', false)
            ->latest()->first();
        $this->assertSame($branch->id, $newProfile->store_branch_id);
        $this->assertTrue(User::find($newProfile->user_id)?->must_change_password);
    }

    public function test_branch_manager_cannot_pick_another_branch_for_new_staff(): void
    {
        [$owner, $store, $branch] = $this->ownerWithBranch();
        $branch2 = StoreBranch::create([
            'store_id' => $store->id, 'name' => 'Branch 2', 'slug' => 'b2',
            'address' => '2 St', 'city' => 'Davao City',
            'latitude' => 7.09, 'longitude' => 125.62,
            'status' => 'active', 'verification_status' => 'verified',
        ]);
        $manager = $this->makeManager($store, $branch);

        $this->actingAs($manager)
            ->postJson("/api/v1/stores/{$store->id}/staff", $this->staffPayload(['store_branch_id' => $branch2->id]))
            ->assertStatus(403);
    }

    public function test_owner_adds_staff_to_auto_selected_single_branch(): void
    {
        [$owner, $store, $branch] = $this->ownerWithBranch();

        $this->actingAs($owner)
            ->postJson("/api/v1/stores/{$store->id}/staff", $this->staffPayload())
            ->assertStatus(201);

        $this->assertSame(
            $branch->id,
            StaffProfile::where('store_id', $store->id)->latest()->first()?->store_branch_id
        );
    }

    public function test_owner_with_two_branches_must_pick_one(): void
    {
        [$owner, $store, $branch] = $this->ownerWithBranch();
        StoreBranch::create([
            'store_id' => $store->id, 'name' => 'Branch 2', 'slug' => 'b2',
            'address' => '2 St', 'city' => 'Davao City',
            'latitude' => 7.09, 'longitude' => 125.62,
            'status' => 'active', 'verification_status' => 'verified',
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/stores/{$store->id}/staff", $this->staffPayload())
            ->assertStatus(422)
            ->assertJsonPath('errors.store_branch_id.0', 'Pick the branch this staff member works in.');
    }

    public function test_email_already_used_returns_422_with_email_taken_message(): void
    {
        [$owner, $store, $branch] = $this->ownerWithBranch();
        // A customer / owner account that already owns this address
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($owner)
            ->postJson("/api/v1/stores/{$store->id}/staff", $this->staffPayload(['email' => 'taken@example.com']))
            ->assertStatus(422)
            ->assertJsonPath('message', ShopAccounts::EMAIL_TAKEN);
    }

    public function test_staff_limit_counts_branch_managers_on_basic_plan(): void
    {
        [$owner, $store, $branch] = $this->ownerWithBranch();

        // Basic plan allows 1 staff (including managers).
        SubscriptionPlan::create([
            'name' => 'Basic', 'slug' => 'basic', 'price' => 0,
            'max_staff' => 1, 'max_branches' => 1, 'is_active' => true,
        ]);

        // The one allowed slot is filled by a branch manager.
        $this->makeManager($store, $branch);

        $this->actingAs($owner)
            ->postJson("/api/v1/stores/{$store->id}/staff", $this->staffPayload())
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Your plan allows up to 1 staff member. Upgrade your plan to add more.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Add Branch + Assign Manager
    // ─────────────────────────────────────────────────────────────────────────

    public function test_owner_adds_branch_and_assigns_manager_via_assign_manager_endpoint(): void
    {
        [$owner, $store, $mainBranch] = $this->ownerWithBranch();

        // Create a second branch with no manager.
        $branch2 = StoreBranch::create([
            'store_id' => $store->id, 'name' => 'Branch 2', 'slug' => 'b2',
            'address' => '2 St', 'city' => 'Davao City',
            'latitude' => 7.09, 'longitude' => 125.62,
            'status' => 'active', 'verification_status' => 'verified',
        ]);

        // Create a plain staff member in the main branch.
        $staffUser = $this->makeStaff($store, $mainBranch);
        $staffProfile = StaffProfile::where('user_id', $staffUser->id)->firstOrFail();

        // Assign that staff member as manager of branch 2.
        $this->actingAs($owner)
            ->postJson("/api/v1/stores/{$store->id}/branches/{$branch2->id}/manager", [
                'manager_id' => $staffProfile->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $staffProfile->refresh();
        $this->assertTrue((bool) $staffProfile->is_branch_manager);
        $this->assertSame($branch2->id, $staffProfile->store_branch_id);
        $this->assertTrue($staffUser->fresh()->hasRole('branch_manager'));
    }

    public function test_owner_assigns_new_account_as_manager_via_assign_manager_endpoint(): void
    {
        [$owner, $store, $mainBranch] = $this->ownerWithBranch();
        $branch2 = StoreBranch::create([
            'store_id' => $store->id, 'name' => 'Branch 2', 'slug' => 'b2',
            'address' => '2 St', 'city' => 'Davao City',
            'latitude' => 7.09, 'longitude' => 125.62,
            'status' => 'active', 'verification_status' => 'verified',
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/stores/{$store->id}/branches/{$branch2->id}/manager", [
                'manager' => [
                    'name' => 'Branch Mgr', 'email' => 'mgr@example.com',
                    'password' => 'Secret@1234', 'password_confirmation' => 'Secret@1234',
                ],
            ])
            ->assertOk();

        $mgrUser = User::where('email', 'mgr@example.com')->firstOrFail();
        $this->assertTrue($mgrUser->hasRole('branch_manager'));
        $this->assertTrue($mgrUser->must_change_password);

        $profile = StaffProfile::where('user_id', $mgrUser->id)->firstOrFail();
        $this->assertSame($branch2->id, $profile->store_branch_id);
    }

    public function test_assign_manager_fails_when_branch_already_has_manager(): void
    {
        [$owner, $store, $branch] = $this->ownerWithBranch();
        $this->makeManager($store, $branch);

        $this->actingAs($owner)
            ->postJson("/api/v1/stores/{$store->id}/branches/{$branch->id}/manager", [
                'manager' => [
                    'name' => 'Second Mgr', 'email' => 'second@example.com',
                    'password' => 'Secret@1234', 'password_confirmation' => 'Secret@1234',
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This branch already has a manager.');
    }

    public function test_new_staff_must_change_password_on_first_login(): void
    {
        [$owner, $store, $branch] = $this->ownerWithBranch();

        $this->actingAs($owner)
            ->postJson("/api/v1/stores/{$store->id}/staff", $this->staffPayload())
            ->assertStatus(201);

        $profile = StaffProfile::where('store_id', $store->id)->latest()->first();
        $this->assertTrue(User::find($profile->user_id)?->must_change_password);
    }
}
