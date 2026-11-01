<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LivestockObtainedMethod extends Model
{
    protected $fillable = ['name'];

    public function livestocks(): HasMany
    {
        return $this->hasMany(Livestock::class, 'livestockObtainedMethodId');
    }
}
