<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Village extends Model
{
    protected $fillable = [
        'name',
        'wardId',
    ];

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class, 'wardId');
    }

    public function farmers(): HasMany
    {
        return $this->hasMany(Farmer::class, 'villageId');
    }

    public function farms(): HasMany
    {
        return $this->hasMany(Farm::class, 'villageId');
    }
}
