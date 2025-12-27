<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pregnancy extends Model
{
    protected $fillable = [
        'uuid',
        'eventDate',
        'farmUuid',
        'livestockUuid',
        'testResultId',
        'noOfMonths',
        'testDate',
        'status',
        'remarks',
    ];

    public function testResult(): BelongsTo
    {
        return $this->belongsTo(TestResults::class, 'testResultId');
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
