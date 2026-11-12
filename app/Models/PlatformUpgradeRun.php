<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformUpgradeRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'final_report_json' => 'array',
    ];
}

