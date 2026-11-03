<?php

namespace App\Services\VetServices;

use App\Enums\UserRole;
use App\Enums\VetServiceRequestStatus as Status;
use App\Models\Farm;
use App\Models\FarmUser;
use App\Models\Livestock;
use App\Models\Treatment;
use App\Models\User;
use App\Models\Vet;
use App\Models\VetRating;
use App\Models\VetServiceMessage;
use App\Models\VetServiceRequest;
use App\Models\VetServiceRequestEvent;
use App\Models\VetServiceRequestOffer;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * State machine and business rules for on-demand vet requests.
 *
 * Every public mutation runs inside a transaction and bumps `version` so clients
 * can detect stale optimistic updates. Domain errors are thrown as
 * {@see VetServiceException} with an HTTP status the controller forwards.
 */
class VetServiceRequestService
{
    public function __construct(
        private readonly VetDispatchService $dispatcher,
        private readonly VetNotifier $notifier,
    ) {
    }

    // ------------------------------------------------------------------
    // Access helpers
    // ------------------------------------------------------------------

    public function vetForUser(User $user): ?Vet
    {
        return Vet::where('userId', $user->id)->first();
    }

    public function requireVet(User $user): Vet
    {
        $vet = $this->vetForUser($user);
        if (! $vet) {
            throw new VetServiceException('You do not have a veterinarian profile yet.', 403, 'NO_VET_PROFILE');
        }

        return $vet;
    }

    public function requireVerifiedVet(User $user): Vet
    {
        $vet = $this->requireVet($user);
        if (! $vet->isVerified) {
            throw new VetServiceException(
                'Your professional credentials are still being verified. You can accept requests once an administrator approves your profile.',
                403,
                'VET_NOT_VERIFIED'
            );
        }

        return $vet;
    }

    /** Farm uuids the user is allowed to raise requests for. */
    public function farmUuidsForRequester(User $user): array
    {
        if ($user->role === UserRole::FARMER) {
            return Farm::where('farmerId', $user->roleId)->pluck('uuid')->all();
        }
        if ($user->role === UserRole::FARM_INVITED_USER) {
            return FarmUser::find($user->roleId)?->getFarmUuidsArray() ?? [];
        }

        return [];
    }

    public function canView(User $user, VetServiceRequest $request): bool
    {
        if ((int) $request->farmerUserId === (int) $user->id) {
            return true;
        }
        if ($user->role === UserRole::SYSTEM_USER) {
            return true;
        }
        $vet = $this->vetForUser($user);
        if (! $vet) {
            return false;
        }
        if ((int) $request->vetId === (int) $vet->id) {
            return true;
        }

        // Vets may read the details of requests offered to them while still pending.
        return $request->status === Status::PENDING
            && VetServiceRequestOffer::where('requestId', $request->id)
                ->where('vetId', $vet->id)->exists();
    }

    /**
     * Farms a vet is currently working on (for sync access). Completed jobs stay
     * visible for 30 days so the vet can still review the animal's record.
     */
    public static function farmUuidsForVetUser(User $user): array
    {
        $vet = Vet::where('userId', $user->id)->first();
        if (! $vet) {
            return [];
        }

        return VetServiceRequest::where('vetId', $vet->id)
            ->where(function ($q) {
                $q->whereIn('status', Status::active())
                    ->orWhere(function ($q2) {
                        $q2->where('status', Status::COMPLETED)
                            ->where('completedAt', '>=', now()->subDays(30));
                    });
            })
            ->distinct()
            ->pluck('farmUuid')
            ->all();
    }

    // ------------------------------------------------------------------
    // Farmer actions
    // ------------------------------------------------------------------

