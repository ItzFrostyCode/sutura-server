<?php

namespace Database\Seeders;

use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Requests\Store\StoreServiceRequest;
use App\Models\CatalogItem;
use App\Models\Service;
use App\Models\Store;
use App\Models\User;
use App\Support\CanonicalTaxonomy;
use App\Support\DesignPricing;
use App\Support\OfferingHealth;
use Illuminate\Database\Seeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Adds the services and designs in data/thread_needle_catalog.php to Thread & Needle Tailoring.
 *
 *   php artisan db:seed --class=ThreadNeedleCatalogSeeder
 *
 * Safe to run again: it finds each service and design by name and updates it; it never deletes anything and never
 * touches the shop's other services, designs or orders. (Do NOT run plain `db:seed`: that one wipes the database.)
 *
 * Nothing is written straight to the tables. Every service and design goes through the same controller and validation
 * the shop owner's dashboard uses, as the shop owner, so the seed can never hold data the app itself would refuse.
 * Order of work: 1) check all entries first, 2) save in one transaction, 3) cross-check the whole shop
 * (App\Support\OfferingHealth); an error on any seeded item rolls everything back.
 */
class ThreadNeedleCatalogSeeder extends Seeder
{
    private const SHOP_SLUG = 'thread-needle';

    private const IMAGE_DIR = '/catalog/seed/';

    public function run(): void
    {
        $store = Store::where('slug', self::SHOP_SLUG)->first()
            ?? throw new RuntimeException('Shop "'.self::SHOP_SLUG.'" not found. Seed the base data first.');
        $owner = $store->owner ?? throw new RuntimeException('Thread & Needle has no owner account.');
        if (! $owner->hasRole('store_owner')) {
            throw new RuntimeException("{$owner->email} is not a store owner, so the dashboard would refuse these saves too.");
        }
        $data = require __DIR__.'/data/thread_needle_catalog.php';

        $this->preflight($data, $store, $owner);
        Auth::setUser($owner);

        $seeded = [];
        DB::transaction(function () use ($data, $store, $owner, &$seeded) {
            $ids = [];
            foreach ($data['services'] as $entry) {
                $service = $this->saveService($entry, $store, $owner);
                $ids[$entry['name']] = $service->id;
                $seeded[] = "Service \"{$entry['name']}\"";
            }
            foreach ($data['tidy'] ?? [] as $entry) {
                if ($label = $this->tidyService($entry, $store, $owner)) {
                    $seeded[] = $label;
                }
            }
            foreach ($data['designs'] as $entry) {
                $this->saveDesign($entry, $store, $owner, $ids[$entry['service']]);
                $seeded[] = "Design \"{$entry['name']}\"";
            }
            foreach ($data['design_fixes'] ?? [] as $entry) {
                if ($label = $this->fixDesign($entry, $store, $owner)) {
                    $seeded[] = $label;
                }
            }

            $issues = OfferingHealth::forStore($store);
            $mine = array_filter($issues, fn ($i) => $i['level'] === 'error' && in_array($i['item'], $seeded, true));
            if ($mine) {
                throw new RuntimeException("The seeded items failed the cross-check, nothing was saved:\n".$this->lines($mine));
            }
            $this->report($issues, $seeded);
        });

        $this->command?->info(count($data['services']).' services and '.count($data['designs']).' designs are in Thread & Needle Tailoring.');
    }

