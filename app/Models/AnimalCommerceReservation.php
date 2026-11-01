<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AnimalCommerceReservation extends Model
{
    protected $table = 'animal_commerce_reservations';

    protected $fillable = [
        'uuid',
        'livestock_uuid',
        'reservation_type',
        'reference_uuid',
        'status',
        'held_by_user_id',
        'expires_at',
        'released_at',
        'release_reason',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function livestock()
    {
        return $this->belongsTo(Livestock::class, 'livestock_uuid', 'uuid');
    }

    public function heldBy()
    {
        return $this->belongsTo(User::class, 'held_by_user_id');
    }
}

