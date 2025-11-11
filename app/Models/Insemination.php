<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Insemination extends Model
{
    protected $fillable = [
        'uuid',
        'livestockUuid',
        'farmUuid',
        'lastHeatDate',
        'currentHeatTypeId',
        'inseminationServiceId',
        'semenStrawTypeId',
        'inseminationDate',
        'bullCode',
        'bullBreed',
        'semenProductionDate',
        'productionCountry',
        'semenBatchNumber',
        'internationalId',
        'aiCode',
        'manufacturerName',
        'semenSupplier',
    ];

    public function currentHeatType(): BelongsTo
    {
        return $this->belongsTo(HeatType::class, 'currentHeatTypeId');
    }

    public function inseminationService(): BelongsTo
    {
        return $this->belongsTo(InseminationService::class, 'inseminationServiceId');
    }

    public function semenStrawType(): BelongsTo
    {
        return $this->belongsTo(SemenStrawType::class, 'semenStrawTypeId');
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
