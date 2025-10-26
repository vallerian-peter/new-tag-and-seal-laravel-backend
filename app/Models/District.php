<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class District extends Model
{
    protected $fillable = [
        'name',
        'regionId',
    ];

    public function region()
    {
        return $this->belongsTo(Region::class, 'regionId');
    }
}
