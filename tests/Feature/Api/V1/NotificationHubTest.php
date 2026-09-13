<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationHubTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'shop_owner', 'description' => 'Shop Owner']);
        $this->user = User::factory()->create();
    }

    private function createNotification(array $data = [], ?string $readAt = null): string
    {
        $id = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('notifications')->insert([
            'id'              => $id,
            'type'            => 'App\\Notifications\\NewJobOrderNotification',
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id'   => $this->user->id,
            'data'            => json_encode(array_merge([
                'type'          => 'new_job_order',
                'title'         => 'New Job Order Created',
                'message'       => 'Job order JO-1001 was created.',
                'action_url'    => '/dashboard/jobs/1',
                'customer_name' => 'Maria Clara',
            ], $data)),
            'read_at'         => $readAt,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        return $id;
    }

    public function test_can_list_notifications_quick_and_paginated(): void
    {
        $this->actingAs($this->user);
        $this->createNotification(['title' => 'First Order']);
        $this->createNotification(['title' => 'Second Order']);

        // Quick list (limit 30)
        $resQuick = $this->getJson('/api/v1/notifications');
        $resQuick->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('unread_count', 2);

        // Paginated list
        $resPaged = $this->getJson('/api/v1/notifications?page=1&per_page=1');
        $resPaged->assertStatus(200)
                 ->assertJsonStructure([
                     'success',
                     'data',
                     'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                     'unread_count',
                 ])
                 ->assertJsonPath('meta.total', 2)
                 ->assertJsonPath('meta.last_page', 2);
    }

    public function test_can_show_single_notification(): void
    {
        $this->actingAs($this->user);
        $id = $this->createNotification(['title' => 'Target Order']);

        $res = $this->getJson("/api/v1/notifications/{$id}");
        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $id);
    }

    public function test_can_bulk_read_and_bulk_delete(): void
    {
        $this->actingAs($this->user);
        $id1 = $this->createNotification(['title' => 'Order 1']);
        $id2 = $this->createNotification(['title' => 'Order 2']);
        $id3 = $this->createNotification(['title' => 'Order 3']);

        // Bulk read
        $resRead = $this->postJson('/api/v1/notifications/bulk-read', ['ids' => [$id1, $id2]]);
        $resRead->assertStatus(200)
                ->assertJsonPath('unread_count', 1);

        // Bulk delete
        $resDelete = $this->postJson('/api/v1/notifications/bulk-delete', ['ids' => [$id1, $id2]]);
        $resDelete->assertStatus(200);

        $this->assertDatabaseMissing('notifications', ['id' => $id1]);
        $this->assertDatabaseMissing('notifications', ['id' => $id2]);
        $this->assertDatabaseHas('notifications', ['id' => $id3]);
    }

    public function test_can_clear_all_notifications(): void
    {
        $this->actingAs($this->user);
        $this->createNotification();
        $this->createNotification();

        $res = $this->deleteJson('/api/v1/notifications/clear-all');
        $res->assertStatus(200)
            ->assertJsonPath('unread_count', 0);

        $this->assertEquals(0, $this->user->notifications()->count());
    }

    public function test_can_get_and_update_notification_preferences(): void
    {
        $this->actingAs($this->user);

        // Get defaults
        $resGet = $this->getJson('/api/v1/notifications/preferences');
        $resGet->assertStatus(200)
               ->assertJsonPath('success', true)
               ->assertJsonStructure(['data' => [['id', 'event', 'email', 'mobile']]]);

        // Update preferences
        $customPrefs = [
            ['id' => 'new_job_order', 'event' => 'A new job order is created', 'email' => false, 'mobile' => true],
        ];

        $resUpdate = $this->postJson('/api/v1/notifications/preferences', ['preferences' => $customPrefs]);
        $resUpdate->assertStatus(200)
                  ->assertJsonPath('success', true)
                  ->assertJsonPath('data.0.email', false);

        // Re-get confirms persistence
        $resReGet = $this->getJson('/api/v1/notifications/preferences');
        $resReGet->assertStatus(200)
                 ->assertJsonPath('data.0.email', false);
    }
}
