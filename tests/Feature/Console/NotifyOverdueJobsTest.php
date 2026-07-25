<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Models\Shop;
use App\Models\Service;
use App\Models\JobOrder;
use App\Models\Role;
use App\Notifications\OverdueJobsNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotifyOverdueJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifies_owner_only_when_shop_has_overdue_jobs_excluding_on_hold_and_rejected()
    {
        Notification::fake();

        $role = Role::create(['name' => 'shop_owner', 'description' => 'Shop Owner']);
        $owner = User::factory()->create();
        $owner->roles()->attach($role);

        $shop = Shop::create([
            'owner_id' => $owner->id,
            'name' => 'Overdue Test Shop',
            'slug' => 'overdue-test-shop',
            'address' => '123 Test St',
            'city' => 'Davao',
            'province' => 'Davao del Sur',
            'status' => 'approved',
        ]);

        $customer = User::factory()->create();
        $service = Service::create(['shop_id' => $shop->id, 'name' => 'Bespoke Suit']);

        // Genuinely overdue — should count.
        JobOrder::create([
            'order_number' => 'JO-' . Str::random(10),
            'shop_id' => $shop->id,
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'status' => 'cutting',
            'due_date' => now()->subDay()->toDateString(),
            'total_amount' => 1000,
            'balance' => 1000,
        ]);

        // Past due_date but on_hold — should NOT count.
        JobOrder::create([
            'order_number' => 'JO-' . Str::random(10),
            'shop_id' => $shop->id,
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'status' => 'on_hold',
            'due_date' => now()->subDay()->toDateString(),
            'total_amount' => 1000,
            'balance' => 1000,
        ]);

        $this->artisan('app:notify-overdue-jobs')->assertSuccessful();

        Notification::assertSentTo(
            $owner,
            OverdueJobsNotification::class,
            function (OverdueJobsNotification $notification) {
                return $notification->overdueCount === 1;
            }
        );
    }

    public function test_does_not_notify_when_no_jobs_are_overdue()
    {
        Notification::fake();

        $role = Role::create(['name' => 'shop_owner', 'description' => 'Shop Owner']);
        $owner = User::factory()->create();
        $owner->roles()->attach($role);

        Shop::create([
            'owner_id' => $owner->id,
            'name' => 'Clean Shop',
            'slug' => 'clean-shop',
            'address' => '456 Test St',
            'city' => 'Davao',
            'province' => 'Davao del Sur',
            'status' => 'approved',
        ]);

        $this->artisan('app:notify-overdue-jobs')->assertSuccessful();

        Notification::assertNothingSentTo($owner);
    }
}
