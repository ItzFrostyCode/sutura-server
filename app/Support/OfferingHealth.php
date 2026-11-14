<?php

namespace App\Support;

use App\Models\CatalogItem;
use App\Models\Service;
use App\Models\Store;

/**
 * Cross-checks a shop's services and designs for settings that save fine but break or confuse ordering later.
 * Used by the Thread & Needle seeder (it refuses to finish with an error) and by `php artisan sutura:check-offerings`.
 *
 * error   = a customer can hit a dead end or a wrong price.   warning = works, but likely not what the shop meant.
 *
 * @phpstan-type Issue array{level:'error'|'warning', item:string, message:string}
 */
final class OfferingHealth
{
    /** Same words the online order uses to answer a question from what it already knows (orderQuestions.ts kindOf). */
    private const AUTO_ANSWERED = [
        'size breakdown' => '/size\s*(breakdown|count|list|run)|how many (of each|per) size|sizes? needed/i',
        'list of names' => '/roster|player|names?\b|list of (names|people|members)|jersey (name|number)/i',
        'team or group name' => '/team|organi[sz]ation|school|company|group name|club/i',
        'fabric description' => '/fabric|material|cloth|tela/i',
    ];

    /** @return list<array{level:string,item:string,message:string}> */
    public static function forStore(Store $store): array
    {
        $out = [];
        $services = Service::where('store_id', $store->id)->where('status', '!=', Service::STATUS_ARCHIVED)->with('pricing')->get();
        foreach ($services as $s) {
            foreach (self::service($s) as [$level, $message]) {
                $out[] = ['level' => $level, 'item' => "Service \"{$s->name}\"", 'message' => $message];
            }
        }
        $designs = CatalogItem::where('store_id', $store->id)->with(['images', 'service'])->get();
        foreach ($designs as $d) {
            foreach (self::design($d) as [$level, $message]) {
                $out[] = ['level' => $level, 'item' => "Design \"{$d->name}\"", 'message' => $message];
            }
        }

        return $out;
    }

    /** @return list<array{0:string,1:string}> */
    public static function service(Service $s): array
    {
        $out = [];
        if ($s->status === Service::STATUS_PUBLISHED) {
            foreach ($s->publishProblems() as $m) {
                $out[] = ['error', "Published but not publishable: {$m}"];
            }
        }

        $derived = Service::deriveOrderTypes($s->service_category, (array) $s->capabilities, (array) $s->garment_types);
        if ($derived !== null && array_values((array) $s->service_types) !== $derived) {
            $out[] = ['error', 'Its order types are out of date ('.implode(', ', (array) $s->service_types).'; should be '.implode(', ', $derived).'). Save the service again.'];
        }

        $repair = $s->hasType(Service::TYPE_ALTERATION_REPAIR) && count((array) $s->service_types) === 1;
        foreach ($s->pricing as $p) {
            if ($p->sold_as === 'set' && $p->covers !== 'both') {
                $out[] = ['error', "Option \"{$p->label}\" is sold as a set but does not cover Top & Bottom."];
            }
            if (in_array($s->price_display, [Service::PRICE_FIXED, Service::PRICE_STARTING], true) && (float) $p->amount <= 0) {
                $out[] = ['error', "Option \"{$p->label}\" shows ₱0.00 because the service has a fixed or starting price. Give it a price, or make the service \"Quotation required\"."];
            }
            if (! $repair && $p->covers === null) {
                $out[] = ['warning', "Option \"{$p->label}\" does not say if it is a top, a bottom or a set, so the customer must choose (and may choose wrong)."];
            }
        }
        if ($s->pricing->isEmpty() && $repair) {
            $out[] = ['error', 'A repair request needs at least one priced job to pick from.'];
        }

        if (in_array('bulk_orders', (array) $s->capabilities, true) && (int) $s->min_order_qty < 2) {
            $out[] = ['warning', 'Group orders are on but no group minimum is set: the minimum becomes 2 people.'];
        }

        $cols = (array) $s->size_chart_columns;
        $sizes = [];
        foreach ((array) $s->size_chart_rows as $i => $row) {
            $size = trim((string) ($row['size'] ?? ''));
            if ($size === '') {
                $out[] = ['error', 'Size chart row '.($i + 1).' has no size name.'];
            } elseif (in_array(strtolower($size), $sizes, true)) {
                $out[] = ['error', "Size \"{$size}\" is in the size chart twice."];
            }
            $sizes[] = strtolower($size);
            if ($cols && count((array) ($row['values'] ?? [])) !== count($cols)) {
                $out[] = ['warning', "Size \"{$size}\" has ".count((array) ($row['values'] ?? [])).' values for '.count($cols).' columns.'];
            }
        }

        $labels = [];
        foreach ((array) $s->custom_fields as $f) {
            $label = mb_strtolower(trim((string) ($f['label'] ?? '')));
            if ($label !== '' && in_array($label, $labels, true)) {
                // Answers are kept by the question's label, so two equal labels overwrite each other.
                $out[] = ['error', "Two questions are called \"{$f['label']}\": the second answer replaces the first."];
            }
            $labels[] = $label;
            foreach (self::AUTO_ANSWERED as $what => $regex) {
                if (preg_match($regex, (string) ($f['label'] ?? '')) && in_array($f['type'] ?? '', ['number', 'select', 'radio', 'checkbox'], true)) {
                    $out[] = ['warning', "Question \"{$f['label']}\" is filled in automatically with the {$what}, but it expects a {$f['type']}. Reword it if that is not what it asks."];
                }
            }
        }

        if (! trim((string) $s->image_url)) {
            $out[] = ['warning', 'No photo: the service card shows a blank image.'];
        }
        foreach ((array) $s->garment_types as $g) {
            if (CanonicalTaxonomy::canonicalGarment($g) === null) {
                $out[] = ['warning', "Garment \"{$g}\" is not in SUTURA's list yet and waits for the admin's check."];
            }
        }

        return $out;
    }

    /** @return list<array{0:string,1:string}> */
    public static function design(CatalogItem $d): array
    {
        $out = [];
        if ($d->is_active && $d->images->isEmpty()) {
            $out[] = ['error', 'Shown to customers but has no photo.'];
        }
        $s = $d->service;
        if ($d->is_active && $d->service_id && (! $s || ! $s->is_active)) {
            $out[] = ['error', 'Shown to customers but its service is not published, so it cannot be ordered.'];
        }
        if ($d->pricing_mode === DesignPricing::SET && (float) $d->price <= 0) {
            $out[] = ['error', 'Priced as "its own price" but the price is ₱0.00.'];
        }
        if ($d->pricing_mode === DesignPricing::EXTRA && (float) $d->extra_charge <= 0) {
            $out[] = ['error', 'Priced as "service price plus an extra" but the extra is ₱0.00.'];
        }
        $chart = collect((array) $s?->size_chart_rows)->pluck('size')->map(fn ($v) => strtolower(trim((string) $v)))->filter()->all();
        if ($chart) {
            $missing = collect((array) $d->sizes)->filter(fn ($v) => ! in_array(strtolower(trim((string) $v)), $chart, true))->values()->all();
            if ($missing) {
                $out[] = ['warning', 'Sizes '.implode(', ', $missing).' are not in its service\'s size chart.'];
            }
        }
        if (in_array($d->garment_type, [null, ''], true)) {
            $out[] = ['warning', 'No garment type: it will not show under any garment in search.'];
        }

        return $out;
    }
}
