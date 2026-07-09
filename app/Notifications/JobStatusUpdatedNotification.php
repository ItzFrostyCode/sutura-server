<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\JobOrder;

/**
 * Fires on every production-stage transition EXCEPT ready_for_pickup, which
 * keeps its own dedicated OrderReadyNotification (balance-due copy, pickup
 * CTA) so customers don't get two overlapping emails for that one stage.
 */
class JobStatusUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public JobOrder $jobOrder;
    public string $status; // 'design', 'pattern_making', 'cutting', 'sewing', 'fitting', 'finishing', 'packed', 'handed_to_courier', 'completed', 'cancelled'

    public function __construct(JobOrder $jobOrder, string $status)
    {
        $this->jobOrder = $jobOrder;
        $this->status = $status;
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

    private function titles(): array
    {
        return [
            'design'            => 'Order In Progress: Design',
            'pattern_making'    => 'Order In Progress: Pattern Making',
            'cutting'           => 'Order In Progress: Cutting',
            'sewing'            => 'Order In Progress: Sewing',
            'fitting'           => 'Order Ready for Fitting',
            'finishing'         => 'Order In Progress: Finishing Touches',
            'packed'            => 'Order Packed',
            'handed_to_courier' => 'Order Shipped',
            'completed'         => 'Order Completed',
            'cancelled'         => 'Order Cancelled',
        ];
    }

    private function messages(): array
    {
        $courierNote = '';
        if ($this->status === 'handed_to_courier' && $this->jobOrder->courier_name) {
            $courierNote = ' via ' . $this->jobOrder->courier_name
                . ($this->jobOrder->courier_tracking_number ? ' (tracking #: ' . $this->jobOrder->courier_tracking_number . ')' : '');
        }

        return [
            'design'            => 'Your order (' . $this->jobOrder->order_number . ') is now in the design stage.',
            'pattern_making'    => 'Your order (' . $this->jobOrder->order_number . ') is now having its pattern drafted.',
            'cutting'           => 'Your order (' . $this->jobOrder->order_number . ') has entered the cutting stage.',
            'sewing'            => 'Your order (' . $this->jobOrder->order_number . ') is now being sewn.',
            'fitting'           => 'Your order (' . $this->jobOrder->order_number . ') is ready for fitting. The shop will reach out to schedule this with you.',
            'finishing'         => 'Your order (' . $this->jobOrder->order_number . ') is receiving its final finishing touches.',
            'packed'            => 'Your order (' . $this->jobOrder->order_number . ') has been packed and is ready for handover.',
            'handed_to_courier' => 'Your order (' . $this->jobOrder->order_number . ') has been handed over to the courier' . $courierNote . '.',
            'completed'         => 'Your order (' . $this->jobOrder->order_number . ') is now complete. Thank you for trusting us with your custom tailoring!',
            'cancelled'         => 'Your order (' . $this->jobOrder->order_number . ') has been cancelled.'
                . ($this->jobOrder->rejection_reason ? ' Reason: ' . $this->jobOrder->rejection_reason : ''),
        ];
    }

    /**
     * Mail representation — reuses the same title/message copy as the in-app
     * notification so both channels stay in sync automatically.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->titles()[$this->status] ?? 'Order Update';
        $message = $this->messages()[$this->status] ?? 'Your order status has been updated.';

        $shop = $this->jobOrder->shop;
        $shopUrl = $shop?->slug ? url(env('FRONTEND_URL', 'http://localhost:3000') . '/shop/' . $shop->slug) : null;

        $mail = (new MailMessage)
            ->subject($title . ' — ' . ($shop?->name ?? 'SUTURA'))
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line($message);

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
        $titles = $this->titles();
        $messages = $this->messages();

        return [
            'type'          => 'job_' . $this->status,
            'title'         => $titles[$this->status] ?? 'Order Update',
            'message'       => $messages[$this->status] ?? 'Your order status has been updated.',
            'action_url'    => '/dashboard/jobs/' . $this->jobOrder->id,
            'job_order_id'  => $this->jobOrder->id,
            'order_number'  => $this->jobOrder->order_number,
        ];
    }
}
