<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DairyTripRoute extends Model
{
    protected $table = 'dairy_trip_routes';

    protected $fillable = [
        'uuid',
        'dairy_trip_uuid',
        'dispatch_order_uuid',
        'mcc_uuid',
        'sequence_order',
        'stop_type',
        'status',
        'liters',
        'temperature_celsius',
        'security_seal_numbers',
        'added_mid_trip',
        'added_at',
        'destination_factory_uuid',
        'phase',
    ];

    protected $casts = [
        'liters' => 'decimal:2',
        'temperature_celsius' => 'decimal:2',
        'security_seal_numbers' => 'array',
        'added_mid_trip' => 'boolean',
        'added_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function trip()
    {
        return $this->belongsTo(DairyTrip::class, 'dairy_trip_uuid', 'uuid');
    }

    public function mcc()
    {
        return $this->belongsTo(MilkCollectionCentre::class, 'mcc_uuid', 'uuid');
    }

    public function dispatchOrder()
    {
        return $this->belongsTo(MilkDispatchOrder::class, 'dispatch_order_uuid', 'uuid');
    }

    public function destinationFactory()
    {
        return $this->belongsTo(DairyFactory::class, 'destination_factory_uuid', 'uuid');
    }

}
