<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalvingType extends Model
{
    protected $table = 'birth_types'; // Table renamed to birth_types
    
    protected $fillable = [
        'name',
        'livestockTypeId',
    ];

    public function livestockType(): BelongsTo
    {
        return $this->belongsTo(LivestockType::class, 'livestockTypeId');
    }
}
