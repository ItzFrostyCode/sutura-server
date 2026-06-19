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
}
