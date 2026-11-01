<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MilkTank extends Model
{
    protected $table = 'milk_tanks';

    protected $fillable = [
        'uuid',
        'mcc_uuid',
        'tank_code',
        'capacity_liters',
        'target_temperature_celsius',
        'status',
    ];

    protected $casts = [
        'capacity_liters' => 'decimal:2',
        'target_temperature_celsius' => 'decimal:2',
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

    public function movements()
    {
        return $this->hasMany(MilkStockMovement::class, 'milk_tank_uuid', 'uuid');
    }

}
