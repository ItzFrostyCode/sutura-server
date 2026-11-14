<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Support\OfferingHealth;
use Illuminate\Console\Command;

/** Lists the services and designs whose settings would break or confuse ordering (App\Support\OfferingHealth). Read-only. */
class CheckOfferings extends Command
{
    protected $signature = 'sutura:check-offerings {store? : shop slug or id (all shops when left out)}';

    protected $description = 'Cross-check services and designs for settings that break or confuse ordering (read-only)';

    public function handle(): int
    {
        $arg = $this->argument('store');
        $stores = $arg === null ? Store::all() : Store::where('slug', $arg)->orWhere('id', $arg)->get();
        if ($stores->isEmpty()) {
            $this->error("No shop \"{$arg}\".");

            return self::FAILURE;
        }

        $errors = 0;
        foreach ($stores as $store) {
            $issues = OfferingHealth::forStore($store);
            $this->line("<options=bold>{$store->name}</>: ".($issues ? count($issues).' to look at' : 'all good'));
            foreach ($issues as $i) {
                $errors += $i['level'] === 'error' ? 1 : 0;
                $this->line(($i['level'] === 'error' ? '  <fg=red>error</>   ' : '  <fg=yellow>warning</> ')."{$i['item']}: {$i['message']}");
            }
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