    /** Checks every entry before anything is written, and lists all problems at once (not one per run). */
    private function preflight(array $data, Store $store, User $owner): void
    {
        $problems = [];
        $names = array_column($data['services'], 'name');
        foreach (array_count_values($names) as $name => $n) {
            if ($n > 1) {
                $problems[] = "Service \"{$name}\" is listed {$n} times.";
            }
        }

        $limit = $store->subscription?->plan?->max_services;
        $new = count(array_filter($names, fn ($n) => ! Service::where('store_id', $store->id)->where('name', $n)->exists()));
        if ($limit !== null && (int) $limit >= 0 && $store->services()->count() + $new > (int) $limit) {
            $problems[] = "The shop's plan allows {$limit} services; this would make ".($store->services()->count() + $new).'.';
        }

        foreach ($data['services'] as $e) {
            $label = "Service \"{$e['name']}\"";
            foreach ($e['garment_types'] ?? [] as $g) {
                if (CanonicalTaxonomy::canonicalGarment($g) === null) {
                    $problems[] = "{$label}: garment \"{$g}\" is not in SUTURA's list (it would wait for an admin check).";
                }
            }
            try {
                $this->serviceRequest($this->servicePayload($e), $owner);
            } catch (ValidationException $ex) {
                $problems[] = "{$label}: ".implode(' ', array_merge(...array_values($ex->errors())));
            }
        }

        foreach ($data['designs'] as $e) {
            $label = "Design \"{$e['name']}\"";
            if (! in_array($e['service'], $names, true)) {
                $problems[] = "{$label}: its service \"{$e['service']}\" is not in this seed.";
            }
            if (! in_array($e['pricing_mode'], DesignPricing::MODES, true)) {
                $problems[] = "{$label}: pricing mode \"{$e['pricing_mode']}\" is unknown.";
            }
            if (CanonicalTaxonomy::canonicalGarment($e['garment_type'] ?? '') === null) {
                $problems[] = "{$label}: garment \"".($e['garment_type'] ?? '')."\" is not in SUTURA's list.";
            }
        }

        foreach ($data['tidy'] ?? [] as $e) {
            $current = $this->findService($store, $e);
            if (! $current || ! empty($e['archive'])) {
                continue;
            }
            try {
                $this->serviceRequest($this->tidyPayload($e, $current), $owner);
            } catch (ValidationException $ex) {
                $problems[] = "Service \"{$e['was']}\": ".implode(' ', array_merge(...array_values($ex->errors())));
            }
        }
        foreach ($data['design_fixes'] ?? [] as $e) {
            // Only a design this shop actually has is moved; a shop without it has nothing to check.
            if (! CatalogItem::where('store_id', $store->id)->where('name', $e['name'])->exists()) {
                continue;
            }
            if (isset($e['service']) && ! in_array($e['service'], $names, true) && ! Service::where('store_id', $store->id)->where('name', $e['service'])->exists()) {
                $problems[] = "Design \"{$e['name']}\": its new service \"{$e['service']}\" does not exist.";
            }
        }

        $images = array_merge(array_column($data['services'], 'image'), array_column($data['designs'], 'image'), array_filter(array_column($data['tidy'] ?? [], 'image')));
        $dir = base_path('../sutura-client/public'.self::IMAGE_DIR);
        if (is_dir($dir)) {
            foreach ($images as $img) {
                if (! is_file($dir.basename(self::image($img)))) {
                    $problems[] = 'Image '.basename(self::image($img)).' is missing from sutura-client/public'.self::IMAGE_DIR;
                }
            }
        }

        if ($problems) {
            throw new RuntimeException("Nothing was saved. Fix these first:\n- ".implode("\n- ", $problems));
        }
    }

    /** The public path of a seed image: "name" means name.png; "name.webp" (any extension) is used as given. */
    private static function image(string $name): string
    {
        return self::IMAGE_DIR.(pathinfo($name, PATHINFO_EXTENSION) === '' ? "{$name}.png" : $name);
    }

    private function servicePayload(array $e): array
    {
        $payload = $e;
        unset($payload['image']);
        $payload['image_url'] = self::image($e['image']);
        $payload['status'] = Service::STATUS_PUBLISHED;

        return $payload;
    }

    /** A validated owner request, exactly as the dashboard would send it (throws ValidationException when it would be refused). */
    private function serviceRequest(array $payload, User $owner): StoreServiceRequest
    {
        $req = StoreServiceRequest::create('/seed/services', 'POST', $payload);
        $req->setContainer(app())->setRedirector(app('redirect'));
        $req->setUserResolver(fn () => $owner);
        $req->validateResolved();

        return $req;
    }

    private function saveService(array $e, Store $store, User $owner): Service
    {
        $existing = Service::where('store_id', $store->id)->where('name', $e['name'])->first();
        $req = $this->serviceRequest($this->servicePayload($e), $owner);
        $controller = app(ServiceController::class);
        $res = $this->attempt("Service \"{$e['name']}\"", fn () => $existing ? $controller->update($req, $store, $existing) : $controller->store($req, $store));

        return Service::findOrFail($res['data']['id']);
    }

    private function saveDesign(array $e, Store $store, User $owner, int $serviceId): void
    {
        $service = Service::findOrFail($serviceId);
        $payload = [
            'name' => $e['name'], 'service_id' => $serviceId, 'garment_type' => $e['garment_type'],
            'pricing_mode' => $e['pricing_mode'], 'price' => $e['price'] ?? null, 'extra_charge' => $e['extra_charge'] ?? null,
            'material' => $e['material'] ?? null, 'color' => $e['color'] ?? null, 'sizes' => $e['sizes'] ?? null,
            'add_ons' => $e['add_ons'] ?? null, 'description' => $e['description'] ?? null,
            'estimated_days' => $service->estimated_days, 'estimated_days_max' => $service->estimated_days_max,
            'is_active' => true,
            'images' => [['url' => self::image($e['image']), 'angle' => 'front', 'is_primary' => true]],
        ];
        $payload = array_filter($payload, fn ($v) => $v !== null);

        $req = Request::create('/seed/designs', 'POST', $payload);
        $req->setUserResolver(fn () => $owner);
        $existing = CatalogItem::where('store_id', $store->id)->where('name', $e['name'])->first();
        $controller = app(CatalogController::class);
        $this->attempt("Design \"{$e['name']}\"", fn () => $existing ? $controller->update($req, $store, $existing) : $controller->store($req, $store));
    }

