<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Village extends Model
{
    protected $fillable = [
        'name',
        'wardId',
    ];

    public function ward()
    {
        return $this->belongsTo(Ward::class, 'wardId');
    }
}
