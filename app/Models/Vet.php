<?php

namespace App\Models;

use App\Enums\VetVerificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vet extends Model
{
    protected $fillable = [
        'uuid',
        'userId',
        'referenceNo',
        'medicalLicenseNo',
        'licenseAuthority',
        'licenseExpiryDate',
        'licenseDocumentPath',
        'specializations',
        'yearsOfExperience',
        'clinicName',
        'bio',
        'fullName',
        'phoneNumber',
        'email',
        'address',
        'countryId',
        'regionId',
        'districtId',
        'gender',
        'dateOfBirth',
        'identityCardTypeId',
        'schoolLevelId',
        'identityNo',
        'serviceAreaDistrictIds',
        'serviceRadiusKm',
        'latitude',
        'longitude',
        'locationUpdatedAt',
        'isAvailable',
        'verificationStatus',
        'verifiedAt',
        'verifiedBy',
        'verificationNotes',
        'ratingAverage',
        'ratingCount',
        'completedJobsCount',
        'status',
    ];

    protected $casts = [
        'specializations' => 'array',
        'serviceAreaDistrictIds' => 'array',
        'licenseExpiryDate' => 'date:Y-m-d',
        'dateOfBirth' => 'date:Y-m-d',
        'latitude' => 'float',
        'longitude' => 'float',
        'locationUpdatedAt' => 'datetime',
        'isAvailable' => 'boolean',
        'verifiedAt' => 'datetime',
        'ratingAverage' => 'float',
        'ratingCount' => 'integer',
        'completedJobsCount' => 'integer',
        'serviceRadiusKm' => 'integer',
        'yearsOfExperience' => 'integer',
    ];

    protected $hidden = ['licenseDocumentPath'];

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userId');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verifiedBy');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'countryId');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'regionId');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'districtId');
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(VetServiceRequest::class, 'vetId');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(VetServiceRequestOffer::class, 'vetId');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(VetRating::class, 'vetId');
    }

    // ------------------------------------------------------------------
    // Compatibility accessors: login/profile code reads firstName / lastName / phone.
    // ------------------------------------------------------------------

    public function getFirstNameAttribute(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->fullName)) ?: [];

        return $parts[0] ?? '';
    }

    public function getLastNameAttribute(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->fullName)) ?: [];
        array_shift($parts);

        return implode(' ', $parts);
    }

    public function getPhoneAttribute(): ?string
    {
        return $this->phoneNumber;
    }

    public function getIsVerifiedAttribute(): bool
    {
        return $this->verificationStatus === VetVerificationStatus::VERIFIED;
    }

    public function getHasLocationAttribute(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verificationStatus', VetVerificationStatus::VERIFIED)
            ->where('status', 'active');
    }

    public function scopeDispatchable(Builder $query): Builder
    {
        return $query->verified()
            ->where('isAvailable', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');
    }

    /**
     * Haversine distance (km) from the given point, selected as `distanceKm`, with a
     * bounding-box prefilter so the position index is used before the trig runs.
     */
    public function scopeWithinKm(Builder $query, float $lat, float $lng, float $radiusKm): Builder
    {
        $latDelta = $radiusKm / 111.045;
        $lngDelta = $radiusKm / (111.045 * max(cos(deg2rad($lat)), 0.01));

        $haversine = '(6371 * acos(least(1, cos(radians(?)) * cos(radians(latitude)) '
            .'* cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))))';

        return $query
            ->whereBetween('latitude', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('longitude', [$lng - $lngDelta, $lng + $lngDelta])
            ->select('vets.*')
            ->selectRaw("$haversine as distanceKm", [$lat, $lng, $lat])
            ->havingRaw('distanceKm <= ?', [$radiusKm])
            ->orderBy('distanceKm');
    }

    /** Public, farmer-facing card. Never leaks licence documents or identity numbers. */
    public function toPublicCard(?float $distanceKm = null): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'fullName' => $this->fullName,
            'clinicName' => $this->clinicName,
            'phoneNumber' => $this->phoneNumber,
            'specializations' => $this->specializations ?? [],
            'yearsOfExperience' => $this->yearsOfExperience,
            'bio' => $this->bio,
            'ratingAverage' => (float) $this->ratingAverage,
            'ratingCount' => (int) $this->ratingCount,
            'completedJobsCount' => (int) $this->completedJobsCount,
            'isAvailable' => (bool) $this->isAvailable,
            'isVerified' => $this->isVerified,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'locationUpdatedAt' => optional($this->locationUpdatedAt)->toIso8601String(),
            'distanceKm' => $distanceKm ?? (isset($this->distanceKm) ? round((float) $this->distanceKm, 1) : null),
        ];
    }
}
