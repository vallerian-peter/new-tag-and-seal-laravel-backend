<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrepuceSeverity extends Model
{
    protected $table = 'prepuce_severities';

    protected $fillable = [
        'name',
        'name_sw',
    ];
}
