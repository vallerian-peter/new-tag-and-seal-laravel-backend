<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MarketplaceListing extends Model
{
    protected $table = 'marketplace_listings';

    protected $fillable = [
        'uuid',
        'seller_user_id',
        'farm_uuid',
        'listing_title',
        'listing_type',
        'group_sale_type',
        'blueprint_group_uuid',
        'total_quantity',
        'available_quantity',
        'reserved_quantity',
        'sold_quantity',
        'total_asking_price',
        'unit_price',
        'bulk_threshold',
        'bulk_price',
        'min_order_quantity',
        'max_order_quantity',
        'is_negotiable',
        'minimum_acceptable_price',
        'currency',
        'status',
        'share_health_records',
        'approved_health_categories',
        'photos',
        'location_name',
        'description',
        'expires_at',
        'published_at',
        'withdrawn_at',
    ];

    protected $casts = [
        'is_negotiable' => 'boolean',
        'share_health_records' => 'boolean',
        'approved_health_categories' => 'array',
        'photos' => 'array',
        'total_asking_price' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'bulk_price' => 'decimal:2',
        'minimum_acceptable_price' => 'decimal:2',
        'total_quantity' => 'integer',
        'available_quantity' => 'integer',
        'reserved_quantity' => 'integer',
        'sold_quantity' => 'integer',
        'bulk_threshold' => 'integer',
        'min_order_quantity' => 'integer',
        'max_order_quantity' => 'integer',
        'expires_at' => 'datetime',
        'published_at' => 'datetime',
        'withdrawn_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class, 'farm_uuid', 'uuid');
    }

    public function items()
    {
        return $this->hasMany(MarketplaceListingItem::class, 'listing_uuid', 'uuid');
    }

    public function offers()
    {
        return $this->hasMany(MarketplaceOffer::class, 'listing_uuid', 'uuid');
    }
}
