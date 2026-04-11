<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LivestockMarking extends Model
{
    protected $fillable = [
        'uuid',
        'eventDate',
        'farmUuid',
        'livestockUuid',
        'markingType',
        'description',
        'notes',
    ];
}
