<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bill extends Model
{

    protected $fillable = [
        'uuid',
        'billNo',
        'farmUuid',
        'extensionOfficerId',
        'farmerId',
        'subjectType',
        'subjectUuid',
        'quantity',
        'amount',
        'status',
        'notes',
        'created_at',
        'updated_at',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Bill $bill) {
            if (empty($bill->billNo)) {
                $bill->billNo = self::generateUniqueBillNo();
            }
            // Don't strip hyphens from billNo - keep the format as-is from Flutter
        });
    }

    private static function generateUniqueBillNo(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $len = strlen($chars);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = '';
            for ($i = 0; $i < 7; $i++) {
                $code .= $chars[random_int(0, $len - 1)];
            }
            if (! self::where('billNo', $code)->exists()) {
                return $code;
            }
        }
        return substr(strtoupper(bin2hex(random_bytes(8))), 0, 7);
    }

    public function extensionOfficer(): BelongsTo
    {
        return $this->belongsTo(ExtensionOfficer::class, 'extensionOfficerId');
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class, 'farmerId');
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class, 'farmUuid', 'uuid');
    }

    // Accept billNo as-is (preserves BILL-YYYYMMDDHHMMSS-XXX format from Flutter)
    public function setBillNoAttribute($value): void
    {
        $this->attributes['billNo'] = (string) $value;
    }
}
