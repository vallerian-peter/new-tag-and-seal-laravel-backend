<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrepuceClinicalSign extends Model
{
    protected $table = 'prepuce_clinical_signs';

    protected $fillable = [
        'name',
        'name_sw',
    ];
}
