<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'shop_owner', 'description' => 'Shop Owner']);
    }

    public function test_user_can_register()
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'shop_owner',
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('success', true);
                 
        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com'
        ]);
    }

    public function test_user_can_login()
    {
        $role = Role::first();
        $user = User::factory()->create([
            'password' => bcrypt('password')
        ]);
        $user->roles()->attach($role);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'success',
                     'data' => [
                         'user',
                         'token'
                     ]
                 ]);
    }

    public function test_walkin_shadow_account_is_claimed_when_customer_registers_by_phone()
    {
        Role::firstOrCreate(['name' => 'customer', 'description' => 'Customer']);

        // A walk-in customer was created at counter with synthetic email and phone
        $shadow = User::create([
            'name' => 'Walk-in Maria',
            'email' => 'walkin_1720000000_abcd@sutura.com',
            'phone' => '09171234567',
            'password' => bcrypt('temporary_random'),
            'password_set_at' => null,
        ]);

        // Maria later registers online with her real email and same phone
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Maria Santos',
            'email' => 'maria.santos@gmail.com',
            'phone' => '09171234567',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'customer',
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('success', true);

        // The original shadow record was updated with the real email and password
        $this->assertDatabaseHas('users', [
            'id' => $shadow->id,
            'email' => 'maria.santos@gmail.com',
            'name' => 'Maria Santos',
            'phone' => '09171234567',
        ]);

        // No orphaned synthetic account remains
        $this->assertDatabaseMissing('users', [
            'email' => 'walkin_1720000000_abcd@sutura.com',
        ]);
    }
}
