<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TailDockingMethod extends Model
{
    protected $table = 'tail_docking_methods';

    protected $fillable = [
        'name',
    ];
}
