<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PurchaseOrderItem extends Model
{
    protected $table = 'purchase_order_items';

    protected $fillable = [
        'uuid',
        'order_uuid',
        'livestock_uuid',
        'agreed_price',
        'scanned_tag_at_handover',
        'handover_status',
        'event_record_uuid',
    ];

    protected $casts = [
        'agreed_price' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function order()
    {
        return $this->belongsTo(PurchaseOrder::class, 'order_uuid', 'uuid');
    }

    public function livestock()
    {
        return $this->belongsTo(Livestock::class, 'livestock_uuid', 'uuid');
    }

}
