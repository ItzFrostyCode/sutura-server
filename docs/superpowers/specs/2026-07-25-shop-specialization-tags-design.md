# Shop Specialization Tags — Design

## Context

SUTURA's own pitch is customers discovering shops by garment specialization. `ShopOwnerSubscription.md` lists "Manage Apparel Specializations" as an explicit Shop Owner responsibility. Nothing in the codebase lets a shop declare what it specializes in — `Shop.php`'s `$fillable` has no such field. The only categorization that exists is `Service::TYPE_*` (4 broad business-model types) and, as of the previous task, `JobOrder.garment_category` (per-order, not per-shop).

## Design

- Add `specializations` (JSON array, cast to `array`) to `Shop`.
- Reuses the exact same 5-value vocabulary already established for `garment_category`/`Appointment.garment_category` (`barong,gown,suit,filipiniana,uniform`) rather than inventing a third parallel list.
- Validation on `UpdateShopRequest`: `'specializations' => ['nullable', 'array'], 'specializations.*' => ['string', 'in:barong,gown,suit,filipiniana,uniform']`.
- Follows the existing `UpdateShopRequest::authorize()` pattern exactly — shop-level config (not branch/job-level) is already owner-exclusive (`$this->user()->id === $shop->owner_id`, excluding even branch_manager), matching `ShopOwnerSubscription.md`'s framing of this as specifically a Shop Owner (not branch manager) responsibility.

## Scope of this task

Backend only — the `specializations` field, validated and saveable through the existing `PUT /shops/{shop}` endpoint. The frontend settings-page UI to actually set it (a multi-select in `SettingsBasicInfo.tsx` or similar) and the customer-facing discovery/search that would eventually filter by it are both explicitly deferred: the settings UI is a small fast-follow once this lands, and discovery/search is a separate groupmate's task per this project's established scope split.
