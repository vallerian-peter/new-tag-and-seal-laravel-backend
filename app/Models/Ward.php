<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ward extends Model
{
    protected $fillable = [
        'name',
        'districtId',
    ];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'districtId');
    }

    public function villages(): HasMany
    {
        return $this->hasMany(Village::class, 'wardId');
    }

    public function streets(): HasMany
    {
        return $this->hasMany(Street::class, 'wardId');
    }

    public function farmers(): HasMany
    {
        return $this->hasMany(Farmer::class, 'wardId');
    }

    public function farms(): HasMany
    {
        return $this->hasMany(Farm::class, 'wardId');
    }
}
