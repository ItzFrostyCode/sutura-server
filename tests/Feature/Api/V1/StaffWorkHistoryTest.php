<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Models\Shop;
use App\Models\Service;
use App\Models\JobOrder;
use App\Models\StaffProfile;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffWorkHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_completing_a_job_stamps_staff_completion_and_history(): void
    {
        $role = Role::create(['name' => 'shop_owner', 'description' => 'Shop Owner']);
        $owner = User::factory()->create();
        $owner->roles()->attach($role);
        $shop = Shop::create([
            'owner_id' => $owner->id, 'name' => 'Test Shop', 'slug' => 'test-shop',
            'address' => '1 St', 'city' => 'Davao', 'province' => 'Davao del Sur', 'status' => 'approved',
        ]);
        $customer = User::factory()->create();
        $service = Service::create(['shop_id' => $shop->id, 'name' => 'Suit']);
        $tailor = User::factory()->create();
        $staff = StaffProfile::create(['shop_id' => $shop->id, 'user_id' => $tailor->id, 'role' => 'tailor']);

        $job = JobOrder::create([
            'order_number' => 'JO-' . Str::random(8),
            'shop_id' => $shop->id,
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
            ->putJson("/api/v1/shops/{$shop->id}/jobs/{$job->id}", ['status' => 'completed'])
            ->assertStatus(200);

        // Completion is now stamped on the staff pivot.
        $this->assertNotNull(
            DB::table('job_order_staff')->where('job_order_id', $job->id)->value('completed_at')
        );

        // Work-history endpoint reflects assigned vs completed.
        $this->actingAs($owner)
            ->getJson("/api/v1/shops/{$shop->id}/staff/{$staff->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.total_assigned', 1)
            ->assertJsonPath('data.total_completed', 1)
            ->assertJsonPath('data.active', 0);
    }
}
