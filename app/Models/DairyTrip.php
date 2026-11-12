<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DairyTrip extends Model
{
    protected $table = 'dairy_trips';

    protected $fillable = [
        'uuid',
        'trip_number',
        'transporter_company_uuid',
        'driver_user_id',
        'created_by_user_id',
        'commodity_code',
        'vehicle_uuid',
        'vehicle_plate_number',
        'destination_factory_uuid',
        'status',
        'approval_status',
        'approved_by_user_id',
        'approval_remarks',
        'approved_at',
        'started_at',
        'arrived_at',
        'completed_at',
        'total_liters',
        'notes',
        'max_tanker_capacity_liters',
        'current_cargo_liters',
        'phase',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'started_at' => 'datetime',
        'arrived_at' => 'datetime',
        'completed_at' => 'datetime',
        'total_liters' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function transporter()
    {
        return $this->belongsTo(TransporterCompany::class, 'transporter_company_uuid', 'uuid');
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_user_id', 'id');
    }

    public function vehicle()
    {
        return $this->belongsTo(TransporterVehicle::class, 'vehicle_uuid', 'uuid');
    }

    public function destinationFactory()
    {
        return $this->belongsTo(DairyFactory::class, 'destination_factory_uuid', 'uuid');
    }

    public function routes()
    {
        return $this->hasMany(DairyTripRoute::class, 'dairy_trip_uuid', 'uuid');
    }
}
