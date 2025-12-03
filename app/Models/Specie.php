<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Specie extends Model
{
    protected $fillable = ['name', 'livestockTypeId'];

    public function livestockType()
    {
        return $this->belongsTo(LivestockType::class, 'livestockTypeId');
    }
}
