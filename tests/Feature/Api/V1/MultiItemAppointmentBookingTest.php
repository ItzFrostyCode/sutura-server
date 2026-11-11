<?php

namespace Tests\Feature\Api\V1;

use App\Models\Appointment;
use App\Models\CatalogItem;
use App\Models\Role;
use App\Models\Service;
use App\Models\Store;
use App\Models\StoreBranch;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiItemAppointmentBookingTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Store $store;
    protected StoreBranch $branch;
    protected User $customer;
    protected Service $jerseyService;
    protected Service $poloService;
    protected CatalogItem $jerseyDesign;

    protected function setUp(): void
    {
        parent::setUp();

        $ownerRole = Role::create(['name' => 'store_owner', 'description' => 'Store Owner']);
        $this->owner = User::factory()->create();
        $this->owner->roles()->attach($ownerRole);

        $this->store = Store::create([
            'owner_id' => $this->owner->id,
            'name' => 'Sakuragi Sportswear',
            'slug' => 'sakuragi-sportswear',
            'address' => 'Illustre St',
            'city' => 'Davao City',
            'province' => 'Davao del Sur',
            'status' => 'approved',
        ]);

        $this->branch = StoreBranch::create([
            'store_id' => $this->store->id,
            'name' => 'Main Branch',
            'address' => 'Illustre St',
            'city' => 'Davao City',
            'status' => 'active',
            'verification_status' => 'verified',
        ]);

        $custRole = Role::create(['name' => 'customer', 'description' => 'Customer']);
        $this->customer = User::factory()->create();
        $this->customer->roles()->attach($custRole);

        $this->jerseyService = Service::create([
            'store_id' => $this->store->id,
            'name' => 'Sublimation Jersey',
            'service_category' => 'custom_tailoring',
            'base_price' => 450,
            'min_order_qty' => 10,
            'is_active' => true,
        ]);

        $this->poloService = Service::create([
            'store_id' => $this->store->id,
            'name' => 'Corporate Polo Shirt',
            'service_category' => 'custom_tailoring',
            'base_price' => 500,
            'min_order_qty' => 20,
            'is_active' => true,
        ]);

        $this->jerseyDesign = CatalogItem::create([
            'store_id' => $this->store->id,
            'service_id' => $this->jerseyService->id,
            'name' => 'Sakuragi Special Jersey',
            'price' => 550,
            'is_active' => true,
        ]);
    }

    public function test_a_booking_cannot_carry_more_than_one_service(): void
    {
        $scheduledAt = Carbon::now()->addDays(2)->setHour(10)->setMinute(0)->setSecond(0);

        // Two needs at once used to be a single multi-item booking; a booking is now for one service
        // (and optionally one of its designs) — a second need is a second appointment (items is capped at 1).
        $payload = [
            'kind' => 'service',
            'store_branch_id' => $this->branch->id,
            'scheduled_at' => $scheduledAt->toDateTimeString(),
            'items' => [
                ['service_id' => $this->jerseyService->id, 'catalog_item_id' => $this->jerseyDesign->id, 'item_name' => $this->jerseyDesign->name, 'quantity' => 15, 'selected_color' => 'Green / White / Black', 'selected_size' => 'M'],
                ['service_id' => $this->poloService->id, 'item_name' => $this->poloService->name, 'quantity' => 25, 'selected_color' => 'Navy Blue', 'selected_size' => 'L'],
            ],
        ];

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/catalog/{$this->store->slug}/book", $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items']);
    }

    public function test_booking_item_quantity_must_be_at_least_one(): void
    {
        $scheduledAt = Carbon::now()->addDays(2)->setHour(14)->setMinute(0)->setSecond(0);

        // There is no per-service minimum on a booking anymore — a single item just needs a quantity of at
        // least one. Guard that floor: a zero-quantity line is rejected.
        $payload = [
            'kind' => 'service',
            'store_branch_id' => $this->branch->id,
            'scheduled_at' => $scheduledAt->toDateTimeString(),
            'items' => [
                [
                    'service_id' => $this->jerseyService->id,
                    'item_name' => $this->jerseyService->name,
                    'quantity' => 0,
                ],
            ],
        ];

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/catalog/{$this->store->slug}/book", $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.quantity']);
    }

    public function test_shop_and_customer_can_retrieve_appointment_items(): void
    {
        $scheduledAt = Carbon::now()->addDays(3)->setHour(9)->setMinute(0)->setSecond(0);

        $appointment = $this->store->appointments()->create([
            'customer_id' => $this->customer->id,
            'store_branch_id' => $this->branch->id,
            'service_id' => $this->jerseyService->id,
            'appointment_type' => 'consultation',
            'kind' => 'service',
            'intake_channel' => 'online',
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => 30,
            'status' => 'pending',
        ]);

        $appointment->items()->create([
            'service_id' => $this->jerseyService->id,
            'item_name' => 'Jersey Design A',
            'quantity' => 30,
            'selected_color' => 'White / Green',
            'add_ons' => ['Embroidered Patch'],
        ]);

        $appointment->items()->create([
            'service_id' => $this->poloService->id,
            'item_name' => 'Polo Uniform B',
            'quantity' => 20,
            'selected_color' => 'Black',
        ]);

        // Customer detail view
        $custResponse = $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/my-appointments/{$appointment->id}");

        $custResponse->assertStatus(200);
        $this->assertCount(2, $custResponse->json('data.items'));
        $this->assertEquals(30, $custResponse->json('data.items.0.quantity'));

        // Shop owner index view
        $ownerResponse = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/stores/{$this->store->id}/appointments");

        $ownerResponse->assertStatus(200);
        $shopApt = collect($ownerResponse->json('data'))->firstWhere('id', $appointment->id);
        $this->assertNotNull($shopApt);
        $this->assertCount(2, $shopApt['items']);
    }

    public function test_customer_can_book_service_and_catalog_design_on_same_date_at_different_times(): void
    {
        $targetDate = Carbon::now()->addDays(5)->setHour(9)->setMinute(0)->setSecond(0);

        // 1. First booking: Service at 9:00 AM
        $payload1 = [
            'kind' => 'service',
            'store_branch_id' => $this->branch->id,
            'service_id' => $this->jerseyService->id,
            'scheduled_at' => $targetDate->copy()->setHour(9)->setMinute(0)->toDateTimeString(),
            'notes' => 'First booking for sublimation jersey service',
            'items' => [
                [
                    'service_id' => $this->jerseyService->id,
                    'item_name' => $this->jerseyService->name,
                    'quantity' => 10,
                ],
            ],
        ];

        $res1 = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/catalog/{$this->store->slug}/book", $payload1);

        $res1->assertStatus(201);
        $res1->assertJsonPath('success', true);
        $this->assertDatabaseHas('appointments', [
            'id' => $res1->json('data.id'),
            'service_id' => $this->jerseyService->id,
            'customer_id' => $this->customer->id,
        ]);

        // 2. Second booking: Specific Catalog Design linked to that service at 2:00 PM on the SAME DATE
        $payload2 = [
            'kind' => 'service',
            'store_branch_id' => $this->branch->id,
            'service_id' => $this->jerseyService->id,
            'catalog_item_id' => $this->jerseyDesign->id,
            'scheduled_at' => $targetDate->copy()->setHour(14)->setMinute(0)->toDateTimeString(),
            'notes' => 'Second booking for specific jersey design',
            'items' => [
                [
                    'service_id' => $this->jerseyService->id,
                    'catalog_item_id' => $this->jerseyDesign->id,
                    'item_name' => $this->jerseyDesign->name,
                    'quantity' => 12,
                ],
            ],
        ];

        $res2 = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/catalog/{$this->store->slug}/book", $payload2);

        $res2->assertStatus(201);
        $res2->assertJsonPath('success', true);
        $this->assertDatabaseHas('appointments', [
            'id' => $res2->json('data.id'),
            'catalog_item_id' => $this->jerseyDesign->id,
            'customer_id' => $this->customer->id,
        ]);

        // Verify customer now holds both appointments for the same date
        $sameDayCount = Appointment::where('customer_id', $this->customer->id)
            ->whereDate('scheduled_at', $targetDate->toDateString())
            ->count();
        $this->assertEquals(2, $sameDayCount);
    }

    public function test_booking_fails_with_409_when_attempting_overlapping_time_on_same_date(): void
    {
        $targetDate = Carbon::now()->addDays(6)->setHour(10)->setMinute(0)->setSecond(0);

        // 1. First booking: 10:00 AM (default duration 60 mins -> ends at 11:00 AM)
        $payload1 = [
            'kind' => 'service',
            'store_branch_id' => $this->branch->id,
            'service_id' => $this->jerseyService->id,
            'scheduled_at' => $targetDate->toDateTimeString(),
            'items' => [
                [
                    'service_id' => $this->jerseyService->id,
                    'item_name' => $this->jerseyService->name,
                    'quantity' => 10,
                ],
            ],
        ];

        $res1 = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/catalog/{$this->store->slug}/book", $payload1);
        $res1->assertStatus(201);

        // 2. Second booking attempting overlapping time: 10:15 AM on same date (consultation is 30 mins: 10:00 - 10:30)
        $payload2 = [
            'kind' => 'service',
            'store_branch_id' => $this->branch->id,
            'service_id' => $this->jerseyService->id,
            'catalog_item_id' => $this->jerseyDesign->id,
            'scheduled_at' => $targetDate->copy()->setHour(10)->setMinute(15)->toDateTimeString(),
            'items' => [
                [
                    'service_id' => $this->jerseyService->id,
                    'catalog_item_id' => $this->jerseyDesign->id,
                    'item_name' => $this->jerseyDesign->name,
                    'quantity' => 10,
                ],
            ],
        ];

        $res2 = $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/catalog/{$this->store->slug}/book", $payload2);

        $res2->assertStatus(409);
        $res2->assertJsonPath('success', false);
    }
}
