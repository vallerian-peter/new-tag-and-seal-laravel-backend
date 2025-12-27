<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Milking extends Model
{
    protected $fillable = [
        'uuid',
        'eventDate',
        'livestockUuid',
        'farmUuid',
        'milkingMethodId',
        'amount',
        'lactometerReading',
        'solid',
        'solidNonFat',
        'protein',
        'correctedLactometerReading',
        'totalSolids',
        'colonyFormingUnits',
        'acidity',
        'session',
        'status',
    ];

    public function milkingMethod(): BelongsTo
    {
        return $this->belongsTo(MilkingMethod::class, 'milkingMethodId');
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class, 'farmUuid', 'uuid');
    }

    public function livestock(): BelongsTo
    {
        return $this->belongsTo(Livestock::class, 'livestockUuid', 'uuid');
    }
}
