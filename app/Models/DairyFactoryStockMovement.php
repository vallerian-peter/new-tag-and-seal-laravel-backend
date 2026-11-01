<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DairyFactoryStockMovement extends Model
{
    protected $table = 'dairy_factory_stock_movements';

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'factory_uuid',
        'factory_tank_uuid',
        'movement_type',
        'quantity_liters',
        'reference_uuid',
        'recorded_by_user_id',
        'notes',
    ];

    protected $casts = [
        'quantity_liters' => 'decimal:2',
        'created_at' => 'datetime',
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

    public function tank()
    {
        return $this->belongsTo(DairyFactoryTank::class, 'factory_tank_uuid', 'uuid');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id', 'id');
    }

}
