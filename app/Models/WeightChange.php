<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeightChange extends Model
{
    protected $fillable = [
        'uuid',
        'eventDate',
        'farmUuid',
        'livestockUuid',
        'oldWeight',
        'newWeight',
        'remarks'
    ];

    public function farm() {
        return $this->belongsTo(Farm::class, 'farmUuid', 'uuid');
    }

    public function livestock() {
        return $this->belongsTo(Livestock::class, 'livestockUuid', 'uuid');
    }
}
