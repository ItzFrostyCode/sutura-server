<?php

namespace Tests\Feature\Api\V1;

use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserGenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_register_with_gender(): void
    {
        Role::create(['name' => 'customer', 'description' => 'Customer']);

        $res = $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane.doe@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'gender' => 'female',
            'role' => 'customer',
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'jane.doe@example.com',
            'gender' => 'female',
        ]);
    }

    public function test_user_can_update_gender_via_personal_profile(): void
    {
        $user = User::factory()->create(['gender' => 'male']);

        $res = $this->actingAs($user)->putJson('/api/v1/profile/personal', [
            'name' => $user->name,
            'phone' => '09123456789',
            'gender' => 'female',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.gender', 'female');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'gender' => 'female',
        ]);
    }

    public function test_owner_can_create_and_update_staff_with_gender(): void
    {
        $ownerRole = Role::create(['name' => 'store_owner', 'description' => 'Store Owner']);
        Role::create(['name' => 'staff', 'description' => 'Staff']);

        $owner = User::factory()->create();
        $owner->roles()->attach($ownerRole);

        $store = Store::create([
            'owner_id' => $owner->id,
            'name' => 'Tailor Hub',
            'slug' => 'tailor-hub',
            'address' => 'Davao City',
            'city' => 'Davao City',
            'province' => 'Davao del Sur',
            'status' => 'approved',
        ]);

        $mainBranch = $store->branches()->create([
            'name' => 'Main Branch',
            'slug' => 'main-branch',
            'address' => 'Davao City',
            'city' => 'Davao City',
            'is_main' => true,
        ]);

        SubscriptionPlan::create([
            'name' => 'Basic Plan',
            'slug' => 'basic',
            'max_staff' => 10,
            'price_monthly' => 100,
            'price_yearly' => 1000,
        ]);

        // 1. Create staff with gender = male
        $createRes = $this->actingAs($owner)->postJson("/api/v1/stores/{$store->id}/staff", [
            'name' => 'Alex Tailor',
            'email' => 'alex.tailor@example.com',
            'password' => 'Password123!',
            'phone' => '09112223333',
            'gender' => 'male',
            'role' => 'tailor',
        ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('data.user.gender', 'male');

        $this->assertDatabaseHas('users', [
            'email' => 'alex.tailor@example.com',
            'gender' => 'male',
        ]);

        $staffProfileId = $createRes->json('data.id');

        // 2. Fetch staff list - gender is loaded
        $listRes = $this->actingAs($owner)->getJson("/api/v1/stores/{$store->id}/staff");
        $listRes->assertStatus(200)
            ->assertJsonPath('data.0.user.gender', 'male');

        // 3. Update staff with gender = female
        $updateRes = $this->actingAs($owner)->putJson("/api/v1/stores/{$store->id}/staff/{$staffProfileId}", [
            'name' => 'Alex Tailor',
            'email' => 'alex.tailor@example.com',
            'phone' => '09112223333',
            'gender' => 'female',
            'role' => 'tailor',
        ]);

        $updateRes->assertStatus(200)
            ->assertJsonPath('data.user.gender', 'female');

        $this->assertDatabaseHas('users', [
            'email' => 'alex.tailor@example.com',
            'gender' => 'female',
        ]);
    }

    public function test_store_application_creates_owner_with_gender(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        Role::create(['name' => 'store_owner', 'description' => 'Store Owner']);
        $plan = SubscriptionPlan::create([
            'name' => 'Pro Plan',
            'slug' => 'pro',
            'price_monthly' => 200,
            'price_yearly' => 2000,
            'is_active' => true,
        ]);

        $dummyImg = \Illuminate\Http\UploadedFile::fake()->image('test.jpg');
        $dummyPdf = \Illuminate\Http\UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        $response = $this->postJson('/api/v1/store-applications', [
            'first_name' => 'Maria',
            'last_name' => 'Clara',
            'birthday' => '1990-01-01',
            'email' => 'maria.clara@example.com',
            'contact_number' => '09123456789',
            'gender' => 'female',
            'store_name' => 'Clara Fashions',
            'address' => '123 Rizal St',
            'city' => 'Davao City',
            'province' => 'Davao del Sur',
            'latitude' => 7.07,
            'longitude' => 125.6,
            'specializations' => ['custom_tailoring'],
            'landmark_image' => $dummyImg,
            'dti_registration' => $dummyPdf,
            'tin_id' => $dummyPdf,
            'brgy_clearance' => $dummyPdf,
            'government_id' => $dummyPdf,
            'government_id_type' => 'Passport',
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
            'payment_method' => 'gcash',
            'payment_receipt' => $dummyImg,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'contact_email' => 'maria.clara@example.com',
            'gender' => 'female',
        ]);
    }
}

