<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A livestock market where authorised auctioneers run live sessions.
 */
class AuctionCentre extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const ROLE_MANAGER = 'manager';
    public const ROLE_AUCTIONEER = 'auctioneer';
    public const ROLE_CLERK = 'clerk';
    public const ROLES = [self::ROLE_MANAGER, self::ROLE_AUCTIONEER, self::ROLE_CLERK];
    /** Roles allowed to schedule and run a session at the centre. */
    public const HOST_ROLES = [self::ROLE_MANAGER, self::ROLE_AUCTIONEER];

    protected $fillable = [
        'uuid', 'code', 'name', 'description', 'regionId', 'districtId', 'wardId', 'address',
        'latitude', 'longitude', 'phone', 'status', 'createdBy',
    ];

    protected $casts = [
        'regionId' => 'integer',
        'districtId' => 'integer',
        'wardId' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $centre): void {
            $centre->uuid ??= (string) Str::uuid();
            $centre->code = strtoupper(trim($centre->code));
        });
        static::updating(function (self $centre): void {
            $centre->code = strtoupper(trim($centre->code));
        });
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'regionId');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'districtId');
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class, 'wardId');
    }

    public function members(): HasMany
    {
        return $this->hasMany(AuctionCentreMember::class, 'centreId');
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', AuctionCentreMember::STATUS_ACTIVE);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AuctionSession::class, 'centreId');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Compact form embedded in session payloads. */
    public function summary(): array
    {
        return [
            'uuid' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
            'address' => $this->address,
        ];
    }

    public function toWire(?AuctionCentreMember $membership = null, bool $withStats = false): array
    {
        $data = [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'address' => $this->address,
            'regionId' => $this->regionId,
            'regionName' => $this->regionId ? $this->region?->name : null,
            'districtId' => $this->districtId,
            'districtName' => $this->districtId ? $this->district?->name : null,
            'wardId' => $this->wardId,
            'wardName' => $this->wardId ? $this->ward?->name : null,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'phone' => $this->phone,
            'status' => $this->status,
            'isActive' => $this->isActive(),
            'myRole' => $membership?->role,
            'myMembershipStatus' => $membership?->status,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
        if ($withStats) {
            $data['membersCount'] = $this->activeMembers()->count();
            $data['sessionsCount'] = $this->sessions()->count();
            $data['liveCount'] = $this->sessions()->where('status', 'live')->count();
        }

        return $data;
    }
}
