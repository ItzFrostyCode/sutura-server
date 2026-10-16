<?php

namespace Tests\Feature\Api\V1;

use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\Store;
use App\Models\StoreBranch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The owner makes branches and their managers; staff are added by the manager, never picked a branch for. */
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

    private function branchPayload(array $extra = []): array
    {
        return $extra + ['name' => 'Main', 'address' => '1 Test St', 'city' => 'Davao City', 'latitude' => 7.07, 'longitude' => 125.6];
    }

    public function test_owner_creates_a_branch_with_its_manager_login_and_the_manager_can_log_in_as_manager(): void
    {
        [$owner, $store] = $this->owner();

        $this->actingAs($owner)->postJson("/api/v1/stores/{$store->id}/branches", $this->branchPayload([
            'manager' => ['name' => 'Mia Manager', 'email' => 'mia@example.com', 'password' => 'secret123'],
        ]))->assertOk();

        $manager = User::where('email', 'mia@example.com')->firstOrFail();
        $this->assertTrue($manager->hasRole('branch_manager'));
        $profile = StaffProfile::where('user_id', $manager->id)->firstOrFail();
        $this->assertTrue((bool) $profile->is_branch_manager);
        $this->assertSame(StoreBranch::where('store_id', $store->id)->value('id'), $profile->store_branch_id);
    }

    public function test_owner_adds_staff_to_the_branch_they_run_but_not_one_with_a_manager(): void
    {
        [$owner, $store] = $this->owner();
        $main = StoreBranch::create($this->branchPayload(['store_id' => $store->id, 'name' => 'Main', 'slug' => 'b-main', 'is_main' => true, 'status' => 'active', 'verification_status' => 'verified']));
        $staff = ['name' => 'Sam Staff', 'email' => 'sam@example.com', 'password' => 'secret123', 'role' => 'tailor'];

        $this->actingAs($owner)->postJson("/api/v1/stores/{$store->id}/staff", $staff)->assertStatus(201);
        $this->assertSame($main->id, StaffProfile::where('store_id', $store->id)->value('store_branch_id'));

        $this->actingAs($owner)->putJson("/api/v1/stores/{$store->id}/branches/{$main->id}", $this->branchPayload([
            'manager' => ['name' => 'Mia Manager', 'email' => 'mia@example.com', 'password' => 'secret123'],
        ]))->assertOk();
        $this->actingAs($owner)->postJson("/api/v1/stores/{$store->id}/staff", ['email' => 'sue@example.com', 'name' => 'Sue'] + $staff)->assertStatus(422);
    }

    public function test_branch_manager_can_edit_the_shop_page_but_plain_staff_cannot(): void
    {
        [$owner, $store] = $this->owner();
        $this->actingAs($owner)->postJson("/api/v1/stores/{$store->id}/branches", $this->branchPayload([
            'manager' => ['name' => 'Mia Manager', 'email' => 'mia@example.com', 'password' => 'secret123'],
        ]))->assertOk();
        $manager = User::where('email', 'mia@example.com')->firstOrFail();

        $this->actingAs($manager)->putJson("/api/v1/stores/{$store->id}", ['description' => 'Made by Mia'])->assertOk();
        $this->assertSame('Made by Mia', $store->fresh()->description);

        $staffUser = User::factory()->create();
        $staffUser->roles()->attach(Role::where('name', 'staff')->first());
        StaffProfile::create(['user_id' => $staffUser->id, 'store_id' => $store->id, 'role' => 'tailor', 'store_branch_id' => StoreBranch::where('store_id', $store->id)->value('id')]);
        $this->actingAs($staffUser)->putJson("/api/v1/stores/{$store->id}", ['description' => 'Hacked'])->assertStatus(403);
    }
}
