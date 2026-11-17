<?php

namespace Tests\Feature;

use App\Models\CatalogImage;
use App\Models\CatalogItem;
use App\Models\JobOrder;
use App\Models\Role;
use App\Models\Service;
use App\Models\Store;
use App\Models\StoreBranch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /my-orders/{id} always gives the order a picture of what it is: the
 * customer's own attachments win, otherwise the order's design/service
 * image stands in, so a service or design order never renders blank.
 */
class JobOrderTrackingReferenceFallbackTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Store $store;

    private StoreBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['store_owner', 'customer'] as $name) {
            Role::firstOrCreate(['name' => $name], ['description' => $name]);
        }
        $this->customer = User::factory()->create();
        $this->customer->roles()->attach(Role::where('name', 'customer')->first());

        $owner = User::factory()->create();
        $owner->roles()->attach(Role::where('name', 'store_owner')->first());

        $this->store = Store::create(['owner_id' => $owner->id, 'name' => 'S', 'slug' => 's', 'address' => '1', 'city' => 'D', 'province' => 'D', 'status' => 'approved']);
        $this->branch = StoreBranch::create(['store_id' => $this->store->id, 'name' => 'A', 'slug' => 'a', 'address' => 'A', 'city' => 'D', 'is_main' => true, 'status' => 'active', 'verification_status' => 'verified']);
    }

    private function order(array $over = [], array $refs = []): JobOrder
    {
        return JobOrder::create(array_merge([
            'store_id' => $this->store->id,
            'store_branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'JO-'.random_int(10000, 99999),
            'total_amount' => 500,
            'balance' => 500,
            'discount_amount' => 0,
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'quantity' => 1,
            'reference_images' => $refs ?: null,
        ], $over));
    }

    private function refs(JobOrder $order): array
    {
        return $this->actingAs($this->customer)
            ->getJson("/api/v1/my-orders/{$order->id}")
            ->assertStatus(200)
            ->json('data.reference_images');
    }

    public function test_service_order_without_customer_attachments_falls_back_to_service_image(): void
    {
        $service = Service::create([
            'store_id' => $this->store->id, 'name' => 'Sportswear Jersey', 'base_price' => 800,
            'image_url' => 'https://img.test/jersey.jpg', 'measurement_requirement' => 'none',
        ]);
        $order = $this->order(['service_id' => $service->id]);

        $this->assertSame(['https://img.test/jersey.jpg'], $this->refs($order));
    }

    public function test_catalog_order_falls_back_to_design_images_before_service_image(): void
    {
        $service = Service::create([
            'store_id' => $this->store->id, 'name' => 'Alterations', 'base_price' => 200,
            'image_url' => 'https://img.test/alterations.jpg', 'measurement_requirement' => 'none',
        ]);
        $item = CatalogItem::create([
            'store_id' => $this->store->id, 'name' => 'Polo Design', 'price' => 600,
            'listing_type' => 'made_to_order', 'service_id' => $service->id,
        ]);
        CatalogImage::create(['catalog_item_id' => $item->id, 'image_url' => 'https://img.test/non-primary.jpg', 'is_primary' => false]);
        $primary = CatalogImage::create(['catalog_item_id' => $item->id, 'image_url' => 'https://img.test/primary.jpg', 'is_primary' => true]);
        $order = $this->order(['service_id' => $service->id, 'catalog_item_id' => $item->id]);

        $this->assertSame([$primary->image_url], $this->refs($order));
    }

    public function test_customer_attached_references_always_win(): void
    {
        $service = Service::create([
            'store_id' => $this->store->id, 'name' => 'Polo Service', 'base_price' => 450,
            'image_url' => 'https://img.test/polo.jpg', 'measurement_requirement' => 'none',
        ]);
        $order = $this->order(['service_id' => $service->id], ['https://img.test/customer-photo.jpg']);

        $this->assertSame(['https://img.test/customer-photo.jpg'], $this->refs($order));
    }

    public function test_order_whose_service_has_no_image_returns_empty(): void
    {
        // A bare service with no image (e.g. "Alteration and Repairs for Barong") — nothing to stand in, so no block.
        $service = Service::create([
            'store_id' => $this->store->id, 'name' => 'Alteration and Repairs for Barong', 'base_price' => 300,
            'measurement_requirement' => 'none',
        ]);
        $order = $this->order(['service_id' => $service->id]);

        $this->assertSame([], $this->refs($order));
    }

    public function test_other_customers_order_stays_invisible(): void
    {
        $service = Service::create([
            'store_id' => $this->store->id, 'name' => 'Polo Service', 'base_price' => 450,
            'image_url' => 'https://img.test/polo.jpg', 'measurement_requirement' => 'none',
        ]);
        $other = User::factory()->create();
        $other->roles()->attach(Role::where('name', 'customer')->first());
        $order = $this->order(['service_id' => $service->id, 'customer_id' => $other->id]);

        $this->actingAs($this->customer)
            ->getJson("/api/v1/my-orders/{$order->id}")
            ->assertStatus(404);
    }
}
