<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class District extends Model
{
    protected $fillable = [
        'name',
        'regionId',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'regionId');
    }

    public function divisions(): HasMany
    {
        return $this->hasMany(Division::class, 'districtId');
    }

    public function wards(): HasMany
    {
        return $this->hasMany(Ward::class, 'districtId');
    }

    public function farmers(): HasMany
    {
        return $this->hasMany(Farmer::class, 'districtId');
    }

    public function farms(): HasMany
    {
        return $this->hasMany(Farm::class, 'districtId');
    }
}
