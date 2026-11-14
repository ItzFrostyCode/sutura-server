<?php

namespace App\Support;

use App\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * Brings stored services, designs and shop settings up to the current rules. Run by the data migrations and again by
 * DatabaseSeeder after it loads database/snapshot (the snapshot was saved before these rules, so loading it would
 * otherwise bring the old values back). Safe to run any number of times.
 *
 *  - questions / list columns in the one current shape (ServiceQuestions)
 *  - order types worked out again from what each service is (Service::deriveOrderTypes)
 *  - no "pay at pick-up" or deposit under 50% for made-to-order work (OrderRequirements::MIN_DEPOSIT_PERCENT)
 */
final class OfferingDataRepair
{
    /** Services whose work is on the customer's own garment: paid at pick-up, so the deposit floor does not apply. */
    private const GARMENT_WORK = ['alterations_repairs', 'garment_customization'];

    public static function run(): void
    {
        self::questionsAndTypes();
        self::depositFloor();
    }

    public static function questionsAndTypes(): void
    {
        DB::table('services')->select(['id', 'service_category', 'capabilities', 'garment_types', 'custom_fields', 'roster_fields'])
            ->orderBy('id')->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $q = ServiceQuestions::normalize($row->custom_fields);
                    $r = ServiceQuestions::normalize($row->roster_fields, ServiceQuestions::ROSTER_TYPES);
                    $update = [
                        'custom_fields' => $q === [] ? null : json_encode($q, JSON_UNESCAPED_UNICODE),
                        'roster_fields' => $r === [] ? null : json_encode($r, JSON_UNESCAPED_UNICODE),
                    ];
                    $types = Service::deriveOrderTypes(
                        $row->service_category,
                        (array) (json_decode((string) $row->capabilities, true) ?? []),
                        (array) (json_decode((string) $row->garment_types, true) ?? []),
                    );
                    if ($types !== null) {
                        $update['service_types'] = json_encode($types);
                    }
                    DB::table('services')->where('id', $row->id)->update($update);
                }
            });
    }

    public static function depositFloor(): void
    {
        $min = OrderRequirements::MIN_DEPOSIT_PERCENT;
        DB::table('stores')->where('default_payment_policy', 'none')->update(['default_payment_policy' => 'deposit', 'default_payment_percent' => $min]);
        DB::table('stores')->whereIn('default_payment_policy', ['deposit', 'custom'])->where('default_payment_percent', '<', $min)->update(['default_payment_percent' => $min]);

        foreach (['services', 'catalog_items'] as $table) {
            $q = fn () => DB::table($table)->when($table === 'services', fn ($q) => $q->where(
                fn ($w) => $w->whereNull('service_category')->orWhereNotIn('service_category', self::GARMENT_WORK)
            ));
            $q()->where('payment_policy', 'none')->update(['payment_policy' => 'deposit', 'payment_policy_percent' => $min]);
            $q()->whereIn('payment_policy', ['deposit', 'custom'])->where('payment_policy_percent', '<', $min)->update(['payment_policy_percent' => $min]);
        }
    }
}
