<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TransporterVehicle extends Model
{
    protected $table = 'transporter_vehicles';

    protected $fillable = [
        'uuid',
        'transporter_company_uuid',
        'registration_number',
        'tanker_capacity_liters',
        'make_model',
        'vehicle_type',
        'color',
        'tare_weight_kg',
        'status',
        'calibration_certificate_url',
        'approval_status',
        'is_active',
    ];

    protected $casts = [
        'tanker_capacity_liters' => 'decimal:2',
        'tare_weight_kg' => 'decimal:2',
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

}
