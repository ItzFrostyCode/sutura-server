<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\Role;
use App\Models\Service;
use App\Models\Store;
use App\Models\StoreBranch;
use App\Models\User;
use App\Support\OfferingHealth;
use App\Support\ServiceQuestions;
use Database\Seeders\ThreadNeedleCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThreadNeedleCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create();
        $owner->roles()->attach(Role::create(['name' => 'store_owner', 'description' => 'Store Owner']));
        $this->store = Store::create(['owner_id' => $owner->id, 'name' => 'Thread & Needle Tailoring', 'slug' => 'thread-needle', 'status' => 'approved', 'address' => 'Poblacion', 'city' => 'Davao City', 'province' => 'Davao del Sur']);
        StoreBranch::create(['store_id' => $this->store->id, 'name' => 'Main', 'slug' => 'tn-main', 'address' => 'Poblacion', 'city' => 'Davao City', 'is_main' => true, 'status' => 'active', 'verification_status' => 'verified']);
    }

    /** The seed goes through the owner's own controllers, passes the cross-check, and can run again without duplicates. */
    public function test_the_seed_saves_every_case_cleanly_and_can_run_twice(): void
    {
        $this->seed(ThreadNeedleCatalogSeeder::class);
        $this->seed(ThreadNeedleCatalogSeeder::class);

        $data = require database_path('seeders/data/thread_needle_catalog.php');
        $this->assertSame(count($data['services']), Service::where('store_id', $this->store->id)->count());
        $this->assertSame(count($data['designs']), CatalogItem::where('store_id', $this->store->id)->where('is_active', true)->count());
        $this->assertSame([], array_values(array_filter(OfferingHealth::forStore($this->store), fn ($i) => $i['level'] === 'error')));

        $types = fn (string $name) => Service::where('name', $name)->value('service_types');
        $this->assertSame(['bulk_sublimation', 'custom_tailoring'], $types('Nursing & Medical Scrub Suit'), 'Individual and group');
        $this->assertSame(['bulk_sublimation'], $types('Basketball Jersey & Shorts (Cut and Sew)'), 'Group only');
        $this->assertSame(['alteration_repair'], $types('Name & Logo Embroidery on Your Uniform'), 'Embroidery on own clothes = short flow');
    }

    /** The shop's older services are tidied: a duplicate is archived, a confusing one renamed, and a design moved to the right service. */
    public function test_older_services_are_tidied_without_touching_open_work(): void
    {
        $old = fn (array $a) => Service::create(['store_id' => $this->store->id, 'base_price' => 100, 'description' => 'Old', 'status' => 'published'] + $a);
        $old(['name' => 'Embroidery & Logo Digitizing', 'service_category' => 'garment_customization']);
        $jerseys = $old(['name' => 'Custom Sublimation Team Jerseys', 'service_category' => 'uniform_production', 'capabilities' => ['bulk_orders'], 'min_order_qty' => 10,
            'custom_fields' => [['name' => 'team_name', 'label' => 'Team/Organization Name', 'type' => 'short_text']]]);
        $suits = $old(['name' => 'Bespoke Suit Tailoring', 'service_category' => 'custom_tailoring']);
        CatalogItem::create(['store_id' => $this->store->id, 'service_id' => $suits->id, 'name' => 'Tailored Chino Trousers', 'price' => 900, 'is_active' => false, 'sizes' => ['S', 'XXL']]);

        $this->seed(ThreadNeedleCatalogSeeder::class);

        $this->assertSoftDeleted('services', ['name' => 'Embroidery & Logo Digitizing']);
        $fresh = $jerseys->fresh();
        $this->assertSame('Team Sports Jerseys (Cycling, Volleyball, Esports)', $fresh->name);
        $this->assertNull($fresh->custom_fields, 'The group form already asks the team name');
        $this->assertSame(['top', 'both', 'both'], $fresh->pricing()->orderBy('id')->pluck('covers')->all());
        $chinos = CatalogItem::where('name', 'Tailored Chino Trousers')->first();
        $this->assertSame('Slacks & Trousers (Made to Measure)', $chinos->service->name);
        $this->assertSame(['S', 'M', 'L', 'XL', '2XL'], $chinos->sizes);
    }

    public function test_older_question_shapes_read_as_the_current_one(): void
    {
        $s = Service::create(['store_id' => $this->store->id, 'name' => 'Old jerseys', 'base_price' => 100, 'custom_fields' => [
            ['name' => 'fabric', 'label' => 'Fabric', 'type' => 'dropdown', 'required' => 'true', 'options' => ['Mesh', ' ', 'mesh', 'Cotton']],
            ['label' => 'Team', 'type' => 'short_text'],
            ['label' => 'Team', 'type' => 'select'],
        ]]);

        $q = $s->fresh()->custom_fields;
        $this->assertSame(['id' => 'fabric', 'label' => 'Fabric', 'type' => 'select', 'required' => true, 'options' => ['Mesh', 'Cotton']], $q[0]);
        $this->assertSame('text', $q[1]['type']);
        $this->assertSame(['select', []], [$q[2]['type'], $q[2]['options']], 'Kept as a choice so the publish check can ask for its choices');
        $this->assertNotSame($q[1]['id'], $q[2]['id'], 'Every question has its own id');
        $this->assertSame('select', ServiceQuestions::normalize([['label' => 'Size', 'type' => 'radio', 'options' => ['S', 'M']]], ServiceQuestions::ROSTER_TYPES)[0]['type']);
    }
}
