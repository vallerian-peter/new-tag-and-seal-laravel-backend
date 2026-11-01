<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LegalStatus extends Model
{
    protected $fillable = [
        'name',
    ];

    public function farms(): HasMany
    {
        return $this->hasMany(Farm::class, 'legalStatusId');
    }
}
