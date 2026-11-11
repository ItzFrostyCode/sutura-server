<?php

namespace Tests\Feature\Api\V1;

use App\Models\Role;
use App\Models\Service;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\StoreSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Services decisions S2, S3, S18 and B6-O11 (reference-repos/Services/11, candidate tests AT-SV06, AT-SV07, AT-SV10,
 * AT-SV12): four statuses, publish validation, archive/restore, price display, owner-only writes, plan limit.
 */
class ServiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->owner->roles()->attach(Role::create(['name' => 'store_owner', 'description' => 'Store Owner']));
        $this->store = Store::create(['owner_id' => $this->owner->id, 'name' => 'Lifecycle Shop', 'slug' => 'lifecycle-shop', 'address' => '1 St',
            'city' => 'Davao City', 'province' => 'Davao del Sur', 'status' => 'approved', 'is_hidden' => false]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/stores/{$this->store->id}/services{$path}";
    }

    private function full(array $over = []): array
    {
        return $over + ['name' => 'Barong Tailoring', 'description' => 'Made to measure.', 'service_category' => 'custom_tailoring', 'base_price' => 1500];
    }

    public function test_a_new_service_starts_as_a_draft_and_is_not_public(): void
    {
        $id = $this->actingAs($this->owner)->postJson($this->url(), $this->full())->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->assertFalse(Service::find($id)->is_active);
        $this->getJson("/api/v1/public/stores/{$this->store->slug}/services")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_publishing_needs_name_group_description_and_a_valid_price_display(): void
    {
        $r = $this->actingAs($this->owner)->postJson($this->url(), ['name' => 'Bare', 'status' => 'published'])->assertStatus(422);
        $r->assertJsonValidationErrors(['service_category', 'description']);
        $this->assertSame(0, Service::count(), 'a refused publish saves nothing');

        $this->actingAs($this->owner)->postJson($this->url(), $this->full(['base_price' => null, 'price_display' => 'fixed', 'status' => 'published']))
            ->assertStatus(422)->assertJsonValidationErrors(['price_display']);
        $this->actingAs($this->owner)->postJson($this->url(), $this->full(['base_price' => null, 'price_display' => 'quotation', 'status' => 'published']))
            ->assertCreated()->assertJsonPath('data.status', 'published')->assertJsonPath('data.price_display', 'quotation');
    }

    public function test_booking_questions_must_be_complete_to_publish(): void
    {
        $q = ['custom_fields' => [['id' => 'c1', 'label' => 'Collar', 'type' => 'select', 'options' => []]]];
        $this->actingAs($this->owner)->postJson($this->url(), $this->full($q + ['status' => 'published']))->assertStatus(422);
        $q['custom_fields'][0]['options'] = ['Classic'];
        $this->actingAs($this->owner)->postJson($this->url(), $this->full($q + ['status' => 'published']))->assertCreated();
    }

    public function test_publish_unpublish_archive_and_restore_to_unpublished(): void
    {
        $id = $this->actingAs($this->owner)->postJson($this->url(), $this->full())->json('data.id');
        $this->putJson($this->url("/$id"), ['name' => 'Barong Tailoring', 'status' => 'published'])->assertOk()->assertJsonPath('data.status', 'published');
        $this->getJson("/api/v1/public/stores/{$this->store->slug}/services")->assertJsonCount(1, 'data');
        $this->putJson($this->url("/$id"), ['name' => 'Barong Tailoring', 'status' => 'unpublished'])->assertJsonPath('data.status', 'unpublished');
        $this->getJson("/api/v1/public/stores/{$this->store->slug}/services")->assertJsonCount(0, 'data');

        $this->deleteJson($this->url("/$id"))->assertOk();
        $this->assertSoftDeleted('services', ['id' => $id]);
        $this->assertSame('archived', Service::withTrashed()->find($id)->status);
        $this->actingAs($this->owner)->getJson($this->url())->assertJsonCount(0, 'data');
        $this->getJson($this->url('?include_archived=1'))->assertJsonCount(1, 'data');

        $this->postJson($this->url("/$id/restore"))->assertOk()->assertJsonPath('data.status', 'unpublished');
        $this->assertFalse(Service::find($id)->is_active, 'restoring never republishes on its own');
    }

    public function test_the_older_is_active_switch_still_maps_to_the_lifecycle(): void
    {
        $id = $this->actingAs($this->owner)->postJson($this->url(), $this->full(['is_active' => true]))->assertCreated()->assertJsonPath('data.status', 'published')->json('data.id');
        $this->putJson($this->url("/$id"), ['name' => 'Barong Tailoring', 'is_active' => false])->assertJsonPath('data.status', 'unpublished');
    }

    public function test_price_display_defaults_from_the_price(): void
    {
        $this->actingAs($this->owner)->postJson($this->url(), $this->full())->assertJsonPath('data.price_display', 'starting');
        $this->postJson($this->url(), $this->full(['name' => 'Repairs', 'base_price' => null]))->assertJsonPath('data.price_display', 'quotation');
    }

    public function test_only_the_owner_writes_services(): void
    {
        $manager = User::factory()->create();
        $manager->roles()->attach(Role::create(['name' => 'branch_manager', 'description' => 'Branch Manager']));
        $this->actingAs($manager)->postJson($this->url(), $this->full())->assertForbidden();
    }

    public function test_minus_one_means_unlimited_services(): void
    {
        $plan = SubscriptionPlan::create(['name' => 'Premium', 'slug' => 'premium-test', 'price_monthly' => 0, 'max_services' => -1, 'max_staff' => -1, 'max_branches' => -1, 'is_active' => true]);
        StoreSubscription::create(['store_id' => $this->store->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $this->actingAs($this->owner)->postJson($this->url(), ['name' => 'Only a name', 'status' => 'draft'])->assertCreated()->assertJsonPath('data.status', 'draft');
        $this->postJson($this->url(), ['name' => 'Another'])->assertCreated();
    }

    public function test_the_admin_set_plan_limit_is_enforced_and_archived_services_do_not_count(): void
    {
        $plan = SubscriptionPlan::create(['name' => 'Basic', 'slug' => 'basic-test', 'price_monthly' => 0, 'max_services' => 1, 'max_staff' => 5, 'max_branches' => 1, 'is_active' => true]);
        StoreSubscription::create(['store_id' => $this->store->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $first = $this->actingAs($this->owner)->postJson($this->url(), $this->full())->assertCreated()->json('data.id');
        $this->postJson($this->url(), $this->full(['name' => 'Second']))->assertForbidden()->assertJsonPath('code', 'plan_limit_reached');
        $this->deleteJson($this->url("/$first"))->assertOk();
        $this->postJson($this->url(), $this->full(['name' => 'Second']))->assertCreated();
        $this->postJson($this->url("/$first/restore"))->assertForbidden(); // restoring would exceed the limit
    }
}
