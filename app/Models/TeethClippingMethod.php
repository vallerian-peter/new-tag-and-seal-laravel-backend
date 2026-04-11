<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeethClippingMethod extends Model
{
    protected $table = 'teeth_clipping_methods';

    protected $fillable = [
        'name',
    ];
}
