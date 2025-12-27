<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transfer extends Model
{
    protected $fillable = [
        'uuid',
        'eventDate',
        'farmUuid',
        'livestockUuid',
        'toFarmUuid',
        'transporterId',
        'reason',
        'price',
        'transferDate',
        'remarks',
        'status',
    ];

    public function fromFarm()
    {
        return $this->belongsTo(Farm::class, 'farmUuid', 'uuid');
    }

    public function livestock()
    {
        return $this->belongsTo(Livestock::class, 'livestockUuid', 'uuid');
    }

    public function toFarm()
    {
        return $this->belongsTo(Farm::class, 'toFarmUuid', 'uuid');
    }
}
