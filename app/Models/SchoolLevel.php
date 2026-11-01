<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolLevel extends Model
{
    protected $fillable = [
        'name',
    ];

    public function farmers(): HasMany
    {
        return $this->hasMany(Farmer::class, 'schoolLevelId');
    }
}
