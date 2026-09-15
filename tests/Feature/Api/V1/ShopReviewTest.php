<?php

namespace Tests\Feature\Api\V1;

use App\Models\Role;
use App\Models\Shop;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'shop_owner', 'description' => 'Shop Owner']);
        Role::firstOrCreate(['name' => 'customer', 'description' => 'Customer']);
        Role::firstOrCreate(['name' => 'staff', 'description' => 'Staff']);
    }

    private function createShop(User $owner): Shop
    {
        return Shop::create([
            'owner_id' => $owner->id,
            'name' => 'Lyndel Tailoring',
            'slug' => 'lyndel-tailoring-' . uniqid(),
            'address' => 'San Pedro St',
            'city' => 'Davao City',
            'province' => 'Davao del Sur',
            'status' => 'approved',
        ]);
    }

    public function test_customer_can_submit_review()
    {
        $owner = User::factory()->create();
        $owner->roles()->attach(Role::where('name', 'shop_owner')->first());

        $shop = $this->createShop($owner);

        $customer = User::factory()->create();
        $customer->roles()->attach(Role::where('name', 'customer')->first());

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/shops/{$shop->id}/reviews", [
                'rating' => 5,
                'comment' => 'Nindot kaayo ang tahi ug sakto ang sukat!',
            ]);

        $response->assertStatus(200)
                 ->assertJsonPath('success', true);

        $this->assertDatabaseHas('shop_reviews', [
            'shop_id' => $shop->id,
            'user_id' => $customer->id,
            'rating' => 5,
        ]);
    }

    public function test_shop_owner_cannot_review_own_shop()
    {
        $owner = User::factory()->create();
        $owner->roles()->attach(Role::where('name', 'shop_owner')->first());

        $shop = $this->createShop($owner);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/shops/{$shop->id}/reviews", [
                'rating' => 5,
                'comment' => 'Reviewing my own shop to boost stars',
            ]);

        $response->assertStatus(403)
                 ->assertJsonPath('success', false)
                 ->assertJsonPath('message', 'Shop owners cannot review their own shop.');
    }

    public function test_staff_member_cannot_review_own_shop()
    {
        $owner = User::factory()->create();
        $owner->roles()->attach(Role::where('name', 'shop_owner')->first());

        $shop = $this->createShop($owner);

        $staff = User::factory()->create();
        $staff->roles()->attach(Role::where('name', 'staff')->first());

        StaffProfile::create([
            'user_id' => $staff->id,
            'shop_id' => $shop->id,
            'specialization' => ['sewing'],
            'is_available' => true,
        ]);

        $response = $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/shops/{$shop->id}/reviews", [
                'rating' => 5,
                'comment' => 'Great employer!',
            ]);

        $response->assertStatus(403)
                 ->assertJsonPath('success', false)
                 ->assertJsonPath('message', 'Staff members cannot review their own shop.');
    }
}
