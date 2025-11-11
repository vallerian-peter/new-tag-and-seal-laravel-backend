<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Disposal extends Model
{
    protected $fillable = [
        'uuid',
        'farmUuid',
        'livestockUuid',
        'disposalTypeId',
        'reasons',
        'remarks',
        'status',
    ];

    public function farm()
    {
        return $this->belongsTo(Farm::class, 'farmUuid', 'uuid');
    }

    public function livestock()
    {
        return $this->belongsTo(Livestock::class, 'livestockUuid', 'uuid');
    }

    public function disposalType()
    {
        return $this->belongsTo(DisposalType::class, 'disposalTypeId', 'id');
    }
}
