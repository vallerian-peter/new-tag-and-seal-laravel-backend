<?php

namespace App\Services\VetServices;

use App\Enums\VetRequestUrgency;
use App\Enums\VetServiceRequestStatus;
use App\Models\Vet;
use App\Models\VetServiceRequest;
use App\Models\VetServiceRequestEvent;
use App\Models\VetServiceRequestOffer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds nearby vets for a request and records offers for them.
 *
 * Round 0 targets a preferred vet (if the farmer picked one). Each later round
 * widens the radius until MAX_RADIUS_KM is reached.
 */
class VetDispatchService
{
    public const MAX_RADIUS_KM = 150;
    public const MAX_OFFERS_PER_ROUND = 8;

    public function __construct(private readonly VetNotifier $notifier)
    {
    }

    /**
     * Run the next dispatch round. Returns the number of new offers created.
     */
    public function dispatch(VetServiceRequest $request): int
    {
        if ($request->status !== VetServiceRequestStatus::PENDING) {
            return 0;
        }

        $round = $request->dispatchRound + 1;
        $alreadyOffered = VetServiceRequestOffer::where('requestId', $request->id)
            ->pluck('vetId')->all();

        $candidates = $this->candidates($request, $round, $alreadyOffered);

        $window = VetRequestUrgency::responseWindowMinutes($request->urgency);

        DB::transaction(function () use ($request, $candidates, $round, $window) {
            foreach ($candidates as $vet) {
                VetServiceRequestOffer::create([
                    'requestId' => $request->id,
                    'vetId' => $vet->id,
                    'distanceKm' => isset($vet->distanceKm) ? round((float) $vet->distanceKm, 2) : null,
                    'round' => $round,
                    'status' => VetServiceRequestOffer::OFFERED,
                    'notifiedAt' => now(),
                ]);
            }

            $request->dispatchRound = $round;
            $request->offersCount = $request->offersCount + $candidates->count();
            $request->expiresAt = now()->addMinutes($window);
            $request->touchActivity();
            $request->save();

            VetServiceRequestEvent::create([
                'requestId' => $request->id,
                'type' => 'dispatched',
                'payload' => [
                    'round' => $round,
                    'radiusKm' => $request->searchRadiusKm,
                    'offers' => $candidates->count(),
                    'vetIds' => $candidates->pluck('id')->values()->all(),
                ],
            ]);
        });

        if ($candidates->isNotEmpty()) {
            $this->notifier->newRequestOffered($request, $candidates);
        } elseif ($round === 1) {
            $this->notifier->noVetFound($request);
        }

        return $candidates->count();
    }

    /**
     * Widen the radius and run another round. Returns new offers created.
     */
    public function widen(VetServiceRequest $request): int
    {
        if ($request->searchRadiusKm >= self::MAX_RADIUS_KM) {
            return $this->dispatch($request);
        }
        $request->searchRadiusKm = min(self::MAX_RADIUS_KM, $request->searchRadiusKm * 2);
        $request->save();

        return $this->dispatch($request);
    }

    /**
     * Candidate vets for a round, nearest first.
     *
     * @param array<int, int> $excludeVetIds
     * @return Collection<int, Vet>
     */
    public function candidates(VetServiceRequest $request, int $round, array $excludeVetIds = []): Collection
    {
        // A preferred vet gets an exclusive first round.
        if ($round === 1 && $request->preferredVetId) {
            $preferred = Vet::dispatchable()
                ->where('id', $request->preferredVetId)
                ->whereNotIn('id', $excludeVetIds)
                ->withinKm($request->latitude, $request->longitude, self::MAX_RADIUS_KM)
                ->get();
            if ($preferred->isNotEmpty()) {
                return $preferred;
            }
        }

        return Vet::dispatchable()
            ->whereNotIn('vets.id', $excludeVetIds)
            ->withinKm($request->latitude, $request->longitude, (float) $request->searchRadiusKm)
            ->limit(self::MAX_OFFERS_PER_ROUND)
            ->get();
    }

    /**
     * Nearby verified vets for the farmer's "who is around" view. Includes vets
     * who are currently unavailable so the farmer can still call them.
     *
     * @return Collection<int, Vet>
     */
    public function nearby(float $lat, float $lng, float $radiusKm = 50, int $limit = 30): Collection
    {
        return Vet::verified()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->withinKm($lat, $lng, $radiusKm)
            ->orderByDesc('isAvailable')
            ->orderBy('distanceKm')
            ->limit($limit)
            ->get();
    }
}
