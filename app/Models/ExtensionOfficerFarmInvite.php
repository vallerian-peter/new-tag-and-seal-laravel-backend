<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtensionOfficerFarmInvite extends Model
{
    protected $fillable = [
        'extensionOfficerId',
        'access_code',
        'farmerId',
    ];

    public function extensionOfficer(): BelongsTo
    {
        return $this->belongsTo(ExtensionOfficer::class, 'extensionOfficerId');
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class, 'farmerId');
    }
}
