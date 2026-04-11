<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IronInjection extends Model
{
    protected $fillable = [
        'uuid',
        'eventDate',
        'farmUuid',
        'livestockUuid',
        'dosage',
        'medicineId',
        'notes',
    ];
}
