<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MilkFactoryDelivery extends Model
{
    protected $table = 'milk_factory_deliveries';

    protected $fillable = [
        'uuid',
        'dispatch_order_uuid',
        'factory_qa_officer_user_id',
        'received_volume_liters',
        'arrival_temperature_celsius',
        'seals_intact',
        'factory_qa_status',
        'discrepancy_liters',
        'spillage_reason',
        'delivery_note_number',
        'received_at',
    ];

    protected $casts = [
        'received_volume_liters' => 'decimal:2',
        'arrival_temperature_celsius' => 'decimal:2',
        'seals_intact' => 'boolean',
        'discrepancy_liters' => 'decimal:2',
        'received_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function dispatchOrder()
    {
        return $this->belongsTo(MilkDispatchOrder::class, 'dispatch_order_uuid', 'uuid');
    }

    public function factoryQaOfficer()
    {
        return $this->belongsTo(User::class, 'factory_qa_officer_user_id', 'id');
    }

}
