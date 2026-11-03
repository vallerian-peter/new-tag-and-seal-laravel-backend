<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An auctioneer. Registers from the app (or is created by the portal) and
 * conducts auctions only once verified and attached to a centre.
 */
class AuctionOfficer extends Model
{
    public const PENDING = 'pending';
    public const VERIFIED = 'verified';
    public const REJECTED = 'rejected';

    protected $fillable = [
        'uuid', 'userId', 'officerNo', 'firstName', 'middleName', 'lastName', 'phone', 'email', 'nationalId',
        'licenceNo', 'licenceDocumentPath', 'address', 'regionId', 'districtId', 'requestedCentreId', 'bio',
        'verificationStatus', 'verificationNotes', 'verifiedBy', 'verifiedAt', 'status', 'createdBy',
    ];

    protected $casts = [
        'userId' => 'integer',
        'regionId' => 'integer',
        'districtId' => 'integer',
        'requestedCentreId' => 'integer',
        'verifiedBy' => 'integer',
        'verifiedAt' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $officer): void {
            $officer->uuid ??= (string) Str::uuid();
            $officer->officerNo ??= self::nextOfficerNo();
        });
    }

    public static function nextOfficerNo(): string
    {
        do {
            $no = 'AO-'.strtoupper(Str::random(6));
        } while (self::where('officerNo', $no)->exists());

        return $no;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userId');
    }

    public function requestedCentre(): BelongsTo
    {
        return $this->belongsTo(AuctionCentre::class, 'requestedCentreId');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('verificationStatus', self::PENDING);
    }

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([$this->firstName, $this->middleName, $this->lastName])));
    }

    public function isVerified(): bool
    {
        return $this->verificationStatus === self::VERIFIED && $this->status === 'active';
    }

    /** Centre memberships for the linked account, with the centre loaded. */
    public function memberships()
    {
        if (! $this->userId) {
            return collect();
        }

        return AuctionCentreMember::with('centre')->where('userId', $this->userId)->get();
    }

    public function toWire(bool $private = false, bool $withMemberships = true): array
    {
        $data = [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'userId' => $this->userId,
            'officerNo' => $this->officerNo,
            'firstName' => $this->firstName,
            'middleName' => $this->middleName,
            'lastName' => $this->lastName,
            'fullName' => $this->fullName,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'regionId' => $this->regionId,
            'districtId' => $this->districtId,
            'requestedCentreId' => $this->requestedCentreId,
            'requestedCentre' => $this->requestedCentreId ? $this->requestedCentre?->summary() : null,
            'bio' => $this->bio,
            'verificationStatus' => $this->verificationStatus,
            'isVerified' => $this->isVerified(),
            'status' => $this->status,
            'verifiedAt' => $this->verifiedAt?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
        if ($private) {
            $data['nationalId'] = $this->nationalId;
            $data['licenceNo'] = $this->licenceNo;
            $data['hasLicenceDocument'] = (bool) $this->licenceDocumentPath;
            $data['verificationNotes'] = $this->verificationNotes;
            $data['verifiedBy'] = $this->verifiedBy;
        }
        if ($withMemberships) {
            $data['centres'] = $this->memberships()->map(function (AuctionCentreMember $m) {
                $row = $m->centre?->toWire($m) ?? ['uuid' => null, 'name' => null];
                $row['canHost'] = $m->canHost() && $this->isVerified();

                return $row;
            })->values()->all();
        }

        return $data;
    }
}
