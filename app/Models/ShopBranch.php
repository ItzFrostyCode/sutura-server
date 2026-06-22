<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopBranch extends Model
{
    protected $fillable = [
        'shop_id', 'name', 'address', 'city', 'latitude', 'longitude', 'contact_number', 'is_main', 'operating_hours', 'status', 'guide_image_url'
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function staffProfiles()
    {
        return $this->hasMany(StaffProfile::class);
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
