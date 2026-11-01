<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MarketplaceListingItem extends Model
{
    protected $table = 'marketplace_listing_items';

    protected $fillable = [
        'uuid',
        'listing_uuid',
        'livestock_uuid',
        'allocated_asking_price',
        'is_sold',
    ];

    protected $casts = [
        'allocated_asking_price' => 'decimal:2',
        'is_sold' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function listing()
    {
        return $this->belongsTo(MarketplaceListing::class, 'listing_uuid', 'uuid');
    }

    public function livestock()
    {
        return $this->belongsTo(Livestock::class, 'livestock_uuid', 'uuid');
    }

}
