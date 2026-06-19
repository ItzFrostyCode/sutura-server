<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePricing extends Model
{
    protected $table = 'service_pricing';

    protected $fillable = [
        'service_id', 'apparel_specialization_id', 'label', 'amount'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function apparelSpecialization(): BelongsTo
    {
        return $this->belongsTo(ApparelSpecialization::class);
    }
}
