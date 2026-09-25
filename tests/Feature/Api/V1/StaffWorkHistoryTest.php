<?php

namespace Tests\Feature\Api\V1;

use App\Models\JobOrder;
use App\Models\Role;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffWorkHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_completing_a_job_stamps_staff_completion_and_history(): void
    {
        $role = Role::create(['name' => 'store_owner', 'description' => 'Store Owner']);
        $owner = User::factory()->create();
        $owner->roles()->attach($role);
        $store = Store::create([
            'owner_id' => $owner->id, 'name' => 'Test Store', 'slug' => 'test-store',
            'address' => '1 St', 'city' => 'Davao', 'province' => 'Davao del Sur', 'status' => 'approved',
        ]);
        $customer = User::factory()->create();
        $service = Service::create(['store_id' => $store->id, 'name' => 'Suit']);
        $tailor = User::factory()->create();
        $staff = StaffProfile::create(['store_id' => $store->id, 'user_id' => $tailor->id, 'role' => 'tailor']);

        $job = JobOrder::create([
            'order_number' => 'JO-'.Str::random(8),
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'status' => 'sewing',
            'total_amount' => 1000,
            'balance' => 0,
            'payment_status' => 'paid',
        ]);
        $job->staffStages()->attach($tailor->id, ['stage' => 'sewing', 'assigned_at' => now()]);

        // Before completion the assignment is open.
        $this->assertNull(
            DB::table('job_order_staff')->where('job_order_id', $job->id)->value('completed_at')
        );

        // The OWNER marks the job completed.
        $this->actingAs($owner)
            ->putJson("/api/v1/stores/{$store->id}/jobs/{$job->id}", ['status' => 'completed'])
            ->assertStatus(200);

        // Completion is now stamped on the staff pivot.
        $this->assertNotNull(
            DB::table('job_order_staff')->where('job_order_id', $job->id)->value('completed_at')
        );

        // Work-history endpoint reflects assigned vs completed.
        $this->actingAs($owner)
            ->getJson("/api/v1/stores/{$store->id}/staff/{$staff->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.total_assigned', 1)
            ->assertJsonPath('data.total_completed', 1)
            ->assertJsonPath('data.active', 0);
    }
}
