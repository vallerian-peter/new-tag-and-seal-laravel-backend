<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TransporterDriver extends Model
{
    protected $table = 'transporter_drivers';

    protected $fillable = [
        'uuid',
        'transporter_company_uuid',
        'user_id',
        'driver_license_number',
        'assigned_vehicle_uuid',
        'is_active',
        'approval_status',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function assignedVehicle()
    {
        return $this->belongsTo(TransporterVehicle::class, 'assigned_vehicle_uuid', 'uuid');
    }

}
