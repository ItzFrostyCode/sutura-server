<?php

namespace Tests\Feature\Console;

use App\Models\JobOrder;
use App\Models\Role;
use App\Models\Service;
use App\Models\Store;
use App\Models\User;
use App\Notifications\OverdueJobsNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotifyOverdueJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifies_owner_only_when_store_has_overdue_jobs_excluding_on_hold_and_rejected()
    {
        Notification::fake();

        $role = Role::create(['name' => 'store_owner', 'description' => 'Store Owner']);
        $owner = User::factory()->create();
        $owner->roles()->attach($role);

        $store = Store::create([
            'owner_id' => $owner->id,
            'name' => 'Overdue Test Store',
            'slug' => 'overdue-test-store',
            'address' => '123 Test St',
            'city' => 'Davao',
            'province' => 'Davao del Sur',
            'status' => 'approved',
        ]);

        $customer = User::factory()->create();
        $service = Service::create(['store_id' => $store->id, 'name' => 'Bespoke Suit']);

        // Genuinely overdue — should count.
        JobOrder::create([
            'order_number' => 'JO-'.Str::random(10),
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'status' => 'cutting',
            'due_date' => now()->subDay()->toDateString(),
            'total_amount' => 1000,
            'balance' => 1000,
        ]);

        // Past due_date but on_hold — should NOT count.
        JobOrder::create([
            'order_number' => 'JO-'.Str::random(10),
            'store_id' => $store->id,
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

        $role = Role::create(['name' => 'store_owner', 'description' => 'Store Owner']);
        $owner = User::factory()->create();
        $owner->roles()->attach($role);

        Store::create([
            'owner_id' => $owner->id,
            'name' => 'Clean Store',
            'slug' => 'clean-store',
            'address' => '456 Test St',
            'city' => 'Davao',
            'province' => 'Davao del Sur',
            'status' => 'approved',
        ]);

        $this->artisan('app:notify-overdue-jobs')->assertSuccessful();

        Notification::assertNothingSentTo($owner);
    }
}
