<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MarketplaceMessage extends Model
{
    protected $fillable = [
        'uuid',
        'thread_key',
        'category',
        'listing_uuid',
        'order_uuid',
        'offer_uuid',
        'livestock_uuid',
        'sender_user_id',
        'recipient_user_id',
        'topic',
        'message',
        'reply_to_uuid',
        'read_at',
        'sms_notified_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'sms_notified_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $msg): void {
            if (empty($msg->uuid)) {
                $msg->uuid = (string) Str::uuid();
            }
        });
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(MarketplaceListing::class, 'listing_uuid', 'uuid');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'order_uuid', 'uuid');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOffer::class, 'offer_uuid', 'uuid');
    }

    public function livestock(): BelongsTo
    {
        return $this->belongsTo(Livestock::class, 'livestock_uuid', 'uuid');
    }
}

