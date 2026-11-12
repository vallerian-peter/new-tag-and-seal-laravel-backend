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
        'listing_uuid',
        'livestock_uuid',
        'blueprint_group_uuid',
        'quantity',
        'unit_price',
        'agreed_price',
        'is_bulk_applied',
        'scanned_tag_at_handover',
        'handover_status',
        'event_record_uuid',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'agreed_price' => 'decimal:2',
        'is_bulk_applied' => 'boolean',
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

    public function listing()
    {
        return $this->belongsTo(MarketplaceListing::class, 'listing_uuid', 'uuid');
    }
}
