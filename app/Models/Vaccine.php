<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vaccine extends Model
{
    protected $fillable = [
        'uuid',
        'farmUuid',
        'name',
        'lot',
        'formulationType', //enum('live-attenuated', 'inactivated')
        'dose',
        'status', //enum('active', 'inactive', 'expired')
        'vaccineTypeId',
        'vaccineSchedule',
    ];

    public function vaccineType()
    {
        return $this->belongsTo(VaccineType::class, 'vaccineTypeId');
    }
    public function vaccineSchedule()
    {
        return $this->belongsTo(VaccineSchedule::class, 'vaccineSchedule');
    }
    public function farm()
    {
        return $this->belongsTo(Farm::class, 'farmUuid', 'uuid');
    }
}

