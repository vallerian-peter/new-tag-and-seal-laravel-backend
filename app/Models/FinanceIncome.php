<?php

namespace App\Models;

use App\Support\UuidHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceIncome extends Model
{
    protected $fillable = [
        'uuid',
        'sourceType',
        'sourceUuid',
        'farmUuid',
        'farmerId',
        'referenceNo',
        'subjectType',
        'quantity',
        'unitAmount',
        'totalAmount',
        'status',
        'notes',
        'incomeDate',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unitAmount' => 'decimal:2',
        'totalAmount' => 'decimal:2',
        'incomeDate' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $income): void {
            if (empty($income->uuid)) {
                $income->uuid = UuidHelper::generate();
            }
        });
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class, 'farmerId');
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class, 'farmUuid', 'uuid');
    }
}
