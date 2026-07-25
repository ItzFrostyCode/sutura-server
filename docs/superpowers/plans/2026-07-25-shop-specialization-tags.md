# Shop Specialization Tags Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a shop declare what garment types it specializes in (Barong, Gown, Suit, Filipiniana, Uniform), reusing the vocabulary already established for `JobOrder.garment_category`.

**Architecture:** One migration (JSON array column), one fillable/cast addition, one validation rule on the existing shop-update endpoint.

**Tech Stack:** Laravel 11 (PHPUnit).

## Global Constraints

- Same 5-value vocabulary as `Appointment.garment_category`/`JobOrder.garment_category`: `barong,gown,suit,filipiniana,uniform` — no new parallel list.
- Owner-exclusive, matching `UpdateShopRequest::authorize()`'s existing pattern (`$this->user()->id === $shop->owner_id`) — this is shop-level config, not a branch/job action.
- Backend-only scope — no frontend UI in this task (deferred as a fast-follow).
- Spec reference: `docs/superpowers/specs/2026-07-25-shop-specialization-tags-design.md`.

---

## Task 1: specializations field on Shop

**Files:**
- Create: `database/migrations/2026_07_25_110000_add_specializations_to_shops_table.php`
- Modify: `app/Models/Shop.php`
- Modify: `app/Http/Requests/Shop/UpdateShopRequest.php`
- Test: `tests/Feature/Api/V1/ShopTest.php` (create this file if it doesn't already exist — check first with a quick `find`/`ls` before assuming; if a shop-update test file already exists under a different name, add to that one instead and note the actual filename in your report)

**Interfaces:**
- Produces: `shops.specializations` (nullable JSON array column, cast to `array` on the model).

- [ ] **Step 1: Check for an existing Shop test file**

Run: `find tests/Feature/Api/V1 -iname "*Shop*"`
If a file already exists (e.g. `ShopTest.php`), add the new test method there instead of creating a new file, and adjust the rest of this task's file references accordingly.

- [ ] **Step 2: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->json('specializations')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('specializations');
        });
    }
};
```

Save as `database/migrations/2026_07_25_110000_add_specializations_to_shops_table.php`.

- [ ] **Step 3: Run the migration**

Run: `php artisan migrate`
Expected: applies cleanly.

- [ ] **Step 4: Write the failing test**

Add to the Shop test file located/created in Step 1 (adapt the `setUp()`/shop-creation boilerplate to match whatever convention that file already uses if it exists, otherwise follow the pattern from `tests/Feature/Api/V1/JobOrderTest.php`'s `setUp()` for creating a shop_owner + shop):

```php
    public function test_owner_can_set_shop_specializations()
    {
        $response = $this->actingAs($this->user)->putJson("/api/v1/shops/{$this->shop->id}", [
            'specializations' => ['barong', 'gown'],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('shops', [
            'id' => $this->shop->id,
        ]);
        $this->shop->refresh();
        $this->assertEquals(['barong', 'gown'], $this->shop->specializations);
    }

    public function test_shop_specializations_rejects_an_invalid_value()
    {
        $response = $this->actingAs($this->user)->putJson("/api/v1/shops/{$this->shop->id}", [
            'specializations' => ['barong', 'not_a_real_garment_type'],
        ]);

        $response->assertStatus(422);
    }
```

- [ ] **Step 5: Run tests to verify they fail**

Run the two new test methods.
Expected: both FAIL — `specializations` isn't in `$fillable`/validated yet.

- [ ] **Step 6: Add to Shop's fillable and casts**

In `app/Models/Shop.php`, add `'specializations'` to the existing `protected $fillable` array, and add to `protected $casts`:

```php
        'specializations' => 'array',
```

- [ ] **Step 7: Add validation to UpdateShopRequest**

In `app/Http/Requests/Shop/UpdateShopRequest.php`, add to the `rules()` array:

```php
            'specializations' => ['nullable', 'array'],
            'specializations.*' => ['string', 'in:barong,gown,suit,filipiniana,uniform'],
```

- [ ] **Step 8: Run tests to verify they pass**

Run the two new test methods.
Expected: both PASS.

- [ ] **Step 9: Run the full Shop test suite for regressions**

Run whichever full test file the new tests live in.
Expected: all tests PASS.

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_07_25_110000_add_specializations_to_shops_table.php app/Models/Shop.php app/Http/Requests/Shop/UpdateShopRequest.php
git add <the shop test file you used — name it explicitly per what Step 1 found>
git commit -m "feat: let a shop declare its garment specializations"
```
