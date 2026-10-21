<?php

namespace Tests\Feature\Api\V1;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class StoreAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'customer', 'description' => 'Customer']);
        Role::firstOrCreate(['name' => 'store_owner', 'description' => 'Store Owner']);
    }

    public function test_user_can_register()
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'customer',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
        ]);
    }

    public function test_user_can_login_with_valid_credentials()
    {
        $role = Role::where('name', 'customer')->first();
        $user = User::factory()->create([
            'email' => 'customer@example.com',
            'password' => Hash::make('Password123!'),
        ]);
        $user->roles()->attach($role);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'Password123!',
            'portal' => 'customer',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'user',
                    'token',
                ],
            ]);
    }

    public function test_login_fails_with_incorrect_password()
    {
        $role = Role::where('name', 'customer')->first();
        $user = User::factory()->create([
            'email' => 'customer@example.com',
            'password' => Hash::make('Password123!'),
        ]);
        $user->roles()->attach($role);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'WrongPassword123!',
            'portal' => 'customer',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Incorrect email or password.');
    }

    public function test_login_fails_with_unregistered_email()
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'unregistered@example.com',
            'password' => 'Password123!',
            'portal' => 'customer',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Incorrect email or password.');
    }

    public function test_forgot_password_for_registered_account()
    {
        $role = Role::where('name', 'customer')->first();
        $user = User::factory()->create([
            'email' => 'registered@example.com',
            'password' => Hash::make('Password123!'),
        ]);
        $user->roles()->attach($role);

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'registered@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'If an account exists for this email, you’ll receive password-reset instructions.');

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'registered@example.com',
        ]);
    }

    public function test_forgot_password_for_unregistered_account_returns_generic_message_and_no_token()
    {
        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'If an account exists for this email, you’ll receive password-reset instructions.');

        // Token must never be generated for unregistered emails
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'nonexistent@example.com',
        ]);
    }

    public function test_reset_password_with_valid_token()
    {
        $role = Role::where('name', 'customer')->first();
        $user = User::factory()->create([
            'email' => 'resetme@example.com',
            'password' => Hash::make('OldPassword123!'),
        ]);
        $user->roles()->attach($role);

        $token = Password::createToken($user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'resetme@example.com',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Password reset successfully. Please log in with your new password.');

        $user->refresh();
        $this->assertTrue(Hash::check('NewPassword123!', $user->password));

        // Token cannot be reused (deleted after use)
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'resetme@example.com',
        ]);
    }

    public function test_reset_password_fails_with_invalid_token()
    {
        $role = Role::where('name', 'customer')->first();
        $user = User::factory()->create([
            'email' => 'resetme@example.com',
            'password' => Hash::make('OldPassword123!'),
        ]);
        $user->roles()->attach($role);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'invalid-token-value',
            'email' => 'resetme@example.com',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This reset link is invalid or has expired. Please request a new one.');
    }

    public function test_suspended_account_cannot_login()
    {
        $role = Role::where('name', 'customer')->first();
        $user = User::factory()->create([
            'email' => 'suspended@example.com',
            'password' => Hash::make('Password123!'),
            'suspended_at' => now(),
        ]);
        $user->roles()->attach($role);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'suspended@example.com',
            'password' => 'Password123!',
            'portal' => 'customer',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This account has been suspended. Please contact SUTURA support.');
    }

    public function test_walkin_shadow_account_is_claimed_when_customer_registers_by_phone()
    {
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
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
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
