# Fitting Feedback Field — Design

## Context

The research doc names "Alterations (if needed) — Adjustments based on fitting feedback" as its own tracked stage, and the use-case doc lists "Manage Fitting Session" / "Perform Adjustments/Alteration" as distinct staff actions. Checked `Appointment`'s fillable: it has a generic `outcome` field (`completed,rescheduled,no_show,converted_to_job,cancelled` — an appointment-lifecycle status, unrelated) and a generic `notes` field, but nothing structured for "what specifically the customer wants changed." Right now, if a customer says "sleeves too long, take in the waist" during a fitting, that either goes into the generic free-text `notes` (easy to lose in a wall of unrelated text) or stays purely verbal.

## Design

- Add `fitting_notes` (nullable text) to `Appointment`.
- Settable through the existing `UpdateAppointmentRequest`/`AppointmentController::update()` flow — same mechanism `outcome`/`notes` already use, no new endpoint.
- Single source of truth stays on `Appointment` — no duplicate field on `JobOrder`. A job order can look up its fitting appointments' notes through the existing `JobOrder::appointments()` relation (already `hasMany(Appointment::class)`), so there's nothing to keep in sync.
- Not type-constrained at the DB/validation level to `appointment_type = 'fitting'` — same convention as `garment_category`, a generally-available nullable field, not hard-coupled to one appointment type.

## Scope of this task

Backend only — the field itself, saveable through the existing update endpoint. Surfacing it on the Job Detail page (reading through the job's linked fitting appointments) is a frontend fast-follow, not part of this task.
