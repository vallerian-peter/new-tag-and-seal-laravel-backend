<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Street extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'wardId',
    ];

    /**
     * Get the ward that owns the street.
     */
    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class, 'wardId');
    }
}
