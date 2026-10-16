<?php

namespace App\Notifications;

use App\Models\JobOrder;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Services\Sms\SmsTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A plain "something happened on your order" notice for the steps before production: the shop accepted
 * the request, the price is ready, the price changed, the payment terms changed. In-app and email;
 * a text only when $smsEvent is given (and the shop switched that text on).
 */
class OrderUpdateNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public JobOrder $jobOrder,
        public string $key,
        public string $title,
        public string $message,
        public ?User $actor = null,
        public ?string $smsEvent = null,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->email && ! str_starts_with($notifiable->email, 'walkin_')) {
            $channels[] = 'mail';
        }
        if ($this->smsEvent) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    public function toSms(object $notifiable): ?array
    {
        if ($this->smsEvent !== 'order_quotation_ready') {
            return null;
        }

        return SmsTemplates::quotation($this->jobOrder) + ['store_id' => $this->jobOrder->store_id, 'related_type' => 'job_order', 'related_id' => $this->jobOrder->id];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $store = $this->jobOrder->store;
        $url = url(env('FRONTEND_URL', 'http://localhost:3000').'/account/orders/'.$this->jobOrder->id);

        return (new MailMessage)
            ->subject($this->title.' — '.($store?->name ?? 'SUTURA'))
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->message)
            ->action('Open my order', $url);
    }

    public function toArray(object $notifiable): array
    {
        $store = $this->jobOrder->store;

        return [
            'type' => 'job_'.$this->key,
            'title' => $this->title,
            'message' => $this->message,
            'action_url' => '/account/orders/'.$this->jobOrder->id,
            'job_order_id' => $this->jobOrder->id,
            'order_number' => $this->jobOrder->order_number,
            'store' => $store ? ['id' => $store->id, 'name' => $store->name, 'slug' => $store->slug, 'logo_path' => $store->logo_path] : null,
            'actor' => $this->actor ? ['id' => $this->actor->id, 'name' => $this->actor->name, 'profile_picture' => $this->actor->profile_picture] : null,
        ];
    }
}
