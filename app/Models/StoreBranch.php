<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreBranch extends Model
{
    protected $fillable = [
        'store_id', 'name', 'slug', 'address', 'landmark', 'city', 'latitude', 'longitude', 'contact_number', 'is_main', 'operating_hours', 'status', 'guide_image_url',
    ];

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
