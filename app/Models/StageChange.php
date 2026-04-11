<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StageChange extends Model
{
    protected $fillable = [
        'uuid',
        'eventDate',
        'farmUuid',
        'livestockUuid',
        'fromStageId',
        'toStageId',
        'notes',
    ];
}
