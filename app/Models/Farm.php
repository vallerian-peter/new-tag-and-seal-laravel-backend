<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Farm extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'farmerId',
        'uuid',
        'referenceNo',
        'regionalRegNo',
        'name',
        'size',
        'sizeUnit',
        'latitudes',
        'longitudes',
        'physicalAddress',
        'villageId',
        'wardId',
        'districtId',
        'regionId',
        'countryId',
        'legalStatusId',
        'status', // active/not-active
        'created_at', // Allow manual timestamp management
        'updated_at', // Allow manual timestamp management
    ];

    public function farmer()
    {
        return $this->belongsTo(Farmer::class, 'farmerId');
    }


    public function village()
    {
        return $this->belongsTo(Village::class, 'villageId');
    }

    public function ward()
    {
        return $this->belongsTo(Ward::class, 'wardId');
    }

    public function district()
    {
        return $this->belongsTo(District::class, 'districtId');
    }

    public function region()
    {
        return $this->belongsTo(Region::class, 'regionId');
    }

    public function country()
    {
        return $this->belongsTo(Country::class, 'countryId');
    }

    public function legalStatus()
    {
        return $this->belongsTo(LegalStatus::class, 'legalStatusId');
    }
}
