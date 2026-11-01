<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MarketplaceOffer extends Model
{
    protected $table = 'marketplace_offers';

    protected $fillable = [
        'uuid',
        'listing_uuid',
        'buyer_user_id',
        'offered_amount',
        'counter_amount',
        'currency',
        'status',
        'buyer_notes',
        'seller_notes',
        'expires_at',
        'accepted_at',
    ];

    protected $casts = [
        'offered_amount' => 'decimal:2',
        'counter_amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function listing()
    {
        return $this->belongsTo(MarketplaceListing::class, 'listing_uuid', 'uuid');
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }
}