    public function create(User $farmer, array $data): VetServiceRequest
    {
        $allowedFarms = $this->farmUuidsForRequester($farmer);
        if (! in_array($data['farmUuid'], $allowedFarms, true)) {
            throw new VetServiceException('You can only request a vet for your own farms.', 403, 'FARM_NOT_ALLOWED');
        }

        // Idempotent create: the app may retry with the same uuid after a timeout.
        if (! empty($data['uuid'])) {
            $existing = VetServiceRequest::where('uuid', $data['uuid'])->first();
            if ($existing) {
                return $existing;
            }
        }

        $openCount = VetServiceRequest::where('farmerUserId', $farmer->id)->open()->count();
        if ($openCount >= 5) {
            throw new VetServiceException('You already have 5 open requests. Complete or cancel one first.', 422, 'TOO_MANY_OPEN');
        }

        $snapshot = $this->livestockSnapshot($data['livestockUuid'] ?? null, $data['farmUuid'], $data['livestock'] ?? null);

        $request = DB::transaction(function () use ($farmer, $data, $snapshot) {
            $request = VetServiceRequest::create([
                'uuid' => $data['uuid'] ?? (string) Str::uuid(),
                'farmerUserId' => $farmer->id,
                'farmUuid' => $data['farmUuid'],
                'livestockUuid' => $data['livestockUuid'] ?? null,
                'livestockSnapshot' => $snapshot,
                'preferredVetId' => $data['preferredVetId'] ?? null,
                'problemCategory' => $data['problemCategory'],
                'description' => $data['description'],
                'urgency' => $data['urgency'] ?? 'normal',
                'symptoms' => $data['symptoms'] ?? null,
                'photos' => $data['photos'] ?? null,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'locationAddress' => $data['locationAddress'] ?? null,
                'contactPhone' => $data['contactPhone'] ?? null,
                'status' => Status::PENDING,
                'searchRadiusKm' => $data['searchRadiusKm'] ?? 30,
                'version' => 1,
                'lastActivityAt' => now(),
            ]);

            $this->event($request, 'created', $farmer->id, null, Status::PENDING, [
                'urgency' => $request->urgency,
                'problemCategory' => $request->problemCategory,
            ]);

            return $request;
        });

        $this->dispatcher->dispatch($request);

        return $request->refresh();
    }

    public function retryDispatch(User $farmer, VetServiceRequest $request): int
    {
        $this->assertOwner($farmer, $request);
        if ($request->status !== Status::PENDING) {
            throw new VetServiceException('Only pending requests can be re-dispatched.', 409, 'NOT_PENDING');
        }

        return $this->dispatcher->widen($request);
    }

    public function cancelByFarmer(User $farmer, VetServiceRequest $request, ?string $reason): VetServiceRequest
    {
        $this->assertOwner($farmer, $request);
        if (! in_array($request->status, Status::cancellable(), true)) {
            throw new VetServiceException('This request can no longer be cancelled.', 409, 'NOT_CANCELLABLE');
        }

        $hadVet = $request->vetId !== null;

        DB::transaction(function () use ($farmer, $request, $reason) {
            $from = $request->status;
            $request->status = Status::CANCELLED;
            $request->cancelledAt = now();
            $request->cancelledByUserId = $farmer->id;
            $request->cancelReason = $reason;
            $this->bump($request);

            VetServiceRequestOffer::where('requestId', $request->id)
                ->where('status', VetServiceRequestOffer::OFFERED)
                ->update(['status' => VetServiceRequestOffer::WITHDRAWN, 'respondedAt' => now()]);

            $this->event($request, 'cancelled', $farmer->id, $from, Status::CANCELLED, ['reason' => $reason]);
        });

        if ($hadVet) {
            $this->notifier->requestCancelledByFarmer($request->load('vet'));
        }

        return $request;
    }

