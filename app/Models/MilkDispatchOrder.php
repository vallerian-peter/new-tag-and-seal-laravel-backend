<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MilkDispatchOrder extends Model
{
    protected $table = 'milk_dispatch_orders';

    protected $fillable = [
        'uuid',
        'dispatch_code',
        'mcc_uuid',
        'factory_name',
        'transporter_user_id',
        'vehicle_registration_number',
        'driver_name',
        'driver_phone',
        'dispatched_volume_liters',
        'dispatch_temperature_celsius',
        'security_seal_numbers',
        'status',
        'dispatched_at',
    ];

    protected $casts = [
        'dispatched_volume_liters' => 'decimal:2',
        'dispatch_temperature_celsius' => 'decimal:2',
        'security_seal_numbers' => 'array',
        'dispatched_at' => 'datetime',
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

    public function transporter()
    {
        return $this->belongsTo(User::class, 'transporter_user_id', 'id');
    }

    public function factoryDelivery()
    {
        return $this->hasOne(MilkFactoryDelivery::class, 'dispatch_order_uuid', 'uuid');
    }

}
