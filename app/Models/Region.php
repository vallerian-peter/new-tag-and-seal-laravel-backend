<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Model
{
    protected $fillable = [
        'name',
        'shortName',
        'countryId',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'countryId');
    }

    public function districts(): HasMany
    {
        return $this->hasMany(District::class, 'regionId');
    }

    public function farmers(): HasMany
    {
        return $this->hasMany(Farmer::class, 'regionId');
    }

    public function farms(): HasMany
    {
        return $this->hasMany(Farm::class, 'regionId');
    }
}
