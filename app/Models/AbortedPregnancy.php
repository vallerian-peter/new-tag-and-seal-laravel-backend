<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbortedPregnancy extends Model
{
    protected $fillable = [
        'uuid',
        'eventDate',
        'farmUuid',
        'livestockUuid',
        'abortionDate',
        'reproductiveProblemId',
        'remarks',
        'status',
    ];

    protected $casts = [
        'abortionDate' => 'date',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class, 'farmUuid', 'uuid');
    }

    public function livestock(): BelongsTo
    {
        return $this->belongsTo(Livestock::class, 'livestockUuid', 'uuid');
    }

    public function reproductiveProblem(): BelongsTo
    {
        return $this->belongsTo(ReproductiveProblem::class, 'reproductiveProblemId');
    }
}

