<?php

namespace App\Notifications;

use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fires to the registrant when Admin\StoreController::approve()/reject()
 * decides their shop registration — previously neither action notified
 * anyone at all, so an applicant had no way to find out short of manually
 * refreshing their dashboard. Mail + database (not database-only): this is
 * a rare, high-stakes, one-time event per registration, unlike the noisy
 * per-status appointment updates that were intentionally cut back to
 * database-only elsewhere.
 */
class StoreApplicationStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Store $store;

    public string $decision; // 'approved' | 'rejected'

    public function __construct(Store $store, string $decision)
    {
        $this->store = $store;
        $this->decision = $decision;
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        if ($this->decision === 'approved') {
            return (new MailMessage)
                ->subject('Your Shop Has Been Approved — '.$this->store->name)
                ->greeting('Hello '.$notifiable->name.',')
                ->line($this->store->name.' has been reviewed and approved.')
                ->line('You now have full access to your Shop Owner Dashboard and your storefront is visible to customers.')
                ->action('Go to Your Dashboard', $frontendUrl.'/dashboard');
        }

        return (new MailMessage)
            ->subject('Your Shop Registration Was Not Approved — '.$this->store->name)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your registration for '.$this->store->name.' was not approved.')
            ->line('Reason: '.($this->store->rejection_reason ?: 'No reason was provided.'))
            ->line('You may correct the issue and submit a new registration.');
    }

    public function toArray(object $notifiable): array
    {
        $approved = $this->decision === 'approved';

        return [
            'type' => 'store_'.$this->decision,
            'title' => $approved ? 'Shop Approved' : 'Shop Registration Rejected',
            'message' => $approved
                ? $this->store->name.' has been approved. You now have full access to your dashboard.'
                : $this->store->name.' was not approved. Reason: '.($this->store->rejection_reason ?: 'No reason was provided.'),
            'action_url' => $approved ? '/dashboard' : '/dashboard/settings',
            'store_id' => $this->store->id,
        ];
    }
}
