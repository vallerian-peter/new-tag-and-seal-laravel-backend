<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrepuceTreatmentGiven extends Model
{
    protected $table = 'prepuce_treatments_given';

    protected $fillable = [
        'name',
        'name_sw',
    ];
}
