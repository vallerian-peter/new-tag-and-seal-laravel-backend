<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TransporterCompany extends Model
{
    protected $table = 'transporter_companies';

    protected $fillable = [
        'uuid',
        'name',
        'license_number',
        'tin_number',
        'contact_person',
        'phone',
        'email',
        'manager_user_id',
        'status',
        'document_urls',
        'approval_status',
        'approved_by_user_id',
        'approval_remarks',
        'approved_at',
        'is_active',
    ];

    protected $casts = [
        'document_urls' => 'array',
        'approved_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function vehicles()
    {
        return $this->hasMany(TransporterVehicle::class, 'transporter_company_uuid', 'uuid');
    }

    public function drivers()
    {
        return $this->hasMany(TransporterDriver::class, 'transporter_company_uuid', 'uuid');
    }

    public function trips()
    {
        return $this->hasMany(DairyTrip::class, 'transporter_company_uuid', 'uuid');
    }

}
