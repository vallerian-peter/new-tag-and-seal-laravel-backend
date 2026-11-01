<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LivestockType extends Model
{
    protected $fillable = ['name'];

    public function species(): HasMany
    {
        return $this->hasMany(Specie::class, 'livestockTypeId');
    }

    public function breeds(): HasMany
    {
        return $this->hasMany(Breed::class, 'livestockTypeId');
    }

    public function livestocks(): HasMany
    {
        return $this->hasMany(Livestock::class, 'livestockTypeId');
    }
}
