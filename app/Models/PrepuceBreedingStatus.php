<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrepuceBreedingStatus extends Model
{
    protected $table = 'prepuce_breeding_statuses';

    protected $fillable = [
        'name',
        'name_sw',
    ];
}
