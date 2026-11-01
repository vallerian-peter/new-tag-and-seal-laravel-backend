<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MilkBatch extends Model
{
    protected $table = 'milk_batches';

    protected $fillable = [
        'uuid',
        'batch_code',
        'mcc_uuid',
        'session_date',
        'delivery_session',
        'status',
    ];

    protected $casts = [
        'session_date' => 'date',
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

    public function intakes()
    {
        return $this->hasMany(MilkIntake::class, 'batch_uuid', 'uuid');
    }

}
