<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\CatalogItemReview;

/**
 * CatalogInteractionController::replyToReview had no notification at all —
 * a customer who left a review would only find out the shop replied by
 * manually revisiting the product page. Same "owner action → customer
 * notified" pattern already used everywhere else (payments, order-ready,
 * appointment status) — the reviewer isn't a customer-portal build, this is
 * just completing the shop owner's own reply action's expected side effect.
 */
class CatalogItemReviewReplyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public CatalogItemReview $review;

    public function __construct(CatalogItemReview $review)
    {
        $this->review = $review;
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        $itemName = $this->review->catalogItem?->name ?? 'your review';

        return (new MailMessage)
            ->subject('The shop replied to your review — ' . $itemName)
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('The shop replied to your review of "' . $itemName . '":')
            ->line('"' . $this->review->reply . '"')
            ->action('View Reply', $frontendUrl . '/shop/' . $this->review->catalogItem?->shop?->slug . '/catalog/' . $this->review->catalog_item_id);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'catalog_item_review_reply',
            'title' => 'The shop replied to your review',
            'message' => 'The shop replied to your review of "' . ($this->review->catalogItem?->name ?? 'an item') . '".',
            'action_url' => '/shop/' . $this->review->catalogItem?->shop?->slug . '/catalog/' . $this->review->catalog_item_id,
            'review_id' => $this->review->id,
        ];
    }
}