    /** An older service by the name it has now, or the name this seed gives it (so a second run finds it). */
    private function findService(Store $store, array $e): ?Service
    {
        return Service::where('store_id', $store->id)->whereIn('name', array_filter([$e['was'], $e['name'] ?? null]))->first();
    }

    private function tidyPayload(array $e, Service $current): array
    {
        $payload = array_diff_key($e, array_flip(['was', 'image', 'archive']));
        // The rules check the capabilities against the service group, so the group always travels with them.
        $payload['service_category'] = $current->service_category;
        $payload['name'] ??= $current->name;
        if (! empty($e['image'])) {
            $payload['image_url'] = self::image($e['image']);
        }

        return $payload;
    }

    /** Updates or archives one older service. Never archives one that still has open work or designs on show. */
    private function tidyService(array $e, Store $store, User $owner): ?string
    {
        $service = $this->findService($store, $e);
        if (! $service) {
            return null; // already archived, or this shop never had it
        }
        $controller = app(ServiceController::class);

        if (! empty($e['archive'])) {
            $open = $service->jobOrders()->whereNotIn('status', ['completed', 'cancelled', 'rejected'])->count()
                + \App\Models\Appointment::where('service_id', $service->id)->whereIn('status', ['pending', 'confirmed', 'in_progress'])->count();
            $designs = CatalogItem::where('service_id', $service->id)->where('is_active', true)->count();
            if ($open + $designs > 0) {
                $this->command?->warn("Kept \"{$service->name}\": it still has {$open} open orders or appointments and {$designs} designs on show. Archive it from the dashboard when they are done.");

                return null;
            }
            $req = Request::create('/seed/services', 'DELETE');
            $req->setUserResolver(fn () => $owner);
            $res = $controller->destroy($req, $store, $service);
            if ($res->getStatusCode() >= 300) {
                throw new RuntimeException("Service \"{$service->name}\" could not be archived: ".json_encode($res->getData(true)));
            }

            return null;
        }

        $req = $this->serviceRequest($this->tidyPayload($e, $service), $owner);
        $this->attempt("Service \"{$service->name}\"", fn () => $controller->update($req, $store, $service));

        return 'Service "'.($e['name'] ?? $service->name).'"';
    }

    /** Moves an older design to the service that makes it (with that service's sizes), or hides it when none fits. */
    private function fixDesign(array $e, Store $store, User $owner): ?string
    {
        $design = CatalogItem::where('store_id', $store->id)->where('name', $e['name'])->first();
        if (! $design) {
            return null;
        }
        $payload = ['name' => $design->name];
        if (! empty($e['hide'])) {
            $payload['is_active'] = false;
        } else {
            $payload['service_id'] = Service::where('store_id', $store->id)->where('name', $e['service'])->value('id');
            $payload['sizes'] = $e['sizes'];
        }
        $req = Request::create('/seed/designs', 'PUT', $payload);
        $req->setUserResolver(fn () => $owner);
        $this->attempt("Design \"{$design->name}\"", fn () => app(CatalogController::class)->update($req, $store, $design));

        return empty($e['hide']) ? "Design \"{$design->name}\"" : null;
    }

    /** Runs one save and turns any refusal into an error that names the item and every reason. */
    private function attempt(string $label, callable $save): array
    {
        try {
            $res = $save();
        } catch (ValidationException $ex) {
            throw new RuntimeException("{$label} was refused: ".implode(' ', array_merge(...array_values($ex->errors()))));
        }
        $body = $res instanceof JsonResponse ? $res->getData(true) : [];
        if (! $res instanceof JsonResponse || $res->getStatusCode() >= 300 || empty($body['data']['id'])) {
            $why = $body['message'] ?? 'no reason given';
            if (! empty($body['errors'])) {
                $why .= ' '.json_encode($body['errors']);
            }
            throw new RuntimeException("{$label} was refused ({$res->getStatusCode()}): {$why}");
        }
        if (! empty($body['notice'])) {
            throw new RuntimeException("{$label}: {$body['notice']}");
        }

        return $body;
    }

    private function report(array $issues, array $seeded): void
    {
        $others = array_values(array_filter($issues, fn ($i) => ! in_array($i['item'], $seeded, true)));
        $mine = array_values(array_filter($issues, fn ($i) => in_array($i['item'], $seeded, true)));
        if ($mine) {
            $this->command?->warn("Warnings on the seeded items:\n".$this->lines($mine));
        }
        if ($others) {
            $this->command?->warn("The shop's other services and designs (not changed by this seeder):\n".$this->lines($others));
        }
    }

    private function lines(array $issues): string
    {
        return implode("\n", array_map(fn ($i) => "  [{$i['level']}] {$i['item']}: {$i['message']}", $issues));
    }
}
