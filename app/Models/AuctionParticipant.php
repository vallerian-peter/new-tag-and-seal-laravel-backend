<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuctionParticipant extends Model
{
    protected $fillable = ['sessionId', 'userId', 'bidderNumber', 'role', 'joinedAt', 'lastSeenAt'];

    protected $casts = ['joinedAt' => 'datetime', 'lastSeenAt' => 'datetime', 'bidderNumber' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userId');
    }
}
