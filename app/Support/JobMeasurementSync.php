<?php

namespace App\Support;

use App\Models\JobOrder;
use App\Models\Measurement;
use App\Models\Store;

class JobMeasurementSync
{
    /**
     * Synchronize body measurements and sheets from a job order's custom_order_data
     * into the customer's measurement profiles in the database and link measurement_id.
     *
     * $source records who provided the measurements: customer self-service input
     * passes 'customer'; shop-entered data (job creation/updates, backfill) keeps
     * the 'store_owner' default.
     */
    /**
     * The numbers are the same. A profile saved before profiles carried their part (`_area`) and unit (`_unit`) is not
     * "changed" just because the order now says so: it keeps its version instead of getting a new one on the next save.
     */
    private static function sameMetrics(?array $saved, array $new): bool
    {
        $saved ??= [];
        foreach (['_area', '_unit'] as $key) {
            if (! array_key_exists($key, $saved)) {
                unset($new[$key]);
            }
        }

        return $saved === $new;
    }

    public static function syncForJob(Store $store, JobOrder $jobOrder, string $source = 'store_owner'): ?Measurement
    {
        $customData = $jobOrder->custom_order_data ?? [];
        $individual = $customData['individual_measurements'] ?? [];

        $metrics = [];
        if (is_array($individual)) {
            foreach ($individual as $k => $v) {
                if ($k !== 'unit' && $v !== null && trim((string) $v) !== '') {
                    $metrics[$k] = trim((string) $v);
                }
            }
        }

        $sheets = [];
        if (! empty($customData['measurement_sheets']) && is_array($customData['measurement_sheets'])) {
            $sheets = array_values(array_filter($customData['measurement_sheets'], fn ($u) => ! empty($u)));
        }

        // If no typed metrics and no sheet photos, there is nothing to store as a profile
        if (empty($metrics) && empty($sheets)) {
            return null;
        }

        $customerId = $jobOrder->customer_id;
        if (! $customerId) {
            return null;
        }

        // What was measured (top / bottom / both) and in which unit travel with the profile; without them a centimetre
        // profile would be read as inches and the shop could not tell a top-only profile from a full one.
        if ($metrics !== []) {
            $area = $customData['measure_area'] ?? null;
            if (in_array($area, ['top', 'bottom', 'both'], true)) {
                $metrics['_area'] = $area;
            }
            if (is_array($individual) && in_array($individual['unit'] ?? null, ['in', 'cm'], true)) {
                $metrics['_unit'] = $individual['unit'];
            }
        }

        $garment = ! empty($jobOrder->garment_category)
            ? ucfirst(str_replace('_', ' ', $jobOrder->garment_category))
            : (! empty($jobOrder->service?->name) ? ucfirst($jobOrder->service->name) : 'Standard');
        // The customer who orders is not always the one who wears it: a named wearer gets a profile of their own,
        // so measuring a child never replaces the parent's profile for the same garment.
        $wearer = trim((string) ($customData['wearer_name'] ?? ''));
        $profileName = $wearer !== '' ? "{$wearer} — {$garment} Profile" : "{$garment} Profile";

        // 1. Check if job already has a valid linked measurement
        if ($jobOrder->measurement_id) {
            $existing = Measurement::where('store_id', $store->id)
                ->where('customer_id', $customerId)
                ->where('id', $jobOrder->measurement_id)
                ->first();

            if ($existing) {
                // If metrics and sheets are identical, keep it as is
                if (self::sameMetrics($existing->metrics, $metrics) && $existing->photo_urls === $sheets) {
                    return $existing;
                }

                // If superseded_at is null, supersede and create the next version
                if ($existing->superseded_at === null) {
                    $existing->update(['superseded_at' => now()]);
                    $next = $store->measurements()->create([
                        'customer_id' => $customerId,
                        'source' => $source,
                        'profile_name' => $existing->profile_name,
                        'version' => $existing->version + 1,
                        'metrics' => $metrics,
                        'photo_urls' => $sheets,
                        'notes' => 'Updated from Job Order ' . $jobOrder->order_number,
                        'status' => 'finalized',
                    ]);
                    $jobOrder->update(['measurement_id' => $next->id]);
                    return $next;
                }
            }
        }

        // 2. Look for an existing active profile for this customer and profile name
        $activeProfile = Measurement::where('store_id', $store->id)
            ->where('customer_id', $customerId)
            ->where('profile_name', $profileName)
            ->whereNull('superseded_at')
            ->first();

        if ($activeProfile) {
            if (self::sameMetrics($activeProfile->metrics, $metrics) && $activeProfile->photo_urls === $sheets) {
                if ($jobOrder->measurement_id !== $activeProfile->id) {
                    $jobOrder->update(['measurement_id' => $activeProfile->id]);
                }
                return $activeProfile;
            }

            // Metrics or photos changed — supersede current version and create next version
            $activeProfile->update(['superseded_at' => now()]);
            $next = $store->measurements()->create([
                'customer_id' => $customerId,
                'source' => $source,
                'profile_name' => $profileName,
                'version' => $activeProfile->version + 1,
                'metrics' => $metrics,
                'photo_urls' => $sheets,
                'notes' => 'Recorded from Job Order ' . $jobOrder->order_number,
                'status' => 'finalized',
            ]);
            $jobOrder->update(['measurement_id' => $next->id]);
            return $next;
        }

        // 3. Create brand-new profile (Version 1)
        $newProfile = $store->measurements()->create([
            'customer_id' => $customerId,
            'source' => $source,
            'profile_name' => $profileName,
            'version' => 1,
            'metrics' => $metrics,
            'photo_urls' => $sheets,
            'notes' => 'Auto-recorded from Job Order ' . $jobOrder->order_number,
            'status' => 'finalized',
        ]);

        $jobOrder->update(['measurement_id' => $newProfile->id]);
        return $newProfile;
    }

