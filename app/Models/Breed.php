<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Breed extends Model
{
    protected $fillable = [
        'name',
        'group',
        'livestockTypeId'
    ];

    public function livestockType()
    {
        return $this->belongsTo(LivestockType::class);
    }
}
