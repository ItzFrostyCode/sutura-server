<?php

namespace App\Models;

use App\Support\CanonicalTaxonomy;
use Illuminate\Database\Eloquent\Model;

class CatalogItem extends Model
{
    // Canonical garment taxonomy — the single source of truth the public
    // header nav's category links (navColumns.ts), the /search page's
    // category filter, and this field's own validation all key off.
    // Transcribed from Mocked Layout/Categories.md via
    // App\Support\CanonicalTaxonomy (mirrored on the frontend at
    // src/lib/canonicalTaxonomy.ts) — this is the flattened leaf-level
    // (Category → Subcategory → Garment Structure → Garment Type) list,
    // not a flat ad hoc enum anymore. See `subcategory`/`garment_structure`
    // below for the rest of the hierarchy.
    public static function garmentCategories(): array
    {
        return CanonicalTaxonomy::allGarmentTypeSlugs();
    }

    public static function garmentCategoryLabels(): array
    {
        return CanonicalTaxonomy::GARMENT_TYPE_LABELS;
    }

    // The public header nav's other axis (MEN/WOMEN/KIDS) — sent as
    // ?department= on nearly every nav link. Independent of garment
    // category: a "suit" can be men's or women's. Wedding/Office are no
    // longer departments — per Categories.md they're cross-cutting
    // filters/attributes, not part of the category tree.
    public const DEPARTMENTS = CanonicalTaxonomy::DEPARTMENTS;

    public const DEPARTMENT_LABELS = CanonicalTaxonomy::DEPARTMENT_LABELS;

    public const SUBCATEGORIES = CanonicalTaxonomy::SUBCATEGORY_LABELS;

    public const GARMENT_STRUCTURES = CanonicalTaxonomy::GARMENT_STRUCTURES;

    protected $fillable = [
        'store_id', 'service_id', 'name', 'price', 'estimated_days', 'estimated_days_max',
        'material', 'color', 'fabric_image_url', 'sizes', 'description',
        'size_chart_image_url', 'size_chart_columns', 'size_chart_rows',
        'measurement_guide',
        'features', 'care_instructions', 'garment_type', 'department',
        'subcategory', 'garment_structure', 'listing_type', 'external_gallery_url',
        'is_active',
        'measurement_requirement', 'fitting_requirement', 'payment_policy', 'payment_policy_percent',
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

    protected $appends = ['category'];

    public function getCategoryAttribute(): ?string
    {
        return $this->garment_type;
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
