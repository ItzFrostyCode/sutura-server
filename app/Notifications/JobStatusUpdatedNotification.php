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
    public string $status; // 'design', 'pattern_making', 'mass_cutting_printing', 'cutting', 'sewing', 'ready_for_fitting', 'final_adjustments', 'qc_ironing', 'completed', 'cancelled', 'rejected'

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
            'design'                 => 'Order In Progress: Design',
            'pattern_making'         => 'Order In Progress: Pattern Making',
            'mass_cutting_printing'  => 'Order In Progress: Mass Cutting & Printing',
            'cutting'                => 'Order In Progress: Cutting',
            'sewing'                 => 'Order In Progress: Sewing & Assembly',
            'ready_for_fitting'      => 'Order Ready for Fitting',
            'final_adjustments'      => 'Order In Progress: Final Adjustments',
            'qc_ironing'             => 'Order In Progress: Quality Check & Ironing',
            'completed'              => 'Order Completed',
            'cancelled'              => 'Order Cancelled',
            'rejected'               => 'Order Declined',
        ];
    }

    private function messages(): array
    {
        return [
            'design'                 => 'Your order (' . $this->jobOrder->order_number . ') is now in the design stage.',
            'pattern_making'         => 'Your order (' . $this->jobOrder->order_number . ') is now having its pattern drafted.',
            'mass_cutting_printing'  => 'Your order (' . $this->jobOrder->order_number . ') has entered mass cutting and printing.',
            'cutting'                => 'Your order (' . $this->jobOrder->order_number . ') has entered the cutting stage.',
            'sewing'                 => 'Your order (' . $this->jobOrder->order_number . ') is now being sewn and assembled.',
            'ready_for_fitting'      => 'Your order (' . $this->jobOrder->order_number . ') is ready for fitting. We\'ve scheduled a fitting appointment and will confirm the exact time with you shortly.',
            'final_adjustments'      => 'Your order (' . $this->jobOrder->order_number . ') is undergoing final adjustments after your fitting.',
            'qc_ironing'             => 'Your order (' . $this->jobOrder->order_number . ') is receiving its final quality check and ironing.',
            'completed'              => 'Your order (' . $this->jobOrder->order_number . ') is now complete. Thank you for trusting us with your custom tailoring!',
            'cancelled'              => 'Your order (' . $this->jobOrder->order_number . ') has been cancelled.',
            'rejected'               => 'Your order (' . $this->jobOrder->order_number . ') could not be accepted. Please reach out to the shop directly for details.',
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
