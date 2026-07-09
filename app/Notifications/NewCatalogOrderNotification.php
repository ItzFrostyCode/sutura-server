<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use App\Models\CatalogOrder;

class NewCatalogOrderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public CatalogOrder $catalogOrder;

    public function __construct(CatalogOrder $catalogOrder)
    {
        $this->catalogOrder = $catalogOrder;
    }

    /**
     * Delivery channels — database only (in-app notification).
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Database payload.
     */
    public function toArray(object $notifiable): array
    {
        $itemName = $this->catalogOrder->catalogItem?->name ?? 'a catalog item';

        return [
            'type'             => 'new_catalog_order',
            'title'            => 'New Ready-to-Wear Order',
            'message'          => ($this->catalogOrder->customer?->name ?? 'A customer') . ' ordered ' . $itemName . '.',
            'action_url'       => '/dashboard/orders',
            'catalog_order_id' => $this->catalogOrder->id,
            'item_name'        => $itemName,
            'customer_name'    => $this->catalogOrder->customer?->name,
            'amount'           => $this->catalogOrder->total_amount,
        ];
    }
}