    public function rate(User $farmer, VetServiceRequest $request, int $rating, ?string $review, array $tags = []): VetServiceRequest
    {
        $this->assertOwner($farmer, $request);
        if ($request->status !== Status::COMPLETED) {
            throw new VetServiceException('You can rate a service once it is completed.', 409, 'NOT_COMPLETED');
        }
        if ($request->ratedAt) {
            throw new VetServiceException('This service has already been rated.', 409, 'ALREADY_RATED');
        }
        if (! $request->vetId) {
            throw new VetServiceException('No veterinarian is attached to this request.', 409, 'NO_VET');
        }

        DB::transaction(function () use ($farmer, $request, $rating, $review, $tags) {
            VetRating::create([
                'requestId' => $request->id,
                'vetId' => $request->vetId,
                'farmerUserId' => $farmer->id,
                'rating' => $rating,
                'review' => $review,
                'tags' => $tags ?: null,
            ]);

            $request->farmerRating = $rating;
            $request->farmerReview = $review;
            $request->ratedAt = now();
            $this->bump($request);

            // Running average without re-reading every rating row.
            Vet::where('id', $request->vetId)->update([
                'ratingAverage' => DB::raw("ROUND(((ratingAverage * ratingCount) + {$rating}) / (ratingCount + 1), 2)"),
                'ratingCount' => DB::raw('ratingCount + 1'),
            ]);

            $this->event($request, 'rated', $farmer->id, null, null, ['rating' => $rating, 'tags' => $tags]);
        });

        return $request;
    }

    // ------------------------------------------------------------------
    // Vet actions
    // ------------------------------------------------------------------

