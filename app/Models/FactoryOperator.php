<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class FactoryOperator extends Model
{
    protected $table = 'factory_operators';

    protected $fillable = [
        'uuid',
        'factory_uuid',
        'user_id',
        'staff_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

}
