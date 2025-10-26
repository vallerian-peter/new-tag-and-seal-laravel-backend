<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Division extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'districtId',
    ];

    /**
     * Get the district that owns the division.
     */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'districtId');
    }
}
