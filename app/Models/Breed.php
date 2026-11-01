<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Breed extends Model
{
    protected $fillable = [
        'name',
        'group',
        'livestockTypeId',
    ];

    public function livestockType(): BelongsTo
    {
        return $this->belongsTo(LivestockType::class, 'livestockTypeId');
    }

    public function livestocks(): HasMany
    {
        return $this->hasMany(Livestock::class, 'breedId');
    }
}
