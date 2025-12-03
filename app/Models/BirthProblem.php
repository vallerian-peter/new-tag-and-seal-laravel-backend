<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BirthProblem extends Model
{
    protected $table = 'birth_problems';

    protected $fillable = [
        'name',
        'livestockTypeId',
    ];

    public function livestockType(): BelongsTo
    {
        return $this->belongsTo(LivestockType::class, 'livestockTypeId');
    }
}

