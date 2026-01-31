<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Calving extends Model
{
    protected $table = 'birth_events';

    protected $fillable = [
        'uuid',
        'eventDate',
        'farmUuid',
        'livestockUuid',
        'eventType',
        'startDate',
        'endDate',
        'birthTypeId',
        'birthProblemsId',
        'reproductiveProblemId',
        'remarks',
        'status',
    ];

    public function calvingType(): BelongsTo
    {
        return $this->belongsTo(BirthType::class, 'birthTypeId');
    }

    public function calvingProblem(): BelongsTo
    {
        return $this->belongsTo(BirthProblem::class, 'birthProblemsId');
    }

    public function reproductiveProblem(): BelongsTo
    {
        return $this->belongsTo(ReproductiveProblem::class, 'reproductiveProblemId');
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
