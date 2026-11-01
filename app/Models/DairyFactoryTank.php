<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DairyFactoryTank extends Model
{
    protected $table = 'dairy_factory_tanks';

    protected $fillable = [
        'uuid',
        'factory_uuid',
        'tank_code',
        'capacity_liters',
        'current_stock_liters',
        'target_temperature_celsius',
        'status',
    ];

    protected $casts = [
        'capacity_liters' => 'decimal:2',
        'current_stock_liters' => 'decimal:2',
        'target_temperature_celsius' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function factory()
    {
        return $this->belongsTo(DairyFactory::class, 'factory_uuid', 'uuid');
    }

    public function stockMovements()
    {
        return $this->hasMany(DairyFactoryStockMovement::class, 'factory_tank_uuid', 'uuid');
    }

}
