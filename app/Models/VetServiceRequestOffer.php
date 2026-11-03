<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VetServiceRequestOffer extends Model
{
    public const OFFERED = 'offered';
    public const ACCEPTED = 'accepted';
    public const DECLINED = 'declined';
    public const EXPIRED = 'expired';
    public const WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'requestId', 'vetId', 'distanceKm', 'round', 'status',
        'declineReason', 'notifiedAt', 'respondedAt',
    ];

    protected $casts = [
        'distanceKm' => 'float',
        'round' => 'integer',
        'notifiedAt' => 'datetime',
        'respondedAt' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(VetServiceRequest::class, 'requestId');
    }

    public function vet(): BelongsTo
    {
        return $this->belongsTo(Vet::class, 'vetId');
    }
}
