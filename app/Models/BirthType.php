<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BirthType extends Model
{
    protected $table = 'birth_types';

    protected $fillable = [
        'name',
        'livestockTypeId',
    ];

    public function livestockType(): BelongsTo
    {
        return $this->belongsTo(LivestockType::class, 'livestockTypeId');
    }
}

