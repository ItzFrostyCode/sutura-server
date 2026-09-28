<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogItem extends Model
{
    // Canonical garment taxonomy — the single source of truth the public
    // header nav's category links (navColumns.ts), the /search page's
    // category filter, and this field's own validation all key off. Before
    // this, garment_type was free text (owner-typed, "e.g. Barong, Gown,
    // Suit") while CatalogController::index()'s category= filter did an
    // exact, case-sensitive match against it — an owner who typed "Barong"
    // (capitalized, exactly as the old placeholder suggested) made their
    // own item invisible from every nav link and category filter expecting
    // lowercase "barong", with no error anywhere. Union of what the nav
    // already links to and what JobOrder's own garment_category enum
    // already recognized (lab_gown/scrub_suit/corporate_wear existed there
    // but had no catalog-side equivalent; jersey existed in the nav but not
    // in JobOrder's enum) — reconciled here as one list both sides share.
    public const GARMENT_CATEGORIES = [
        'barong', 'gown', 'suit', 'filipiniana', 'uniform', 'jersey',
        'lab_gown', 'scrub_suit', 'corporate_wear', 'alteration_repair',
    ];

    public const GARMENT_CATEGORY_LABELS = [
        'barong' => 'Barong Tagalog',
        'gown' => 'Gown',
        'suit' => 'Suit & Tuxedo',
        'filipiniana' => 'Filipiniana',
        'uniform' => 'School / Corporate Uniform',
        'jersey' => 'Jersey / Sublimation',
        'lab_gown' => 'Lab Gown',
        'scrub_suit' => 'Scrub Suit',
        'corporate_wear' => 'Corporate Wear',
        'alteration_repair' => 'Alteration / Repair',
    ];

    // The public header nav's other axis (MEN/WOMEN/WEDDING/OFFICE) — sent
    // as ?department= on nearly every nav link, but nothing ever read it
    // before this. Independent of garment category: a "suit" can be
    // men's, women's, or a wedding suit.
    public const DEPARTMENTS = ['men', 'women', 'wedding', 'office'];

    public const DEPARTMENT_LABELS = [
        'men' => 'Men',
        'women' => 'Women',
        'wedding' => 'Wedding',
        'office' => 'Office & Uniforms',
    ];

    protected $fillable = [
        'store_id', 'service_id', 'name', 'price', 'estimated_days',
        'material', 'color', 'fabric_image_url', 'sizes', 'description',
        'size_chart_image_url', 'size_chart_columns', 'size_chart_rows',
        'features', 'care_instructions', 'garment_type', 'department', 'listing_type', 'external_gallery_url',
        'is_active',
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
