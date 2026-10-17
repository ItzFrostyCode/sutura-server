<?php

namespace App\Models;

use App\Support\CanonicalTaxonomy;
use Illuminate\Database\Eloquent\Model;

class CatalogItem extends Model
{
    // Since the 2-level taxonomy (2026-10-14) this is every known garment slug, old and new: an old slug
    // still saved on a design must not be mistaken for a typed-in custom name (which would hide the design
    // until the admin approves it). Old slugs resolve through CanonicalTaxonomy::LEGACY_GARMENT_SLUGS.
    public static function garmentCategories(): array
    {
        return CanonicalTaxonomy::knownGarmentSlugs();
    }

    protected $fillable = [
        'store_id', 'service_id', 'name', 'price', 'estimated_days', 'estimated_days_max',
        // How the design is priced against its service (App\Support\DesignPricing).
        'pricing_mode', 'extra_charge',
        'material', 'color', 'fabric_image_url', 'sizes', 'description',
        'size_chart_image_url', 'size_chart_columns', 'size_chart_rows',
        'measurement_guide',
        'features', 'care_instructions', 'garment_type', 'listing_type', 'external_gallery_url',
        'is_active',
        'measurement_requirement', 'fitting_requirement', 'payment_policy', 'payment_policy_percent',
        // Server-resolved from garment_type in CatalogController, never client input.
        'custom_apparel_type_id',
    ];

    // admin_hidden_at/admin_hidden_reason are deliberately NOT fillable —
    // only Admin\ModerationController may set them, via forceFill().
    protected $casts = [
        'admin_hidden_at' => 'datetime',
        'size_chart_columns' => 'array',
        'size_chart_rows' => 'array',
        'features' => 'array',
        'sizes' => 'array',
        'is_active' => 'boolean',
    ];

    // Men / Women / Kids, subcategory and top/bottom (2026-10-14) stay in the table, unused and never shown.
    protected $hidden = ['department', 'subcategory', 'garment_structure'];

    protected $appends = ['category', 'apparel_review'];

    public function getCategoryAttribute(): ?string
    {
        return $this->garment_type;
    }

    /**
     * What the public can see: active, and — when the design uses a typed-in
     * apparel name — only once the System Admin has approved that name.
     */
    public function scopeListed($query)
    {
        return $query->where('catalog_items.is_active', true)
            ->where(fn ($q) => $q->whereNull('catalog_items.custom_apparel_type_id')
                ->orWhereHas('customApparelType', fn ($t) => $t->where('status', 'approved')));
    }

    /** Designs of one garment, whether saved under its current slug or an old slug merged into it. */
    public function scopeOfGarment($query, string $slug)
    {
        $variants = CanonicalTaxonomy::garmentVariants($slug);

        return $query->where(function ($q) use ($variants) {
            foreach ($variants as $v) {
                $q->orWhereRaw('LOWER(catalog_items.garment_type) = ?', [$v]);
            }
        });
    }

    /** Designs of any of several garments (each with its old slugs). */
    public function scopeOfGarments($query, array $slugs)
    {
        $variants = collect($slugs)->flatMap(fn ($s) => CanonicalTaxonomy::garmentVariants($s))->unique()->values()->all();

        return $query->where(function ($q) use ($variants) {
            foreach ($variants as $v) {
                $q->orWhereRaw('LOWER(catalog_items.garment_type) = ?', [$v]);
            }
        });
    }

    public function isListed(): bool
    {
        return $this->is_active && (! $this->custom_apparel_type_id || $this->customApparelType?->isApproved());
    }

    /** null when nothing is under review; otherwise the custom name's review state for the owner's badge. */
    public function getApparelReviewAttribute(): ?array
    {
        $type = $this->custom_apparel_type_id ? $this->customApparelType : null;
        if (! $type || $type->isApproved()) {
            return null;
        }

        return ['status' => $type->status, 'label' => $type->label, 'reason' => $type->rejection_reason];
    }

    public function customApparelType()
    {
        return $this->belongsTo(CustomApparelType::class);
    }

    // Missing entirely despite store_id being a real column — every place
    // that needed the owning store had to join/query around it manually.
    // Needed for CatalogItemReviewReplyNotification's storefront link.
    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function images()
    {
        return $this->hasMany(CatalogImage::class);
    }

    public function recommendations()
    {
        return $this->hasMany(CatalogRecommendation::class);
    }

    public function reviews()
    {
        return $this->hasMany(CatalogItemReview::class);
    }

    public function saves()
    {
        return $this->hasMany(CatalogItemSave::class);
    }

    public function catalogOrders()
    {
        return $this->hasMany(CatalogOrder::class, 'catalog_item_id');
    }

    public function jobOrders()
    {
        return $this->hasMany(JobOrder::class, 'catalog_item_id');
    }
}