    public function accept(User $user, VetServiceRequest $request): VetServiceRequest
    {
        $vet = $this->requireVerifiedVet($user);

        $activeJobs = VetServiceRequest::where('vetId', $vet->id)->active()->count();
        if ($activeJobs >= 3) {
            throw new VetServiceException('You already have 3 active jobs. Complete one before accepting another.', 409, 'TOO_MANY_ACTIVE');
        }

        $won = DB::transaction(function () use ($vet, $request, $user) {
            // Atomic claim: only one vet can flip pending -> accepted.
            $updated = VetServiceRequest::where('id', $request->id)
                ->where('status', Status::PENDING)
                ->update([
                    'status' => Status::ACCEPTED,
                    'vetId' => $vet->id,
                    'acceptedAt' => now(),
                    'lastActivityAt' => now(),
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                return false;
            }

            VetServiceRequestOffer::updateOrCreate(
                ['requestId' => $request->id, 'vetId' => $vet->id],
                ['status' => VetServiceRequestOffer::ACCEPTED, 'respondedAt' => now(), 'round' => max(1, $request->dispatchRound)]
            );
            VetServiceRequestOffer::where('requestId', $request->id)
                ->where('vetId', '!=', $vet->id)
                ->where('status', VetServiceRequestOffer::OFFERED)
                ->update(['status' => VetServiceRequestOffer::EXPIRED, 'respondedAt' => now()]);

            $this->event($request, 'accepted', $user->id, Status::PENDING, Status::ACCEPTED, ['vetId' => $vet->id]);

            return true;
        });

        if (! $won) {
            $request->refresh();
            $taken = $request->status !== Status::PENDING;
            throw new VetServiceException(
                $taken ? 'Another veterinarian already took this request.' : 'This request is no longer available.',
                409,
                'ALREADY_TAKEN'
            );
        }

        $request->refresh()->load('vet');
        $this->notifier->requestAccepted($request, $vet);

        return $request;
    }

    public function decline(User $user, VetServiceRequest $request, ?string $reason): void
    {
        $vet = $this->requireVet($user);

        $offer = VetServiceRequestOffer::where('requestId', $request->id)->where('vetId', $vet->id)->first();
        if ($offer && $offer->status === VetServiceRequestOffer::OFFERED) {
            $offer->update([
                'status' => VetServiceRequestOffer::DECLINED,
                'declineReason' => $reason,
                'respondedAt' => now(),
            ]);
        } elseif (! $offer) {
            VetServiceRequestOffer::create([
                'requestId' => $request->id,
                'vetId' => $vet->id,
                'round' => max(1, $request->dispatchRound),
                'status' => VetServiceRequestOffer::DECLINED,
                'declineReason' => $reason,
                'respondedAt' => now(),
            ]);
        }

        $this->event($request, 'declined', $user->id, null, null, ['vetId' => $vet->id, 'reason' => $reason]);

        // Everyone in this round said no: widen immediately instead of waiting for expiry.
        $stillOpen = VetServiceRequestOffer::where('requestId', $request->id)
            ->where('status', VetServiceRequestOffer::OFFERED)->exists();
        if (! $stillOpen && $request->status === Status::PENDING) {
            $this->dispatcher->widen($request);
        }
    }

    /** Vet backs out of an accepted job before treatment starts; request goes back to the pool. */
    public function withdraw(User $user, VetServiceRequest $request, ?string $reason): VetServiceRequest
    {
        $vet = $this->requireVet($user);
        $this->assertAssignedVet($vet, $request);
        if (! in_array($request->status, [Status::ACCEPTED, Status::TRAVELLING], true)) {
            throw new VetServiceException('You can only withdraw before arriving at the farm.', 409, 'CANNOT_WITHDRAW');
        }

        DB::transaction(function () use ($vet, $request, $user, $reason) {
            $from = $request->status;
            $request->status = Status::PENDING;
            $request->vetId = null;
            $request->acceptedAt = null;
            $request->travellingAt = null;
            $this->bump($request);

            VetServiceRequestOffer::where('requestId', $request->id)->where('vetId', $vet->id)
                ->update(['status' => VetServiceRequestOffer::WITHDRAWN, 'declineReason' => $reason, 'respondedAt' => now()]);

            $this->event($request, 'withdrawn', $user->id, $from, Status::PENDING, ['vetId' => $vet->id, 'reason' => $reason]);
        });

        $this->dispatcher->dispatch($request);

        return $request->refresh();
    }

    public function advance(User $user, VetServiceRequest $request, string $toStatus, ?int $expectedVersion = null): VetServiceRequest
    {
        $vet = $this->requireVet($user);
        $this->assertAssignedVet($vet, $request);

        if ($expectedVersion !== null && (int) $request->version !== $expectedVersion) {
            throw new VetServiceException('The request changed on another device. Refresh and try again.', 409, 'STALE_VERSION');
        }
        if (! Status::canTransition($request->status, $toStatus)) {
            throw new VetServiceException(
                "Cannot move a request from '{$request->status}' to '{$toStatus}'.",
                409,
                'INVALID_TRANSITION'
            );
        }

        DB::transaction(function () use ($request, $toStatus, $user, $vet) {
            $from = $request->status;
            $request->status = $toStatus;
            match ($toStatus) {
                Status::TRAVELLING => $request->travellingAt = now(),
                Status::ARRIVED => $request->arrivedAt = now(),
                Status::IN_PROGRESS => $request->startedAt = now(),
                Status::COMPLETED => $request->completedAt = now(),
                default => null,
            };
            $this->bump($request);

            if ($toStatus === Status::COMPLETED) {
                Vet::where('id', $vet->id)->update(['completedJobsCount' => DB::raw('completedJobsCount + 1')]);
            }

            $this->event($request, 'status_changed', $user->id, $from, $toStatus);
        });

        $this->notifier->statusChanged($request->load('vet'), $toStatus);

        return $request;
    }

    /**
     * Writes a real `treatments` row linked to the request so the farmer's app
     * receives it through normal log sync, and keeps a summary on the request.
     */
    public function recordTreatment(User $user, VetServiceRequest $request, array $data): VetServiceRequest
    {
        $vet = $this->requireVet($user);
        $this->assertAssignedVet($vet, $request);
        if (! in_array($request->status, [Status::ARRIVED, Status::IN_PROGRESS, Status::COMPLETED], true)) {
            throw new VetServiceException('Treatment can be recorded once you have arrived at the farm.', 409, 'NOT_ON_SITE');
        }
        if (! $request->livestockUuid) {
            throw new VetServiceException('This request is not attached to a specific animal.', 422, 'NO_ANIMAL');
        }

        DB::transaction(function () use ($request, $data, $vet, $user) {
            $now = now();
            $uuid = $request->treatmentUuid ?: ($data['uuid'] ?? (string) Str::uuid());

            Treatment::updateOrCreate(
                ['uuid' => $uuid],
                [
                    'farmUuid' => $request->farmUuid,
                    'livestockUuid' => $request->livestockUuid,
                    'diseaseId' => $data['diseaseId'] ?? null,
                    'medicineId' => $data['medicineId'] ?? null,
                    'quantity' => $data['quantity'] ?? null,
                    'withdrawalPeriod' => $data['withdrawalPeriod'] ?? null,
                    'medicationDate' => $data['medicationDate'] ?? $now->toDateString(),
                    'nextMedicationDate' => $data['nextMedicationDate'] ?? null,
                    'remarks' => $data['remarks'] ?? null,
                    'eventDate' => $data['medicationDate'] ?? $now->toDateString(),
                    'vetId' => $vet->id,
                    'serviceRequestUuid' => $request->uuid,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $request->treatmentUuid = $uuid;
            $request->treatmentSummary = [
                'diagnosis' => $data['diagnosis'] ?? null,
                'diseaseId' => $data['diseaseId'] ?? null,
                'diseaseName' => $data['diseaseName'] ?? null,
                'medicineId' => $data['medicineId'] ?? null,
                'medicineName' => $data['medicineName'] ?? null,
                'quantity' => $data['quantity'] ?? null,
                'withdrawalPeriod' => $data['withdrawalPeriod'] ?? null,
                'medicationDate' => $data['medicationDate'] ?? $now->toDateString(),
                'nextMedicationDate' => $data['nextMedicationDate'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'recordedAt' => $now->toIso8601String(),
                'vetName' => $vet->fullName,
            ];
            $this->bump($request);

            $this->event($request, 'treatment_recorded', $user->id, null, null, ['treatmentUuid' => $uuid]);
        });

        return $request;
    }

    // ------------------------------------------------------------------
    // Messaging
    // ------------------------------------------------------------------

    public function sendMessage(User $sender, VetServiceRequest $request, string $body, ?string $uuid = null): VetServiceMessage
    {
        if (! $this->canView($sender, $request)) {
            throw new VetServiceException('You are not part of this request.', 403, 'FORBIDDEN');
        }
        if ($request->vetId === null && (int) $request->farmerUserId !== (int) $sender->id) {
            throw new VetServiceException('Messaging opens once a veterinarian accepts the request.', 409, 'NOT_ACCEPTED');
        }
        if ($request->isTerminal() && $request->completedAt && $request->completedAt->lt(now()->subDays(7))) {
            throw new VetServiceException('This conversation is closed.', 409, 'CLOSED');
        }

        if ($uuid) {
            $existing = VetServiceMessage::where('uuid', $uuid)->first();
            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($sender, $request, $body, $uuid) {
            $message = VetServiceMessage::create([
                'uuid' => $uuid ?: (string) Str::uuid(),
                'requestId' => $request->id,
                'senderUserId' => $sender->id,
                'body' => $body,
            ]);
            $request->touchActivity();
            $request->save();

            return $message;
        });
    }

    public function unreadCountFor(User $viewer, VetServiceRequest $request): int
    {
        return VetServiceMessage::where('requestId', $request->id)
            ->where('senderUserId', '!=', $viewer->id)
            ->whereNull('readAt')
            ->count();
    }

    public function markRead(User $viewer, VetServiceRequest $request, ?int $uptoId = null): int
    {
        $q = VetServiceMessage::where('requestId', $request->id)
            ->where('senderUserId', '!=', $viewer->id)
            ->whereNull('readAt');
        if ($uptoId) {
            $q->where('id', '<=', $uptoId);
        }

        return $q->update(['readAt' => now()]);
    }

    // ------------------------------------------------------------------
    // Housekeeping (run opportunistically; there is no scheduler)
    // ------------------------------------------------------------------

    /** Pending requests whose response window lapsed get another round, or expire. */
    public function sweepPending(?int $farmerUserId = null): void
    {
        $q = VetServiceRequest::where('status', Status::PENDING)
            ->whereNotNull('expiresAt')
            ->where('expiresAt', '<', now())
            ->limit(20);
        if ($farmerUserId) {
            $q->where('farmerUserId', $farmerUserId);
        }

        foreach ($q->get() as $request) {
            if ($request->searchRadiusKm >= VetDispatchService::MAX_RADIUS_KM && $request->dispatchRound >= 3) {
                $this->expire($request);
                continue;
            }
            $created = $this->dispatcher->widen($request);
            if ($created === 0 && $request->searchRadiusKm >= VetDispatchService::MAX_RADIUS_KM) {
                $this->expire($request);
            }
        }
    }

    private function expire(VetServiceRequest $request): void
    {
        DB::transaction(function () use ($request) {
            $from = $request->status;
            $request->status = Status::EXPIRED;
            $this->bump($request);
            VetServiceRequestOffer::where('requestId', $request->id)
                ->where('status', VetServiceRequestOffer::OFFERED)
                ->update(['status' => VetServiceRequestOffer::EXPIRED, 'respondedAt' => now()]);
            $this->event($request, 'expired', null, $from, Status::EXPIRED);
        });
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function livestockSnapshot(?string $livestockUuid, string $farmUuid, ?array $clientSnapshot): ?array
    {
        if (! $livestockUuid) {
            return $clientSnapshot;
        }
        $animal = Livestock::where('uuid', $livestockUuid)->where('farmUuid', $farmUuid)->first();
        if (! $animal) {
            // The animal may exist only on the phone (not yet synced); trust the client copy.
            return $clientSnapshot ? array_merge(['uuid' => $livestockUuid], $clientSnapshot) : ['uuid' => $livestockUuid];
        }

        return [
            'uuid' => $animal->uuid,
            'name' => $animal->name,
            'identificationNumber' => $animal->identificationNumber,
            'gender' => $animal->gender,
            'dateOfBirth' => optional($animal->dateOfBirth)->toDateString(),
            'speciesId' => $animal->speciesId,
            'breedId' => $animal->breedId,
            'livestockTypeId' => $animal->livestockTypeId,
            'speciesName' => $clientSnapshot['speciesName'] ?? null,
            'breedName' => $clientSnapshot['breedName'] ?? null,
            'primaryColor' => $animal->primaryColor,
        ];
    }

    private function assertOwner(User $user, VetServiceRequest $request): void
    {
        if ((int) $request->farmerUserId !== (int) $user->id) {
            throw new VetServiceException('This request belongs to another farmer.', 403, 'FORBIDDEN');
        }
    }

    private function assertAssignedVet(Vet $vet, VetServiceRequest $request): void
    {
        if ((int) $request->vetId !== (int) $vet->id) {
            throw new VetServiceException('This request is not assigned to you.', 403, 'NOT_ASSIGNED');
        }
    }

    private function bump(VetServiceRequest $request): void
    {
        $request->version = (int) $request->version + 1;
        $request->touchActivity();
        $request->save();
    }

    private function event(VetServiceRequest $request, string $type, ?int $actorUserId, ?string $from, ?string $to, ?array $payload = null): void
    {
        VetServiceRequestEvent::create([
            'requestId' => $request->id,
            'type' => $type,
            'actorUserId' => $actorUserId,
            'fromStatus' => $from,
            'toStatus' => $to,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
