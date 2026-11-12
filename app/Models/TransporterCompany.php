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
        'mcu_uuid',
        'license_number',
        'tin_number',
        'contact_person',
        'phone',
        'email',
        'contact_phone',
        'contact_email',
        'contact_title',
        'region_id',
        'district_id',
        'physical_address',
        'website',
        'rejected_at',
        'archived_at',
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
        'rejected_at' => 'datetime',
        'archived_at' => 'datetime',
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

    public const TIN_PATTERN = '/^\d{3}-\d{3}-\d{3}$/';

    /** Nine TIN digits typed with or without dashes are stored as 123-456-789. */
    public static function normaliseTin(mixed $tin): mixed
    {
        if (! is_string($tin)) {
            return $tin;
        }
        $digits = preg_replace('/\D/', '', $tin);

        return strlen($digits) === 9 ? implode('-', str_split($digits, 3)) : trim($tin);
    }
}
