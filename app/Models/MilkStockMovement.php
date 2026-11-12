<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MilkStockMovement extends Model
{
    protected $table = 'milk_stock_movements';

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'mcc_uuid',
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

    public function mcc()
    {
        return $this->belongsTo(MilkCollectionCentre::class, 'mcc_uuid', 'uuid');
    }
}
