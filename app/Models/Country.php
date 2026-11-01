<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    protected $fillable = [
        'name',
        'shortName',
    ];

    public function regions(): HasMany
    {
        return $this->hasMany(Region::class, 'countryId');
    }

    public function farmers(): HasMany
    {
        return $this->hasMany(Farmer::class, 'countryId');
    }

    public function farms(): HasMany
    {
        return $this->hasMany(Farm::class, 'countryId');
    }
}
