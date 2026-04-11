<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrepuceConditionType extends Model
{
    protected $table = 'prepuce_condition_types';

    protected $fillable = [
        'name',
        'name_sw',
    ];
}
