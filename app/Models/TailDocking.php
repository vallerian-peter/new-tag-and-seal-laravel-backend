<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TailDocking extends Model
{
    protected $fillable = [
        'uuid',
        'eventDate',
        'farmUuid',
        'livestockUuid',
        'method',
        'notes',
    ];
}
