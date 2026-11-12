<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MilkCollectionCentre extends Model
{
    protected $table = 'milk_collection_centres';

    protected $fillable = [
        'uuid',
        'centre_code',
        'name',
        'region_id',
        'district_id',
        'latitude',
        'longitude',
        'is_active',
    ];

    protected $casts = [
        'latitude' => 'decimal:2',
        'longitude' => 'decimal:2',
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

    public function intakes()
    {
        return $this->hasMany(MilkIntake::class, 'mcc_uuid', 'uuid');
    }
}
