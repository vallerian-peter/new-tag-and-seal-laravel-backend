<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user attached to an auction centre with a role (manager, auctioneer, clerk).
 */
class AuctionCentreMember extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = ['centreId', 'userId', 'role', 'status', 'assignedBy'];

    protected $casts = [
        'centreId' => 'integer',
        'userId' => 'integer',
        'assignedBy' => 'integer',
    ];

    public function centre(): BelongsTo
    {
        return $this->belongsTo(AuctionCentre::class, 'centreId');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userId');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function canHost(): bool
    {
        return $this->isActive() && in_array($this->role, AuctionCentre::HOST_ROLES, true);
    }

    public function toWire(): array
    {
        $user = $this->relationLoaded('user') ? $this->user : $this->user()->first();
        $card = $user?->farmerCard();

        return [
            'id' => $this->id,
            'centreId' => $this->centreId,
            'userId' => $this->userId,
            'username' => $user?->username,
            'email' => $user?->email,
            'name' => $card['name'] ?? $user?->username,
            'phone' => $card['phone'] ?? null,
            'userRole' => $user?->role,
            'role' => $this->role,
            'status' => $this->status,
            'assignedBy' => $this->assignedBy,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
