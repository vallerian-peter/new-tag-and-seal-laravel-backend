<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PurchaseOrder extends Model
{
    protected $table = 'purchase_orders';

    protected $fillable = [
        'uuid',
        'order_code',
        'seller_user_id',
        'buyer_user_id',
        'origin_farm_uuid',
        'destination_farm_uuid',
        'total_amount',
        'currency',
        'source_type',
        'offer_uuid',
        'status',
        'reserved_until',
        'settlement_method',
        'settlement_verified_at',
        'settlement_verified_by_user_id',
        'payment_evidence_ref',
        'payment_evidence_notes',
        'payment_evidence_receipt',
        'payment_evidence_submitted_at',
        'cancellation_reason',
        'cancelled_by_user_id',
        'dispute_reason',
        'disputed_at',
        'sms_notified_at',
        'handover_challenge_hash',
        'handover_challenge_expires_at',
        'handover_verified_at',
        'seller_handover_confirmed_at',
        'buyer_handover_confirmed_at',
        'ownership_transferred_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'reserved_until' => 'datetime',
        'settlement_verified_at' => 'datetime',
        'payment_evidence_submitted_at' => 'datetime',
        'disputed_at' => 'datetime',
        'sms_notified_at' => 'datetime',
        'handover_challenge_expires_at' => 'datetime',
        'handover_verified_at' => 'datetime',
        'seller_handover_confirmed_at' => 'datetime',
        'buyer_handover_confirmed_at' => 'datetime',
        'ownership_transferred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
            $model->order_code ??= 'ORD-' . strtoupper(Str::random(8));
            $model->reserved_until ??= now()->addHours(48);
        });
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function originFarm()
    {
        return $this->belongsTo(Farm::class, 'origin_farm_uuid', 'uuid');
    }

    public function destinationFarm()
    {
        return $this->belongsTo(Farm::class, 'destination_farm_uuid', 'uuid');
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class, 'order_uuid', 'uuid');
    }
}