    /** What the garment is called in a profile name: its category, else the service, else "Standard". */
    private static function garmentName(JobOrder $jobOrder): string
    {
        return ! empty($jobOrder->garment_category)
            ? ucfirst(str_replace('_', ' ', $jobOrder->garment_category))
            : (! empty($jobOrder->service?->name) ? ucfirst($jobOrder->service->name) : 'Standard');
    }

    /**
     * Saves measurements under one profile name for a customer: the next version of the current profile with that exact
     * name (history kept), a new profile when the name is new. Other profiles are never touched.
     *
     * @param  array<string,string>  $metrics
     * @param  list<string>  $photos
     */
    public static function saveProfile(Store $store, int $customerId, string $profileName, array $metrics, array $photos, string $source, string $note): Measurement
    {
        $active = Measurement::where('store_id', $store->id)
            ->where('customer_id', $customerId)
            ->where('profile_name', $profileName)
            ->whereNull('superseded_at')
            ->first();

        if ($active && $active->metrics === $metrics && $active->photo_urls === $photos) {
            return $active;
        }
        if ($active) {
            $active->update(['superseded_at' => now()]);
        }

        return $store->measurements()->create([
            'customer_id' => $customerId,
            'source' => $source,
            'profile_name' => $profileName,
            'version' => $active ? $active->version + 1 : 1,
            'metrics' => $metrics,
            'photo_urls' => $photos,
            'notes' => $note,
            'status' => 'finalized',
        ]);
    }

    /**
     * A team order's Custom Fit wearers: each one's typed measuring points (`fit_metrics` on their row) become that
     * person's own profile, "{Name} — {Garment} Profile", and the row keeps the profile's id. Wearers never share a
     * profile, and a wearer measured again later gets the next version of their own. Rows with no typed points are
     * left as they are (a chart size, or a profile already linked).
     */
    public static function syncRoster(Store $store, JobOrder $jobOrder, string $source = 'store_owner'): void
    {
        $data = (array) $jobOrder->custom_order_data;
        $roster = $data['team_roster'] ?? null;
        if (! is_array($roster) || $roster === [] || ! $jobOrder->customer_id) {
            return;
        }

        $garment = self::garmentName($jobOrder);
        $unit = ($data['roster_unit'] ?? 'in') === 'cm' ? 'cm' : 'in';
        foreach ($roster as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $points = [];
            foreach ((array) ($row['fit_metrics'] ?? []) as $label => $value) {
                if (trim((string) $value) !== '') {
                    $points[(string) $label] = trim((string) $value);
                }
            }
            unset($row['fit_metrics']);
            if ($points !== [] && empty($row['measurement_id'])) {
                $who = trim((string) ($row['name'] ?? $row['player_name'] ?? '')) ?: 'Person '.($i + 1);
                $profile = self::saveProfile(
                    $store, (int) $jobOrder->customer_id, "{$who} — {$garment} Profile",
                    $points + ['_area' => in_array($row['measure_area'] ?? null, ['top', 'bottom', 'both'], true) ? $row['measure_area'] : 'both', '_unit' => $unit], [], $source, 'Recorded from Job Order '.$jobOrder->order_number,
                );
                $row['measurement_id'] = $profile->id;
            }
            $roster[$i] = $row;
        }

        $jobOrder->update(['custom_order_data' => array_merge($data, ['team_roster' => $roster])]);
    }

    /**
     * Auto-sync any unlinked job orders for a customer into their measurement profiles.
     */
    public static function syncForCustomer(Store $store, int $customerId): void
    {
        $jobs = JobOrder::where('store_id', $store->id)
            ->where('customer_id', $customerId)
            ->get();

        foreach ($jobs as $job) {
            if (! $job->measurement_id || ! Measurement::where('id', $job->measurement_id)->exists()) {
                self::syncForJob($store, $job);
            }
        }
    }
}
