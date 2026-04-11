<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrepuceHealingStatus extends Model
{
    protected $table = 'prepuce_healing_statuses';

    protected $fillable = [
        'name',
        'name_sw',
    ];
}
