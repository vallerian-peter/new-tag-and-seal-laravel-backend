<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DairyFactory extends Model
{
    protected $table = 'dairy_factories';

    protected $fillable = [
        'uuid',
        'name',
        'factory_code',
        'registration_number',
        'dairy_board_license_no',
        'contact_name',
        'contact_phone',
        'contact_email',
        'region_id',
        'district_id',
        'address',
        'latitude',
        'longitude',
        'total_storage_capacity_liters',
        'document_urls',
        'approval_status',
        'approved_by_user_id',
        'approval_remarks',
        'approved_at',
        'is_active',
        'current_inventory_liters',
        'manager_user_id',
        'status',
    ];

    protected $casts = [
        'latitude' => 'decimal:2',
        'longitude' => 'decimal:2',
        'total_storage_capacity_liters' => 'decimal:2',
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

    public function region()
    {
        return $this->belongsTo(Region::class, 'region_id', 'id');
    }

    public function district()
    {
        return $this->belongsTo(District::class, 'district_id', 'id');
    }

    public function tanks()
    {
        return $this->hasMany(DairyFactoryTank::class, 'factory_uuid', 'uuid');
    }

    public function operators()
    {
        return $this->hasMany(FactoryOperator::class, 'factory_uuid', 'uuid');
    }

    public function stockMovements()
    {
        return $this->hasMany(DairyFactoryStockMovement::class, 'factory_uuid', 'uuid');
    }

}
