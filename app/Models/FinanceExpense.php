<?php

namespace App\Models;

use App\Support\UuidHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceExpense extends Model
{
    protected $fillable = [
        'uuid',
        'sourceType',
        'sourceUuid',
        'farmUuid',
        'farmerId',
        'billNo',
        'subjectType',
        'quantity',
        'unitCost',
        'totalCost',
        'status',
        'notes',
        'expenseDate',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unitCost' => 'decimal:2',
        'totalCost' => 'decimal:2',
        'expenseDate' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $expense): void {
            if (empty($expense->uuid)) {
                $expense->uuid = UuidHelper::generate();
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
