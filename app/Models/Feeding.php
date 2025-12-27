<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Feeding extends Model
{
    protected $fillable = [
        'uuid',
        'eventDate',
        'feedingTypeId',
        'farmUuid',
        'livestockUuid',
        'nextFeedingTime',
        'amount',
        'remarks',
    ];

    public function feedingType()
    {
        return $this->belongsTo(FeedingType::class, 'feedingTypeId');
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class, 'farmUuid', 'uuid');
    }

    public function livestock()
    {
        return $this->belongsTo(Livestock::class, 'livestockUuid', 'uuid');
    }

}
