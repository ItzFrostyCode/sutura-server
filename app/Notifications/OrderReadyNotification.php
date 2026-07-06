<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\JobOrder;

class OrderReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public JobOrder $jobOrder;

    public function __construct(JobOrder $jobOrder)
    {
        $this->jobOrder = $jobOrder;
    }

    /**
     * Delivery channels — database + mail, unless this is a synthetic walk-in
     * placeholder address (no real customer inbox to deliver to).
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->email && !str_starts_with($notifiable->email, 'walkin_')) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    /**
     * Mail representation.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $shop = $this->jobOrder->shop;
        $shopUrl = $shop?->slug ? url(env('FRONTEND_URL', 'http://localhost:3000') . '/shop/' . $shop->slug) : null;

        $mail = (new MailMessage)
            ->subject('Your Bespoke Garment is Ready for Pickup!')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('Great news! Your order (' . $this->jobOrder->order_number . ') from ' . ($shop?->name ?? 'the shop') . ' is now ready.')
            ->line('Please visit the shop to fit your garment. If everything is perfect, you can pay your remaining balance of ₱' . number_format($this->jobOrder->balance, 2) . ' and take it home.')
            ->line('If any final adjustments are needed, our tailors will handle them on-site.');

        if ($shopUrl) {
            $mail->action('Visit ' . $shop->name, $shopUrl);
        }

        return $mail->line('Thank you for trusting us with your custom tailoring!');
    }

    /**
     * Database payload — used by the NotificationBell on the frontend.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type'          => 'order_ready',
            'title'         => 'Order Ready for Pickup',
            'message'       => 'Order ' . $this->jobOrder->order_number . ' is ready for pickup.',
            'action_url'    => '/dashboard/jobs/' . $this->jobOrder->id,
            'job_order_id'  => $this->jobOrder->id,
            'order_number'  => $this->jobOrder->order_number,
            'customer_name' => $this->jobOrder->customer?->name,
        ];
    }
}
