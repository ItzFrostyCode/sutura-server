<?php

namespace Tests\Feature\Api\V1;

use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // These URL-correctness tests deliberately use the REAL 'public' disk
        // (not Storage::fake()) -- see note below -- so clean up what they wrote.
        Storage::disk('public')->deleteDirectory('shops');
        parent::tearDown();
    }

    private function makeShopOwner(): array
    {
        $role = Role::create(['name' => 'shop_owner', 'description' => 'Shop Owner']);
        $user = User::factory()->create();
        $user->roles()->attach($role);
        $shop = Shop::create([
            'owner_id' => $user->id, 'name' => 'Test Shop', 'slug' => 'test-shop',
            'address' => '1 St', 'city' => 'Davao', 'province' => 'Davao del Sur', 'status' => 'approved',
        ]);

        return [$user, $shop];
    }

    // Regression guard for the bug where 'url' => config('app.url') . Storage::url($path)
    // double-prefixed every upload response with the app URL -- this was already live on
    // the local 'public' disk (not just a future S3/R2 problem), see project memory.
    //
    // Deliberately does NOT use Storage::fake() here: fake() swaps in a bare local driver
    // that doesn't reproduce the 'public' disk's custom `url` config (the one that bakes in
    // APP_URL and is the actual source of the bug) -- under fake(), Storage::url() returns
    // a plain relative path and the bug wouldn't show up at all, defeating the point of the test.
    private function assertNotDoublePrefixed(string $url, string $appUrl): void
    {
        $this->assertStringStartsWith($appUrl, $url);
        $this->assertSame(1, substr_count($url, $appUrl), "URL is double-prefixed: {$url}");
    }

    public function test_shop_owner_can_upload_catalog_image_with_correct_url(): void
    {
        [$user, $shop] = $this->makeShopOwner();

        $response = $this->actingAs($user)->postJson("/api/v1/shops/{$shop->id}/upload", [
            'file' => UploadedFile::fake()->image('logo.jpg'),
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $url = $response->json('data.url');
        $this->assertNotDoublePrefixed($url, config('app.url'));
        Storage::disk('public')->assertExists("shops/{$shop->id}/catalog/" . basename(parse_url($url, PHP_URL_PATH)));
    }

    public function test_shop_owner_can_upload_support_attachment_with_correct_url(): void
    {
        [$user, $shop] = $this->makeShopOwner();

        $response = $this->actingAs($user)->postJson("/api/v1/shops/{$shop->id}/support/upload", [
            'file' => UploadedFile::fake()->image('screenshot.png'),
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertNotDoublePrefixed($response->json('data.url'), config('app.url'));
    }

    public function test_public_visitor_can_upload_receipt_with_correct_url(): void
    {
        [, $shop] = $this->makeShopOwner();

        $response = $this->postJson("/api/v1/public/shops/{$shop->slug}/upload-receipt", [
            'file' => UploadedFile::fake()->image('receipt.jpg'),
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertNotDoublePrefixed($response->json('data.url'), config('app.url'));
    }

    public function test_public_visitor_can_upload_reference_image_with_correct_url(): void
    {
        [, $shop] = $this->makeShopOwner();

        $response = $this->postJson("/api/v1/public/shops/{$shop->slug}/upload-reference-image", [
            'file' => UploadedFile::fake()->image('barong-design.jpg'),
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertNotDoublePrefixed($response->json('data.url'), config('app.url'));
    }

    public function test_catalog_upload_rejects_non_image_file(): void
    {
        Storage::fake('public');
        [$user, $shop] = $this->makeShopOwner();

        $response = $this->actingAs($user)->postJson("/api/v1/shops/{$shop->id}/upload", [
            'file' => UploadedFile::fake()->create('notes.txt', 10),
        ]);

        $response->assertStatus(422);
    }

    public function test_catalog_upload_rejects_oversized_file(): void
    {
        Storage::fake('public');
        [$user, $shop] = $this->makeShopOwner();

        // Controller caps this endpoint at 5120 KB (5MB).
        $response = $this->actingAs($user)->postJson("/api/v1/shops/{$shop->id}/upload", [
            'file' => UploadedFile::fake()->image('too-big.jpg')->size(6000),
        ]);

        $response->assertStatus(422);
    }
}
