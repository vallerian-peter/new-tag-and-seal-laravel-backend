<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VaccineType extends Model
{
    protected $fillable = [
        'name',
    ];

    public function vaccines(): HasMany
    {
        return $this->hasMany(Vaccine::class, 'vaccineTypeId');
    }
}
