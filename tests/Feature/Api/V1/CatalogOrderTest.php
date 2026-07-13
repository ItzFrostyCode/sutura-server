<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Models\Shop;
use App\Models\CatalogItem;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogOrderTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Shop $shop;
    protected User $customer;
    protected CatalogItem $catalogItem;

    protected function setUp(): void
    {
        parent::setUp();

        $ownerRole = Role::create(['name' => 'shop_owner', 'description' => 'Shop Owner']);
        $customerRole = Role::create(['name' => 'customer', 'description' => 'Customer']);

        $this->user = User::factory()->create();
        $this->user->roles()->attach($ownerRole);

        $this->shop = Shop::create([
            'owner_id' => $this->user->id,
            'name' => 'Test Shop',
            'slug' => 'test-shop',
            'address' => '123 Test St',
            'city' => 'Manila',
            'province' => 'Metro Manila',
            'status' => 'approved'
        ]);

        $this->customer = User::factory()->create();
        $this->customer->roles()->attach($customerRole);

        // Made-to-order only — the approved thesis excludes ready-to-wear
        // inventory and rental stock from the system's scope.
        $this->catalogItem = CatalogItem::create([
            'shop_id' => $this->shop->id,
            'name' => 'Barong Tagalog',
            'price' => 5000.00,
            'listing_type' => 'made_to_order',
            'material' => 'Jusi',
        ]);

        // Create a single branch to satisfy single branch auto-resolve
        $this->shop->branches()->create([
            'name' => 'Main Branch',
            'address' => '123 Test St',
            'city' => 'Manila',
            'operating_hours' => '09:00 - 18:00',
            'status' => 'active'
        ]);
    }

    /** A walk-in catalog order is always store pickup, regardless of what's posted. */
    public function test_walkin_order_is_always_store_pickup(): void
    {
        $payload = [
            'catalog_item_id' => $this->catalogItem->id,
            'total_amount' => 5000.00,
            'payment_status' => 'pending',
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/shops/{$this->shop->id}/catalog-orders", $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('catalog_orders', [
            'catalog_item_id' => $this->catalogItem->id,
            'type' => 'walkin',
            'fulfillment_type' => 'pickup',
            'status' => 'ready',
        ]);
    }

    /** Public booking creates only an appointment — catalog items are a
     *  design reference for the fitting, not something ordered online. */
    public function test_public_booking_creates_appointment_only(): void
    {
        $payload = [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@example.com',
            'phone' => '09171234567',
            'appointment_type' => 'consultation',
            'scheduled_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
            'payment_method' => 'cash',
        ];

        $response = $this->postJson("/api/v1/catalog/{$this->shop->slug}/book", $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('appointments', [
            'appointment_type' => 'consultation',
            'intake_channel' => 'online',
        ]);
        $this->assertDatabaseCount('catalog_orders', 0);
    }
}
