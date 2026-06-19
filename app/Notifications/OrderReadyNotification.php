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
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
                    ->subject('Your Bespoke Garment is Ready for Pickup!')
                    ->greeting('Hello ' . $notifiable->name . ',')
                    ->line('Great news! Your order (' . $this->jobOrder->order_number . ') from ' . $this->jobOrder->shop->name . ' is now ready.')
                    ->line('Please visit the shop to fit your garment. If everything is perfect, you can pay your remaining balance of ₱' . number_format($this->jobOrder->balance, 2) . ' and take it home.')
                    ->line('If any final adjustments are needed, our tailors will handle them on-site.')
                    ->action('View Your Order', url(env('FRONTEND_URL', 'http://localhost:3000') . '/dashboard'))
                    ->line('Thank you for trusting us with your custom tailoring!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'order_ready',
            'job_order_id' => $this->jobOrder->id,
            'message' => 'Your order ' . $this->jobOrder->order_number . ' is ready for pickup.'
        ];
    }
}
