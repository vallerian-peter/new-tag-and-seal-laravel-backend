<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DairyPayoutAccrual extends Model
{
    protected $table = 'dairy_payout_accruals';

    protected $fillable = [
        'uuid',
        'intake_uuid',
        'farmer_user_id',
        'accepted_liters',
        'base_price_per_liter',
        'quality_bonus_per_liter',
        'coop_levy_per_liter',
        'net_payout_amount',
        'settlement_status',
        'payment_reference',
        'paid_at',
    ];

    protected $casts = [
        'accepted_liters' => 'decimal:2',
        'base_price_per_liter' => 'decimal:2',
        'quality_bonus_per_liter' => 'decimal:2',
        'coop_levy_per_liter' => 'decimal:2',
        'net_payout_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function intake()
    {
        return $this->belongsTo(MilkIntake::class, 'intake_uuid', 'uuid');
    }

    public function farmer()
    {
        return $this->belongsTo(User::class, 'farmer_user_id', 'id');
    }

}
