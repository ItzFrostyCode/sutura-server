<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class StoreBranch extends Model
{
    protected $fillable = [
        'store_id', 'name', 'slug', 'address', 'landmark', 'barangay', 'city', 'district', 'latitude', 'longitude', 'contact_number', 'is_main', 'operating_hours', 'status', 'guide_image_url',
        'verification_status', 'verified_at', 'verified_by', 'verification_note',
    ];

    protected $casts = ['verified_at' => 'datetime'];

    /** What the public may see: switched on by the shop AND location-checked by the System Admin. */
    public function scopeLive($query)
    {
        return $query->where('status', 'active')->where('verification_status', 'verified');
    }

    /**
     * The search screens' place filter, for `whereHas('branches' | 'store.branches', …)`: a district, one of its
     * barangays, and/or a street or landmark (`area`: the branch's address, landmark or barangay contains it; several
     * spellings separated by "|", e.g. "km. 5|km 5"). Live branches only. Null when the request names no place.
     */
    public static function placeFilter(Request $request): ?Closure
    {
        $district = trim($request->string('district')->toString());
        $barangay = mb_strtolower(trim($request->string('barangay')->toString()));
        $terms = array_slice(array_filter(array_map(
            fn ($t) => mb_strtolower(trim($t)),
            explode('|', $request->string('area')->toString())
        ), fn ($t) => mb_strlen($t) >= 2), 0, 5);
        if ($district === '' && $barangay === '' && $terms === []) {
            return null;
        }

        return function ($branches) use ($district, $barangay, $terms) {
            $branches->live()
                ->when($district !== '', fn ($b) => $b->where('district', $district))
                ->when($barangay !== '', fn ($b) => $b->whereRaw('LOWER(barangay) = ?', [$barangay]))
                ->when($terms !== [], fn ($b) => $b->where(function ($w) use ($terms) {
                    foreach ($terms as $term) {
                        $like = '%'.addcslashes($term, '%_\\').'%';
                        $w->orWhereRaw('LOWER(address) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(landmark) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(barangay) LIKE ?', [$like]);
                    }
                }));
        };
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function staffProfiles()
    {
        return $this->hasMany(StaffProfile::class);
    }

    public function manager()
    {
        return $this->hasOne(StaffProfile::class, 'store_branch_id')->where('is_branch_manager', true);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function jobOrders()
    {
        return $this->hasMany(JobOrder::class);
    }
}
