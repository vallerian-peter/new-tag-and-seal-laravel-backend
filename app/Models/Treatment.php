<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Treatment extends Model
{
    protected $table = 'treatments';

    protected $fillable = [
        'uuid',
        'eventDate',
        'farmUuid',
        'livestockUuid',
        'diseaseId',
        'medicineId',
        'quantity',
        'withdrawalPeriod',
        'medicationDate',
        'nextMedicationDate',
        'remarks',
        'vetId',
        'serviceRequestUuid',
        'created_at',
        'updated_at',
    ];

    public function farm()
    {
        return $this->belongsTo(Farm::class, 'farmUuid', 'uuid');
    }

    public function livestock()
    {
        return $this->belongsTo(Livestock::class, 'livestockUuid', 'uuid');
    }

    public function disease()
    {
        return $this->belongsTo(Disease::class, 'diseaseId', 'id');
    }

    public function medicine()
    {
        return $this->belongsTo(Medicines::class, 'medicineId', 'id');
    }

    public function vet()
    {
        return $this->belongsTo(Vet::class, 'vetId', 'id');
    }
}
