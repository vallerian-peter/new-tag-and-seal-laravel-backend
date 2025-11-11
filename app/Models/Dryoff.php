<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dryoff extends Model
{
    protected $fillable = [
        'uuid',
        'farmUuid',
        'livestockUuid',
        'startDate',
        'endDate',
        'reason',
        'remarks',
    ];

    public function farm()
    {
        return $this->belongsTo(Farm::class, 'farmUuid', 'uuid');
    }

    public function livestock()
    {
        return $this->belongsTo(Livestock::class, 'livestockUuid', 'uuid');
    }
}
