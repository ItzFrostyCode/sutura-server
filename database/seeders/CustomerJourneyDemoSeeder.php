<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One customer with something on every customer screen, for the demo and the adviser's UI/UX review: the demo
 * customer Juan dela Cruz at the demo shop Thread & Needle gets orders at different stages (in production, ready,
 * completed) made with his measurements, upcoming / past / pending appointments, notifications spread over today,
 * yesterday and earlier (some read), saved and recently viewed items, and his own ratings.
 *
 * Safe to run again: rows are found by a fixed order number / id and updated, never duplicated. Only touches this
 * one customer and shop. Mock data — never run it against real customers (DatabaseSeeder's production guard applies).
 *
 *   php artisan db:seed --class=CustomerJourneyDemoSeeder
 */
class CustomerJourneyDemoSeeder extends Seeder
{
    private const CUSTOMER_EMAIL = 'juan.delacruz@gmail.com';

    private const STORE_SLUG = 'thread-needle';

    public function run(): void
    {
        if (app()->environment('production') && ! filter_var(env('ALLOW_DEMO_SEED', false), FILTER_VALIDATE_BOOL)) {
            $this->command?->warn('Production: CustomerJourneyDemoSeeder skipped (set ALLOW_DEMO_SEED=true to run it).');

            return;
        }

        $juan = User::where('email', self::CUSTOMER_EMAIL)->first();
        $store = Store::where('slug', self::STORE_SLUG)->first();
        if (! $juan || ! $store) {
            $this->command?->warn('CustomerJourneyDemoSeeder: run LocalTestSeeder first (demo customer or shop missing).');

            return;
        }

        $branchId = DB::table('store_branches')->where('store_id', $store->id)->orderByDesc('is_main')->value('id');
        $staffId = DB::table('job_orders')->where('store_id', $store->id)->whereNotNull('assigned_staff_id')->value('assigned_staff_id');
        $now = Carbon::now();

        $barongMeasurement = $this->barongMeasurement($juan->id, $store->id, $now);
        $suitV1 = DB::table('measurements')->where('customer_id', $juan->id)->where('profile_name', 'Bespoke Suit Tailoring')->where('version', 1)->value('id');
        $suitV2 = DB::table('measurements')->where('customer_id', $juan->id)->where('profile_name', 'Bespoke Suit Tailoring')->where('version', 2)->value('id');

        // His existing walk-in suit order (ORD-0010, sewing) was made with the current suit measurements.
        DB::table('job_orders')->where('customer_id', $juan->id)->where('order_number', 'ORD-0010')->update(['measurement_id' => $suitV2]);

        $base = [
            'intake_channel' => 'online', 'fulfillment_type' => 'pickup', 'store_id' => $store->id, 'store_branch_id' => $branchId,
            'customer_id' => $juan->id, 'assigned_staff_id' => $staffId, 'quantity' => 1, 'is_rush' => 0, 'rush_fee' => 0,
            'material_source' => 'store_supplied', 'measurement_requirement' => 'existing', 'fitting_requirement' => 'optional',
            'payment_policy' => 'deposit', 'payment_policy_percent' => 50, 'uses_confirmation_flow' => 0, 'adjustment_count' => 0,
        ];
        $ready = $this->order('ORD-J101', 'TNJ101RD', $base + [
            'service_id' => 3, 'catalog_item_id' => 16, 'measurement_id' => $barongMeasurement, 'status' => 'ready_for_pickup',
            'total_amount' => 4500, 'balance' => 2250, 'payment_status' => 'partial', 'ready_for_pickup_at' => $now->copy()->subHour(),
            'due_date' => $now->copy()->addDay()->toDateString(), 'notes' => 'Barong for the company anniversary.',
            'created_at' => $now->copy()->subDays(12), 'updated_at' => $now->copy()->subHour(),
        ]);
        $inProduction = $this->order('ORD-J102', 'TNJ102PR', $base + [
            'service_id' => 2, 'catalog_item_id' => 74, 'measurement_id' => $suitV2, 'status' => 'design',
            'total_amount' => 11800, 'balance' => 5900, 'payment_status' => 'partial',
            'due_date' => $now->copy()->addDays(18)->toDateString(), 'notes' => 'Navy three-piece for a wedding (principal sponsor).',
            'created_at' => $now->copy()->subDays(3), 'updated_at' => $now->copy()->subDay(),
        ]);
        $completed = $this->order('ORD-J103', 'TNJ103DN', $base + [
            'service_id' => 2, 'catalog_item_id' => 67, 'measurement_id' => $suitV1, 'status' => 'completed',
            'total_amount' => 11500, 'balance' => 0, 'payment_status' => 'paid',
            'due_date' => $now->copy()->subDays(25)->toDateString(), 'notes' => 'Charcoal two-piece, picked up.',
            'created_at' => $now->copy()->subDays(40), 'updated_at' => $now->copy()->subDays(24),
        ]);

        $fitting = $this->appointment('fitting', $juan->id, $store->id, $branchId, [
            'service_id' => 3, 'catalog_item_id' => 16, 'job_order_id' => $ready, 'status' => 'confirmed', 'duration_minutes' => 30,
            'scheduled_at' => $now->copy()->addDays(2)->setTime(10, 0), 'notes' => 'Final fitting before pickup.',
        ]);
        $this->appointment('measurement', $juan->id, $store->id, $branchId, [
            'service_id' => 2, 'catalog_item_id' => 74, 'job_order_id' => $inProduction, 'status' => 'completed', 'duration_minutes' => 45,
            'scheduled_at' => $now->copy()->subDays(4)->setTime(14, 0), 'notes' => 'Measured for the navy three-piece.',
        ]);
        $pending = $this->appointment('consultation', $juan->id, $store->id, $branchId, [
            'service_id' => 13, 'status' => 'pending', 'duration_minutes' => 60,
            'scheduled_at' => $now->copy()->addDays(6)->setTime(15, 30), 'notes' => 'Costume for the school play — wants to ask about fabrics.',
        ]);

        $shop = ['id' => $store->id, 'name' => $store->name, 'slug' => $store->slug, 'logo_path' => $store->logo_path];
        $owner = $store->owner ? ['id' => $store->owner->id, 'name' => $store->owner->name, 'profile_picture' => $store->owner->profile_picture] : null;
        $orderTitle = fn (string $n) => DB::table('catalog_items')->where('id', DB::table('job_orders')->where('id', $n)->value('catalog_item_id'))->value('name');
        $notes = [
            ['n1', 'OrderReadyNotification', 40, null, ['type' => 'order_ready', 'title' => 'Your order is ready', 'message' => 'Your '.$orderTitle($ready).' (ORD-J101) is ready for pickup at Poblacion Branch.', 'action_url' => '/account/orders/'.$ready, 'job_order_id' => $ready, 'order_number' => 'ORD-J101', 'store' => $shop, 'actor' => $owner]],
            ['n2', 'AppointmentStatusNotification', 180, null, ['type' => 'appointment_confirmed', 'title' => 'Appointment Confirmed', 'message' => 'Your fitting appointment with '.$store->name.' has been confirmed by the store.', 'action_url' => '/account/appointments/'.$fitting, 'appointment_id' => $fitting, 'scheduled_at' => $now->copy()->addDays(2)->setTime(10, 0)->toIso8601String(), 'store' => $shop, 'actor' => $owner]],
            ['n3', 'JobStatusUpdatedNotification', 60 * 26, null, ['type' => 'job_design', 'title' => 'Order In Progress: Design', 'message' => 'Your order (ORD-J102) is now in the design stage.', 'action_url' => '/account/orders/'.$inProduction, 'job_order_id' => $inProduction, 'order_number' => 'ORD-J102', 'store' => $shop, 'actor' => $owner]],
            ['n4', 'PaymentReceivedNotification', 60 * 30, 60 * 29, ['type' => 'payment_received', 'title' => 'Payment received', 'message' => 'The shop recorded your downpayment for ORD-J102.', 'action_url' => '/account/orders/'.$inProduction, 'job_order_id' => $inProduction, 'order_number' => 'ORD-J102', 'amount' => 5900, 'store' => $shop]],
            ['n5', 'AppointmentStatusNotification', 60 * 24 * 4, 60 * 24 * 4 - 30, ['type' => 'appointment_completed', 'title' => 'Appointment Completed', 'message' => 'Your measurement appointment is done. Thank you for coming in!', 'action_url' => '/account/appointments', 'scheduled_at' => $now->copy()->subDays(4)->setTime(14, 0)->toIso8601String(), 'store' => $shop]],
            ['n6', 'AppointmentBookedNotification', 60 * 24 * 2, null, ['type' => 'appointment_booked', 'title' => 'Appointment request sent', 'message' => 'Your consultation request was sent. The shop will confirm the time.', 'action_url' => '/account/appointments/'.$pending, 'appointment_id' => $pending, 'scheduled_at' => $now->copy()->addDays(6)->setTime(15, 30)->toIso8601String(), 'store' => $shop]],
            ['n7', 'JobStatusUpdatedNotification', 60 * 24 * 12, 60 * 24 * 11, ['type' => 'job_confirmed', 'title' => 'Order Confirmed', 'message' => 'Your order (ORD-J101) has been confirmed and is in the queue.', 'action_url' => '/account/orders/'.$ready, 'job_order_id' => $ready, 'order_number' => 'ORD-J101', 'store' => $shop, 'actor' => $owner]],
            ['n8', 'JobStatusUpdatedNotification', 60 * 24 * 24, 60 * 24 * 23, ['type' => 'job_completed', 'title' => 'Order Completed', 'message' => 'Your order (ORD-J103) is complete. Thank you for choosing '.$store->name.'!', 'action_url' => '/account/orders/'.$completed, 'job_order_id' => $completed, 'order_number' => 'ORD-J103', 'store' => $shop]],
        ];
        foreach ($notes as [$key, $class, $minutesAgo, $readMinutesAgo, $data]) {
            DB::table('notifications')->updateOrInsert(
                ['id' => '7a1d0c5e-0000-4000-8000-0000000000'.str_pad(substr($key, 1), 2, '0', STR_PAD_LEFT)],
                [
                    'type' => 'App\\Notifications\\'.$class, 'notifiable_type' => User::class, 'notifiable_id' => $juan->id,
                    'data' => json_encode($data), 'read_at' => $readMinutesAgo !== null ? $now->copy()->subMinutes($readMinutesAgo) : null,
                    'created_at' => $now->copy()->subMinutes($minutesAgo), 'updated_at' => $now->copy()->subMinutes($minutesAgo),
                ],
            );
        }

        foreach ([16, 5, 74] as $itemId) {
            DB::table('catalog_item_saves')->updateOrInsert(['catalog_item_id' => $itemId, 'user_id' => $juan->id], ['created_at' => $now, 'updated_at' => $now]);
        }
        foreach ([3, 4] as $serviceId) {
            DB::table('service_saves')->updateOrInsert(['service_id' => $serviceId, 'user_id' => $juan->id], ['created_at' => $now, 'updated_at' => $now]);
        }
        $viewed = [['App\\Models\\CatalogItem', 1, 20], ['App\\Models\\CatalogItem', 37, 90], ['App\\Models\\CatalogItem', 91, 300], ['App\\Models\\Service', 2, 45], ['App\\Models\\Service', 8, 600], ['App\\Models\\Store', $store->id, 10]];
        foreach ($viewed as [$type, $id, $minutesAgo]) {
            DB::table('recently_viewed')->updateOrInsert(
                ['user_id' => $juan->id, 'viewable_type' => $type, 'viewable_id' => $id],
                ['viewed_at' => $now->copy()->subMinutes($minutesAgo), 'created_at' => $now, 'updated_at' => $now],
            );
        }

        // His own ratings, for the design and service of the order he already has.
        DB::table('catalog_item_reviews')->updateOrInsert(['catalog_item_id' => 67, 'user_id' => $juan->id], ['rating' => 5, 'comment' => 'Perfect fit through the shoulders. Will order again.', 'created_at' => $now->copy()->subDays(22), 'updated_at' => $now->copy()->subDays(22)]);
        DB::table('service_reviews')->updateOrInsert(['service_id' => 2, 'user_id' => $juan->id], ['rating' => 5, 'created_at' => $now->copy()->subDays(22), 'updated_at' => $now->copy()->subDays(22)]);

        $this->command?->info('CustomerJourneyDemoSeeder: '.self::CUSTOMER_EMAIL.' now has 4 orders, 5 appointments, 8 notifications, saved items, recently viewed and ratings.');
    }

    /** A barong measurement profile (made by the shop) for his barong order. */
    private function barongMeasurement(int $customerId, int $storeId, Carbon $now): int
    {
        DB::table('measurements')->updateOrInsert(
            ['customer_id' => $customerId, 'store_id' => $storeId, 'profile_name' => 'Barong Tagalog', 'version' => 1],
            [
                'source' => 'store_owner', 'status' => 'finalized', 'superseded_at' => null,
                'metrics' => json_encode(['Neck' => '40.5', 'Chest' => '102', 'Waist' => '87', 'Shoulder' => '46', 'Sleeve' => '64', 'Barong Length' => '76']),
                'notes' => 'Prefers a slightly loose fit at the waist.', 'created_at' => $now->copy()->subDays(12), 'updated_at' => $now->copy()->subDays(12),
            ],
        );

        return (int) DB::table('measurements')->where('customer_id', $customerId)->where('store_id', $storeId)->where('profile_name', 'Barong Tagalog')->where('version', 1)->value('id');
    }

    /** @param  array<string, mixed>  $fields */
    private function order(string $number, string $tracking, array $fields): int
    {
        DB::table('job_orders')->updateOrInsert(['order_number' => $number], $fields + ['tracking_code' => $tracking]);

        return (int) DB::table('job_orders')->where('order_number', $number)->value('id');
    }

    /** Found again by customer + type + linked order / service, so a re-run updates it. @param  array<string, mixed>  $fields */
    private function appointment(string $type, int $customerId, int $storeId, ?int $branchId, array $fields): int
    {
        $key = ['customer_id' => $customerId, 'store_id' => $storeId, 'appointment_type' => $type, 'service_id' => $fields['service_id']];
        DB::table('appointments')->updateOrInsert($key, $fields + [
            'store_branch_id' => $branchId, 'intake_channel' => 'online', 'payment_status' => 'pending', 'rebooking_blocked' => 0,
            'created_at' => now()->subDays(5), 'updated_at' => now(),
        ]);

        return (int) DB::table('appointments')->where($key)->value('id');
    }
}
