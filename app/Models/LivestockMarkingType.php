<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LivestockMarkingType extends Model
{
    protected $table = 'livestock_marking_types';

    protected $fillable = [
        'name',
    ];
}
