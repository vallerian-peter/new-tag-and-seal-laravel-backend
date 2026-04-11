<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrepuceCauseRisk extends Model
{
    protected $table = 'prepuce_cause_risks';

    protected $fillable = [
        'name',
        'name_sw',
    ];
}
