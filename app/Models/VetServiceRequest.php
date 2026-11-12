<?php

namespace App\Models;

use App\Enums\VetServiceRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VetServiceRequest extends Model
{
    protected $fillable = [
        'uuid', 'farmerUserId', 'farmUuid', 'livestockUuid', 'livestockSnapshot',
        'vetId', 'preferredVetId', 'problemCategory', 'description', 'urgency',
        'symptoms', 'photos', 'latitude', 'longitude', 'locationAddress', 'contactPhone',
        'status', 'searchRadiusKm', 'dispatchRound', 'offersCount',
        'acceptedAt', 'travellingAt', 'arrivedAt', 'startedAt', 'completedAt',
        'cancelledAt', 'expiresAt', 'cancelledByUserId', 'cancelReason',
        'treatmentUuid', 'treatmentSummary', 'farmerRating', 'farmerReview', 'ratedAt',
        'version', 'lastActivityAt',
    ];

    protected $casts = [
        'livestockSnapshot' => 'array',
        'symptoms' => 'array',
        'photos' => 'array',
        'treatmentSummary' => 'array',
        'latitude' => 'float',
        'longitude' => 'float',
        'acceptedAt' => 'datetime',
        'travellingAt' => 'datetime',
        'arrivedAt' => 'datetime',
        'startedAt' => 'datetime',
        'completedAt' => 'datetime',
        'cancelledAt' => 'datetime',
        'expiresAt' => 'datetime',
        'ratedAt' => 'datetime',
        'lastActivityAt' => 'datetime',
        'version' => 'integer',
        'searchRadiusKm' => 'integer',
        'dispatchRound' => 'integer',
        'offersCount' => 'integer',
        'farmerRating' => 'integer',
    ];

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'farmerUserId');
    }

    public function vet(): BelongsTo
    {
        return $this->belongsTo(Vet::class, 'vetId');
    }

    public function preferredVet(): BelongsTo
    {
        return $this->belongsTo(Vet::class, 'preferredVetId');
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class, 'farmUuid', 'uuid');
    }

    public function livestock(): BelongsTo
    {
        return $this->belongsTo(Livestock::class, 'livestockUuid', 'uuid');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(VetServiceRequestOffer::class, 'requestId');
    }

    public function events(): HasMany
    {
        return $this->hasMany(VetServiceRequestEvent::class, 'requestId')->orderBy('id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(VetServiceMessage::class, 'requestId')->orderBy('id');
    }

    public function rating(): HasOne
    {
        return $this->hasOne(VetRating::class, 'requestId');
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class, 'treatmentUuid', 'uuid');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', VetServiceRequestStatus::active());
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn(
            'status',
            array_merge([VetServiceRequestStatus::PENDING], VetServiceRequestStatus::active())
        );
    }

    public function isActive(): bool
    {
        return in_array($this->status, VetServiceRequestStatus::active(), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, VetServiceRequestStatus::terminal(), true);
    }

    public function touchActivity(): void
    {
        $this->lastActivityAt = now();
    }

    /**
     * Wire representation. `$viewerUserId` decides which side's contact details are
     * exposed and how unread counts are computed.
     */
    public function toWire(?int $viewerUserId = null, ?int $unreadCount = null): array
    {
        $vet = $this->relationLoaded('vet') ? $this->vet : null;
        $farmerProfile = $this->relationLoaded('farmer') ? $this->farmer?->farmerCard() : null;

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'status' => $this->status,
            'version' => $this->version,
            'farmerUserId' => $this->farmerUserId,
            'farmUuid' => $this->farmUuid,
            'livestockUuid' => $this->livestockUuid,
            'livestock' => $this->livestockSnapshot,
            'problemCategory' => $this->problemCategory,
            'description' => $this->description,
            'urgency' => $this->urgency,
            'symptoms' => $this->symptoms ?? [],
            'photos' => $this->photos ?? [],
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'locationAddress' => $this->locationAddress,
            'contactPhone' => $this->contactPhone,
            'searchRadiusKm' => $this->searchRadiusKm,
            'dispatchRound' => $this->dispatchRound,
            'offersCount' => $this->offersCount,
            'vet' => $vet?->toPublicCard(),
            'farmer' => $farmerProfile,
            'timeline' => [
                'createdAt' => $this->created_at?->toIso8601String(),
                'acceptedAt' => $this->acceptedAt?->toIso8601String(),
                'travellingAt' => $this->travellingAt?->toIso8601String(),
                'arrivedAt' => $this->arrivedAt?->toIso8601String(),
                'startedAt' => $this->startedAt?->toIso8601String(),
                'completedAt' => $this->completedAt?->toIso8601String(),
                'cancelledAt' => $this->cancelledAt?->toIso8601String(),
                'expiresAt' => $this->expiresAt?->toIso8601String(),
            ],
            'cancelReason' => $this->cancelReason,
            'cancelledByUserId' => $this->cancelledByUserId,
            'treatmentUuid' => $this->treatmentUuid,
            'treatmentSummary' => $this->treatmentSummary,
            'farmerRating' => $this->farmerRating,
            'farmerReview' => $this->farmerReview,
            'ratedAt' => $this->ratedAt?->toIso8601String(),
            'unreadCount' => $unreadCount ?? 0,
            'isMine' => $viewerUserId !== null && $viewerUserId === (int) $this->farmerUserId,
            'lastActivityAt' => ($this->lastActivityAt ?? $this->updated_at)?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
