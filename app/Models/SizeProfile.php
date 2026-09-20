<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SizeProfile extends Model
{
    protected $fillable = ['customer_id', 'height_cm', 'weight_kg', 'metrics'];

    protected $casts = [
        'height_cm' => 'decimal:1',
        'weight_kg' => 'decimal:1',
        'metrics'   => 'array',
    ];

    /** The optional secondary fields the frontend's "Other Measurements"
     *  section collects — all cm. Kept here (not just client-side) so the
     *  size-recommendation matching in CatalogController can validate a
     *  key before comparing it against a size chart column. */
    public const METRIC_KEYS = [
        'shoulder', 'bust', 'under_bust', 'waist', 'hip', 'thigh', 'ball_girth', 'foot_length',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
