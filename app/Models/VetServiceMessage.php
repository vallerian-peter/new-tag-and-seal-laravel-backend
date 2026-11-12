<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VetServiceMessage extends Model
{
    protected $fillable = [
        'uuid', 'requestId', 'senderUserId', 'body', 'attachmentPath', 'readAt',
    ];

    protected $casts = [
        'readAt' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(VetServiceRequest::class, 'requestId');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'senderUserId');
    }

    public function toWire(int $viewerUserId): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'requestId' => $this->requestId,
            'senderUserId' => $this->senderUserId,
            'sentByMe' => (int) $this->senderUserId === $viewerUserId,
            'body' => $this->body,
            'attachmentUrl' => $this->attachmentPath ? url('storage/'.$this->attachmentPath) : null,
            'readAt' => $this->readAt?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
