<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePricing extends Model
{
    protected $table = 'service_pricing';

    protected $fillable = [
        'service_id', 'label', 'amount', 'covers', 'sold_as',
    ];

    /** What an option can cover and be sold as. Null in the table = not configured (legacy: no coverage implied, one unit = one garment). */
    public const COVERS = ['top', 'bottom', 'both'];

    public const SOLD_AS = ['garment', 'set'];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * What is kept on an order that was made from this option, taken here on the server and never from the client:
     * the label, what it covered and what one ordered unit was. A later edit of the option never changes an old order.
     * Only what is configured is written; a legacy option adds nothing but its label.
     *
     * @return array{option:string,option_covers?:string,option_sold_as?:string}
     */
    public function snapshot(): array
    {
        return array_filter([
            'option' => $this->label,
            'option_covers' => $this->covers,
            'option_sold_as' => $this->sold_as,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
