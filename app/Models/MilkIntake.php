<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MilkIntake extends Model
{
    protected $table = 'milk_intakes';

    protected $fillable = [
        'uuid',
        'mcc_uuid',
        'batch_uuid',
        'farmer_user_id',
        'clerk_user_id',
        'gross_liters_delivered',
        'accepted_liters',
        'rejected_liters',
        'temperature_celsius',
        'density_lactometer',
        'fat_percentage',
        'alcohol_test_passed',
        'antibiotic_free',
        'rejection_reason',
        'scale_device_id',
        'intake_timestamp',
    ];

    protected $casts = [
        'gross_liters_delivered' => 'decimal:2',
        'accepted_liters' => 'decimal:2',
        'rejected_liters' => 'decimal:2',
        'temperature_celsius' => 'decimal:2',
        'density_lactometer' => 'decimal:2',
        'fat_percentage' => 'decimal:2',
        'alcohol_test_passed' => 'boolean',
        'antibiotic_free' => 'boolean',
        'intake_timestamp' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function mcc()
    {
        return $this->belongsTo(MilkCollectionCentre::class, 'mcc_uuid', 'uuid');
    }

    public function batch()
    {
        return $this->belongsTo(MilkBatch::class, 'batch_uuid', 'uuid');
    }

    public function farmer()
    {
        return $this->belongsTo(User::class, 'farmer_user_id', 'id');
    }

    public function clerk()
    {
        return $this->belongsTo(User::class, 'clerk_user_id', 'id');
    }

    public function payoutAccrual()
    {
        return $this->hasOne(DairyPayoutAccrual::class, 'intake_uuid', 'uuid');
    }

}
