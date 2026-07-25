# Fitting Feedback Field Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give staff/owner a structured place to record what a customer wants adjusted during a fitting, instead of it living only in generic free-text `notes` or staying purely verbal.

**Architecture:** One migration (nullable text column on `Appointment`), settable through both places `notes`/`outcome` are already settable: the general `UpdateAppointmentRequest`, and `AppointmentController::complete()`'s own inline validation (the natural moment a fitting's outcome gets recorded).

**Tech Stack:** Laravel 11 (PHPUnit).

## Global Constraints

- Single source of truth on `Appointment` — no duplicate field on `JobOrder`.
- Not type-constrained to `appointment_type = 'fitting'` at the DB/validation level — same convention as `garment_category`.
- Spec reference: `docs/superpowers/specs/2026-07-25-fitting-feedback-field-design.md`.

---

## Task 1: fitting_notes field

**Files:**
- Create: `database/migrations/2026_07_25_120000_add_fitting_notes_to_appointments_table.php`
- Modify: `app/Models/Appointment.php`
- Modify: `app/Http/Requests/Shop/UpdateAppointmentRequest.php`
- Modify: `app/Http/Controllers/Api/V1/AppointmentController.php`
- Test: `tests/Feature/Api/V1/AppointmentTest.php` (check first with `find tests/Feature/Api/V1 -iname "*Appointment*"` — if it doesn't exist, follow `JobOrderTest.php`'s `setUp()` conventions to create one, or add to whichever appointment test file actually exists)

**Interfaces:**
- Produces: `appointments.fitting_notes` (nullable text), settable via `PUT /shops/{shop}/appointments/{appointment}` (general update) and via `POST .../appointments/{appointment}/complete` (the completion flow — check the actual route/method name for "complete" in `routes/api.php` before writing this, don't guess the URL).

- [ ] **Step 1: Check for an existing Appointment test file**

Run: `find tests/Feature/Api/V1 -iname "*Appointment*"`
Use whatever you find; if nothing exists, create `tests/Feature/Api/V1/AppointmentTest.php` following `JobOrderTest.php`'s `setUp()` pattern (shop_owner role, shop, customer, service) adapted for appointments.

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
        Schema::table('appointments', function (Blueprint $table) {
            $table->text('fitting_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('fitting_notes');
        });
    }
};
```

Save as `database/migrations/2026_07_25_120000_add_fitting_notes_to_appointments_table.php`.

- [ ] **Step 3: Run the migration**

Run: `php artisan migrate`

- [ ] **Step 4: Write the failing tests**

Add two tests to the test file from Step 1 — one proving `fitting_notes` can be set via the general update endpoint, one proving it can be set via the `complete()` action alongside `outcome`. Look at how existing tests in that file (or `JobOrderTest.php` if the file is new) construct an `Appointment::create([...])` and call the update/complete routes, and match that exact style. The core assertions:

```php
    public function test_fitting_notes_can_be_set_via_general_update()
    {
        // Create an appointment (adapt fields to whatever this file's existing
        // tests/setUp already uses for creating one), then:
        // PUT to the appointment's update route with ['fitting_notes' => 'Take in the waist, shorten sleeves by 1 inch.']
        // assertStatus(200)
        // assertDatabaseHas('appointments', ['id' => $appointment->id, 'fitting_notes' => 'Take in the waist, shorten sleeves by 1 inch.'])
    }

    public function test_fitting_notes_can_be_set_when_completing_an_appointment()
    {
        // Create an in_progress appointment, then call the complete() route
        // (find its actual URL in routes/api.php first) with
        // ['outcome' => 'completed', 'fitting_notes' => 'Customer wants a looser collar.']
        // assertStatus(200)
        // assertDatabaseHas('appointments', ['id' => $appointment->id, 'fitting_notes' => 'Customer wants a looser collar.'])
    }
```

Write these out fully with real field values matching this codebase's actual `Appointment::create()` requirements (check `StoreAppointmentRequest`/existing tests for what's actually required) rather than leaving placeholders.

- [ ] **Step 5: Run tests to verify they fail**

Expected: both FAIL — `fitting_notes` isn't in `$fillable`/validated yet.

- [ ] **Step 6: Add to Appointment's fillable**

In `app/Models/Appointment.php`, add `'fitting_notes'` to the existing `protected $fillable` array.

- [ ] **Step 7: Add validation to UpdateAppointmentRequest**

In `app/Http/Requests/Shop/UpdateAppointmentRequest.php`, add:

```php
            'fitting_notes' => ['nullable', 'string', 'max:2000'],
```

- [ ] **Step 8: Add to complete()'s inline validation and update logic**

In `app/Http/Controllers/Api/V1/AppointmentController.php`, find the `complete()` method's inline `$request->validate([...])` call (the one alongside `'notes'`, `'job_order_id'`, `'measurement_id'`, `'outcome'`). Add:

```php
                'fitting_notes'  => ['nullable', 'string', 'max:2000'],
```

Then find the `$updateData = ['status' => 'completed'];` block right after, where `outcome` gets conditionally added (`if ($request->filled('outcome')) { $updateData['outcome'] = $request->outcome; }`). Add the same pattern:

```php
                if ($request->filled('fitting_notes')) {
                    $updateData['fitting_notes'] = $request->fitting_notes;
                }
```

- [ ] **Step 9: Run tests to verify they pass**

Expected: both PASS.

- [ ] **Step 10: Run the full test suite for the file used**

Expected: all tests PASS, no regressions.

- [ ] **Step 11: Commit**

```bash
git add database/migrations/2026_07_25_120000_add_fitting_notes_to_appointments_table.php app/Models/Appointment.php app/Http/Requests/Shop/UpdateAppointmentRequest.php app/Http/Controllers/Api/V1/AppointmentController.php
git add <the appointment test file you used>
git commit -m "feat: add structured fitting_notes field to appointments"
```
