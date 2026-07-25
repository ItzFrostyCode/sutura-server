# Garment Category Propagation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop `Appointment.garment_category` from evaporating the moment an appointment becomes a `JobOrder` — add the same field to `JobOrder` and carry it over automatically, using the exact carry-over pattern this codebase already uses for `reference_images`/`reference_link`.

**Architecture:** One migration, one fillable addition, matching validation on both Store/Update requests, one small addition to the existing appointment-carry-over block in `JobOrderController::store()`.

**Tech Stack:** Laravel 11 (PHPUnit).

## Global Constraints

- `garment_category`'s valid values must exactly match `Appointment`'s: `barong,gown,suit,filipiniana,uniform` — a job order and the appointment it came from must speak the same vocabulary, or the whole point of carrying the value over is defeated.
- No backfill of existing job orders — new field starts `null` on records that predate it.
- Spec reference: `docs/superpowers/specs/2026-07-25-garment-category-propagation-design.md`.

---

## Task 1: garment_category field + carry-over

**Files:**
- Create: `database/migrations/2026_07_25_100000_add_garment_category_to_job_orders_table.php`
- Modify: `app/Models/JobOrder.php`
- Modify: `app/Http/Requests/Shop/StoreJobOrderRequest.php`
- Modify: `app/Http/Requests/Shop/UpdateJobOrderRequest.php`
- Modify: `app/Http/Controllers/Api/V1/JobOrderController.php`
- Test: `tests/Feature/Api/V1/JobOrderTest.php`

**Interfaces:**
- Produces: `job_orders.garment_category` (nullable string), validated `in:barong,gown,suit,filipiniana,uniform` on both create and update.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('garment_category')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('garment_category');
        });
    }
};
```

Save as `database/migrations/2026_07_25_100000_add_garment_category_to_job_orders_table.php`.

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: migration applies cleanly, no errors.

- [ ] **Step 3: Write the failing test**

Add to `tests/Feature/Api/V1/JobOrderTest.php`:

```php
    public function test_garment_category_carries_over_from_linked_appointment()
    {
        $appointment = \App\Models\Appointment::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'appointment_type' => 'fitting',
            'intake_channel' => 'online',
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 30,
            'status' => 'confirmed',
            'garment_category' => 'barong',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/shops/{$this->shop->id}/jobs", [
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'total_amount' => 5000,
            'balance' => 5000,
            'status' => 'pending',
            'appointment_id' => $appointment->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('job_orders', [
            'id' => $response->json('data.id'),
            'garment_category' => 'barong',
        ]);
    }

    public function test_garment_category_can_be_set_directly_without_an_appointment()
    {
        $response = $this->actingAs($this->user)->postJson("/api/v1/shops/{$this->shop->id}/jobs", [
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->id,
            'total_amount' => 5000,
            'balance' => 5000,
            'status' => 'pending',
            'garment_category' => 'gown',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('job_orders', [
            'id' => $response->json('data.id'),
            'garment_category' => 'gown',
        ]);
    }
```

- [ ] **Step 4: Run tests to verify they fail**

Run: `php artisan test --filter=test_garment_category_carries_over_from_linked_appointment`
Run: `php artisan test --filter=test_garment_category_can_be_set_directly_without_an_appointment`
Expected: both FAIL — `garment_category` isn't in `$fillable`/validated yet, so it's silently dropped from `create()`.

- [ ] **Step 5: Add to JobOrder's fillable**

In `app/Models/JobOrder.php`, add `'garment_category'` to the existing `protected $fillable` array (anywhere in the list; place it near `'material_source'` for readability).

- [ ] **Step 6: Add validation to StoreJobOrderRequest**

In `app/Http/Requests/Shop/StoreJobOrderRequest.php`, add this rule to the `rules()` array (near `'reference_link'`/`'material_source'`):

```php
            'garment_category' => ['nullable', 'string', 'in:barong,gown,suit,filipiniana,uniform'],
```

- [ ] **Step 7: Add validation to UpdateJobOrderRequest**

In `app/Http/Requests/Shop/UpdateJobOrderRequest.php`, add the same rule (near `'material_source'`):

```php
            'garment_category' => ['nullable', 'string', 'in:barong,gown,suit,filipiniana,uniform'],
```

- [ ] **Step 8: Add the carry-over**

In `app/Http/Controllers/Api/V1/JobOrderController.php`, in `store()`, find the existing appointment-carry-over block:

```php
                if (empty($validated['reference_images']) && !empty($appointment->reference_images)) {
                    $validated['reference_images'] = $appointment->reference_images;
                }
                if (empty($validated['reference_link']) && !empty($appointment->reference_link)) {
                    $validated['reference_link'] = $appointment->reference_link;
                }
```

Add a third carry-over line directly after it, same pattern:

```php
                if (empty($validated['garment_category']) && !empty($appointment->garment_category)) {
                    $validated['garment_category'] = $appointment->garment_category;
                }
```

- [ ] **Step 9: Run tests to verify they pass**

Run: `php artisan test --filter=test_garment_category_carries_over_from_linked_appointment`
Run: `php artisan test --filter=test_garment_category_can_be_set_directly_without_an_appointment`
Expected: both PASS.

- [ ] **Step 10: Run the full JobOrder test suite for regressions**

Run: `php artisan test tests/Feature/Api/V1/JobOrderTest.php`
Expected: all tests PASS.

- [ ] **Step 11: Commit**

```bash
git add database/migrations/2026_07_25_100000_add_garment_category_to_job_orders_table.php app/Models/JobOrder.php app/Http/Requests/Shop/StoreJobOrderRequest.php app/Http/Requests/Shop/UpdateJobOrderRequest.php app/Http/Controllers/Api/V1/JobOrderController.php tests/Feature/Api/V1/JobOrderTest.php
git commit -m "feat: carry garment_category from appointment onto the job order it creates"
```
