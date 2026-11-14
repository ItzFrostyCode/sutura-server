<?php

namespace Tests\Feature\Api\V1;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

/** "Continue with Google": state check, customer-only, one-time code exchange. */
class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'customer', 'description' => 'Customer']);
        Role::firstOrCreate(['name' => 'store_owner', 'description' => 'Store Owner']);
        config([
            'services.google.client_id' => 'test-id',
            'services.google.client_secret' => 'test-secret',
            'app.frontend_url' => 'http://localhost:3000',
        ]);
    }

    private function fakeGoogle(string $email, bool $verified = true, string $id = 'g-123', ?string $avatar = null): void
    {
        $google = (new GoogleUser)->setRaw(['email_verified' => $verified])->map([
            'id' => $id, 'name' => 'Maria Santos', 'email' => $email, 'avatar' => $avatar,
        ]);
        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($google);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    /** Runs the callback with a valid state and returns the URL fragment it sends the browser to. */
    private function runCallback(): array
    {
        Cache::put('google_oauth_state_s1', true, 600);
        $location = $this->get('/api/v1/auth/google/callback?state=s1&code=x')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('http://localhost:3000/auth/google/callback#', $location);
        parse_str((string) parse_url($location, PHP_URL_FRAGMENT), $params);

        return $params;
    }

    public function test_new_google_user_becomes_a_signed_in_customer(): void
    {
        $this->fakeGoogle('maria@gmail.com');
        $params = $this->runCallback();
        $this->assertArrayHasKey('code', $params);

        $user = User::where('email', 'maria@gmail.com')->firstOrFail();
        $this->assertTrue($user->hasRole('customer'));
        $this->assertSame('g-123', $user->google_id);

        $this->postJson('/api/v1/auth/google/exchange', ['code' => $params['code']])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'maria@gmail.com')
            ->assertJsonMissingPath('data.user.google_id');

        // The code works once.
        $this->postJson('/api/v1/auth/google/exchange', ['code' => $params['code']])->assertStatus(401);
    }

    public function test_shop_account_cannot_use_google(): void
    {
        $owner = User::factory()->create(['email' => 'owner@gmail.com']);
        $owner->roles()->attach(Role::where('name', 'store_owner')->first()->id);
        $this->fakeGoogle('owner@gmail.com');

        $params = $this->runCallback();
        $this->assertArrayNotHasKey('code', $params);
        $this->assertStringContainsString('shop account', $params['error']);
    }

    public function test_unverified_google_email_is_refused(): void
    {
        $this->fakeGoogle('maria@gmail.com', verified: false);

        $params = $this->runCallback();
        $this->assertArrayHasKey('error', $params);
        $this->assertDatabaseMissing('users', ['email' => 'maria@gmail.com']);
    }

    public function test_new_google_user_gets_the_google_photo_at_profile_size(): void
    {
        $this->fakeGoogle('maria@gmail.com', avatar: 'https://lh3.googleusercontent.com/a/abc=s96-c');
        $this->runCallback();

        $this->assertSame('https://lh3.googleusercontent.com/a/abc=s256-c', User::where('email', 'maria@gmail.com')->value('profile_picture'));
    }

    public function test_existing_customer_without_a_photo_gets_the_google_photo(): void
    {
        $user = User::factory()->create(['email' => 'maria@gmail.com', 'profile_picture' => null]);
        $user->roles()->attach(Role::where('name', 'customer')->first()->id);
        $this->fakeGoogle('maria@gmail.com', avatar: 'https://lh3.googleusercontent.com/a/abc=s96-c');
        $this->runCallback();

        $this->assertSame('https://lh3.googleusercontent.com/a/abc=s256-c', $user->fresh()->profile_picture);
    }

    public function test_a_changed_google_photo_replaces_the_old_google_photo(): void
    {
        $user = User::factory()->create(['email' => 'maria@gmail.com', 'profile_picture' => 'https://lh3.googleusercontent.com/a/old=s96-c']);
        $user->roles()->attach(Role::where('name', 'customer')->first()->id);
        $this->fakeGoogle('maria@gmail.com', avatar: 'https://lh3.googleusercontent.com/a/new=s96-c');
        $this->runCallback();

        $this->assertSame('https://lh3.googleusercontent.com/a/new=s256-c', $user->fresh()->profile_picture);
    }

    public function test_an_uploaded_photo_is_kept_over_the_google_photo(): void
    {
        $own = 'http://127.0.0.1:8000/storage/avatars/me.jpg';
        $user = User::factory()->create(['email' => 'maria@gmail.com', 'profile_picture' => $own]);
        $user->roles()->attach(Role::where('name', 'customer')->first()->id);
        $this->fakeGoogle('maria@gmail.com', avatar: 'https://lh3.googleusercontent.com/a/abc=s96-c');
        $this->runCallback();

        $this->assertSame($own, $user->fresh()->profile_picture);
    }

    public function test_unknown_state_is_refused(): void
    {
        $location = $this->get('/api/v1/auth/google/callback?state=nope&code=x')->headers->get('Location');
        $this->assertStringContainsString('error=', $location);
    }
}
