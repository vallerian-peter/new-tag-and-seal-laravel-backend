<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stage extends Model
{
    protected $fillable = [
        'name',
        'livestockTypeId',
        'is_tag_applicable',
    ];

    protected $casts = [
        'is_tag_applicable' => 'boolean',
    ];

    public function livestockType(): BelongsTo
    {
        return $this->belongsTo(LivestockType::class, 'livestockTypeId');
    }

    public function livestock()
    {
        return $this->hasMany(Livestock::class, 'stageId');
    }

    public function isTagApplicable(): bool
    {
        if ($this->is_tag_applicable !== null) {
            return (bool) $this->is_tag_applicable;
        }

        $youngNames = [
            'calf', 'piglet', 'chick', 'lamb', 'kid', 'foal', 'puppy', 'kitten',
            'weaner', 'suckling', 'neonate', 'juvenile', 'ndama', 'kifaranga', 'kitoto',
        ];
        return !in_array(strtolower(trim($this->name)), $youngNames, true);
    }
}
