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
    protected CatalogItem $rentalItem;
    protected CatalogItem $saleItem;

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

        $this->rentalItem = CatalogItem::create([
            'shop_id' => $this->shop->id,
            'name' => 'Rental Tulle Gown',
            'price' => 5000.00,
            'listing_type' => 'for_rent',
            'material' => 'Tulle',
        ]);

        $this->saleItem = CatalogItem::create([
            'shop_id' => $this->shop->id,
            'name' => 'Ready-to-Wear Shirt',
            'price' => 1200.00,
            'listing_type' => 'ready_to_wear',
            'material' => 'Cotton',
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

    /** Test rental orders must use pickup fulfillment. */
    public function test_rental_order_requires_pickup(): void
    {
        $payload = [
            'catalog_item_id' => $this->rentalItem->id,
            'type' => 'online',
            'total_amount' => 5000.00,
            'payment_status' => 'pending',
            'fulfillment_type' => 'shipping', // Invalid for rental
            'rental_start_date' => '2026-07-10',
            'rental_end_date' => '2026-07-15',
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/shops/{$this->shop->id}/catalog-orders", $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('fulfillment_type');
    }

    /** Test rental orders require start and end dates. */
    public function test_rental_order_requires_dates(): void
    {
        $payload = [
            'catalog_item_id' => $this->rentalItem->id,
            'type' => 'online',
            'total_amount' => 5000.00,
            'payment_status' => 'pending',
            'fulfillment_type' => 'pickup',
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/shops/{$this->shop->id}/catalog-orders", $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('rental_start_date');
    }

    /** Test public booking submits a catalog order correctly. */
    public function test_public_booking_creates_catalog_order_and_appointment(): void
    {
        $payload = [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@example.com',
            'phone' => '09171234567',
            'appointment_type' => 'consultation',
            'scheduled_at' => '2026-07-10 10:00:00',
            'payment_method' => 'cash',
            'catalog_item_id' => $this->rentalItem->id,
            'fulfillment_type' => 'pickup',
            'rental_start_date' => '2026-07-10',
            'rental_end_date' => '2026-07-15',
        ];

        $response = $this->postJson("/api/v1/catalog/{$this->shop->slug}/book", $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('appointments', [
            'appointment_type' => 'consultation',
            'intake_channel' => 'online',
        ]);
        $this->assertDatabaseHas('catalog_orders', [
            'catalog_item_id' => $this->rentalItem->id,
            'fulfillment_type' => 'pickup',
            'rental_start_date' => '2026-07-10',
            'rental_end_date' => '2026-07-15',
        ]);
    }
}
