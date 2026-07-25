# Garment Category Propagation — Design

## Context

`Appointment.garment_category` exists (`in:barong,gown,suit,filipiniana,uniform`) — a customer can specify this when booking online. `JobOrder` has no equivalent field. `JobOrderController::store()` already carries `reference_images`/`reference_link` over from a linked appointment when a job order is created from one (see the existing comment: "carry over onto the job the assigned staff actually work from, instead of being stranded on an appointment record nobody looks at again"). `garment_category` isn't part of that carry-over, so the one piece of classification a customer gave at booking time currently evaporates the moment their appointment becomes a real job — it never reaches the job record, analytics, or (eventually) shop-level specialization reporting.

## Design

- Add `garment_category` (nullable string) to `job_orders` — same shape as `Appointment`'s column (`->nullable()`, no DB-level enum, validated at the app layer).
- Add to `JobOrder::$fillable`.
- Add identical validation to `StoreJobOrderRequest`/`UpdateJobOrderRequest`: `['nullable', 'string', 'in:barong,gown,suit,filipiniana,uniform']` — same value set as `Appointment`'s, so a job order and the appointment it came from always speak the same vocabulary.
- In `JobOrderController::store()`, carry `garment_category` over from the linked appointment when not explicitly provided in the request, using the exact same `if (empty($validated[...]) && !empty($appointment->...))` pattern already used for `reference_images`/`reference_link` in that method — not a new pattern, just extending the existing one.
- A walk-in job order created with no linked appointment can still have `garment_category` set directly (the validation allows it standalone), for staff to classify a purely in-person order the same way.

## Out of scope

- Using this field for shop-level specialization/discovery (a separate, larger follow-on item).
- Backfilling `garment_category` onto existing job orders created before this field existed — new field, starts null on old records, no migration data-fill.
