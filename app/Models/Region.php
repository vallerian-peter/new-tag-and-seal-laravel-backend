<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Region extends Model
{
    protected $fillable = [
        'name',
        'shortName',
        'countryId',
    ];

    public function country()
    {
        return $this->belongsTo(Country::class, 'countryId');
    }
}
