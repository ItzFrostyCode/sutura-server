<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreApplication extends Model
{
    /** Document fields an admin can open, keyed by the URL segment. */
    public const DOCUMENTS = [
        'landmark' => 'landmark_image_path',
        'dti' => 'dti_registration_path',
        'tin' => 'tin_id_path',
        'barangay' => 'brgy_clearance_path',
        'government-id' => 'government_id_path',
        'receipt' => 'payment_receipt_path',
    ];

    protected $fillable = [
        'user_id', 'store_id', 'first_name', 'middle_name', 'last_name', 'suffix',
        'birthday', 'contact_number', 'requested_plan_id', 'billing_cycle', 'quoted_price',
        'payment_method', 'payment_receipt_path', 'landmark_image_path', 'dti_registration_path',
        'tin_id_path', 'brgy_clearance_path', 'government_id_path', 'government_id_type',
        'business_permit_paths', 'reviewed_by', 'reviewed_at',
    ];

    // Raw storage paths never leave the server — admins get documents
    // through the authenticated stream endpoint instead.
    protected $hidden = [
        'payment_receipt_path', 'landmark_image_path', 'dti_registration_path', 'tin_id_path',
        'brgy_clearance_path', 'government_id_path', 'business_permit_paths',
    ];

    protected $casts = [
        'birthday' => 'date',
        'quoted_price' => 'decimal:2',
        'business_permit_paths' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function requestedPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'requested_plan_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Which documents were actually uploaded, for the admin checklist. */
    public function documentList(): array
    {
        $docs = [];
        foreach (self::DOCUMENTS as $key => $column) {
            if ($this->{$column}) {
                $docs[] = ['key' => $key, 'type' => $this->mimeFor($this->{$column})];
            }
        }
        foreach ($this->business_permit_paths ?? [] as $i => $path) {
            $docs[] = ['key' => 'permit-'.$i, 'type' => $this->mimeFor($path)];
        }

        return $docs;
    }

    public function pathFor(string $key): ?string
    {
        if (isset(self::DOCUMENTS[$key])) {
            return $this->{self::DOCUMENTS[$key]};
        }
        if (preg_match('/^permit-(\d+)$/', $key, $m)) {
            return ($this->business_permit_paths ?? [])[(int) $m[1]] ?? null;
        }

        return null;
    }

    public function ownerFullName(): string
    {
        return trim(preg_replace('/\s+/', ' ', "{$this->first_name} {$this->middle_name} {$this->last_name} {$this->suffix}"));
    }

    private function mimeFor(string $path): string
    {
        return str_ends_with(strtolower($path), '.pdf') ? 'pdf' : 'image';
    }
}
