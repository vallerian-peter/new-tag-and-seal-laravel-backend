<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VetRating extends Model
{
    protected $fillable = ['requestId', 'vetId', 'farmerUserId', 'rating', 'review', 'tags'];

    protected $casts = [
        'rating' => 'integer',
        'tags' => 'array',
    ];

    public function vet(): BelongsTo
    {
        return $this->belongsTo(Vet::class, 'vetId');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(VetServiceRequest::class, 'requestId');
    }
}
