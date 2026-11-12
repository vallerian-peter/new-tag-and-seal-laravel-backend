<?php

namespace App\Services\Auction;

use App\Enums\UserRole;
use App\Models\AuctionCentre;
use App\Models\AuctionCentreMember;
use App\Models\Farm;
use App\Models\Farmer;
use App\Models\Livestock;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Membership and consignment rules for auction centres.
 *
 * A centre session is run by an attached auctioneer, and the animals offered
 * are whatever tagged livestock farmers bring to the market that day. The
 * clerk looks each animal up by its tag, and the lot remembers the farmer as
 * the seller so the money and the SMS go to the right person.
 */
class AuctionCentreService
{
    public function __construct(
        private readonly SaleHoldService $holds,
        private readonly AuctionOfficerService $officers,
    ) {
    }

    /** Memberships at active centres, with the centre loaded. */
    public function membershipsFor(User $user, bool $includePending = false): Collection
    {
        $statuses = $includePending
            ? [AuctionCentreMember::STATUS_ACTIVE, AuctionCentreMember::STATUS_PENDING]
            : [AuctionCentreMember::STATUS_ACTIVE];

        return AuctionCentreMember::with('centre')
            ->where('userId', $user->id)
            ->whereIn('status', $statuses)
            ->get()
            ->filter(fn (AuctionCentreMember $m) => $m->centre?->isActive())
            ->values();
    }

    public function membershipFor(User $user, AuctionCentre $centre): ?AuctionCentreMember
    {
        return AuctionCentreMember::where('centreId', $centre->id)->where('userId', $user->id)->first();
    }

    /** Any active member or a system user may look animals up at the centre. */
    public function canAccess(User $user, AuctionCentre $centre): bool
    {
        if ($user->role === UserRole::SYSTEM_USER) {
            return true;
        }

        return $this->membershipFor($user, $centre)?->isActive() ?? false;
    }

    /** Managers and auctioneers may schedule and run sessions at the centre. */
    public function canHostAt(User $user, AuctionCentre $centre): bool
    {
        if (! $centre->isActive()) {
            return false;
        }
        if ($user->role === UserRole::SYSTEM_USER) {
            return true;
        }
        if (! $this->officers->clearedToConduct($user)) {
            return false; // officer profile exists but is not verified yet
        }

        return $this->membershipFor($user, $centre)?->canHost() ?? false;
    }

    public function hasHostingCentre(User $user): bool
    {
        return $this->officers->clearedToConduct($user)
            && $this->membershipsFor($user)->contains(fn (AuctionCentreMember $m) => $m->canHost());
    }

    /**
     * Find tagged animals by tag number or name, across every farm. Returns
     * wire arrays with the owner and the current sale-hold state so the app
     * can show who is consigning and grey out animals already for sale.
     */
    public function searchLivestock(?AuctionCentre $centre, string $query, int $limit = 30): array
    {
        $q = trim($query);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';

        $animals = Livestock::with(['farm', 'species', 'breed'])
            ->where('status', 'active')
            ->where(function ($w) use ($like) {
                $w->where('identificationNumber', 'like', $like)
                    ->orWhere('rfidTagId', 'like', $like)
                    ->orWhere('barcodeTagId', 'like', $like)
                    ->orWhere('name', 'like', $like);
            })
            ->orderByRaw(
                'CASE WHEN identificationNumber = ? THEN 0 WHEN identificationNumber LIKE ? THEN 1 ELSE 2 END',
                [$q, $q.'%']
            )
            ->orderBy('identificationNumber')
            ->limit(max(1, min($limit, 50)))
            ->get();

        $holds = $this->holds->activeForMany($animals->pluck('uuid')->all());
        $owners = [];

        return $animals->map(function (Livestock $a) use ($holds, &$owners) {
            $owners[$a->farmUuid] ??= $this->ownerCard($a->farm);
            $hold = $holds[$a->uuid] ?? null;

            return [
                'uuid' => $a->uuid,
                'name' => $a->name,
                'identificationNumber' => $a->identificationNumber,
                'rfidTagId' => $a->rfidTagId,
                'gender' => $a->gender,
                'dateOfBirth' => optional($a->dateOfBirth)->toDateString(),
                'speciesId' => $a->speciesId,
                'speciesName' => $a->species?->name,
                'breedName' => $a->breed?->name,
                'primaryColor' => $a->primaryColor,
                'weightKg' => $a->weightAsOnRegistration,
                'farmUuid' => $a->farmUuid,
                'farmName' => $a->farm?->name,
                'owner' => [
                    'name' => $owners[$a->farmUuid]['name'],
                    'phone' => $owners[$a->farmUuid]['phone'],
                ],
                'held' => $hold !== null,
                'heldChannel' => $hold?->channel,
            ];
        })->values()->all();
    }

    /** Who gets paid for an animal on this farm: the farm's owner. */
    public function ownerCard(?Farm $farm): array
    {
        $farmer = $farm?->farmerId ? Farmer::find($farm->farmerId) : null;
        $user = $farmer
            ? User::where('role', UserRole::FARMER)->where('roleId', $farmer->id)->first()
            : null;
        $name = trim(implode(' ', array_filter([$farmer?->firstName, $farmer?->surname])));

        return [
            'userId' => $user?->id,
            'name' => $name !== '' ? $name : ($user?->username ?? $farm?->name ?? 'Unknown owner'),
            'phone' => $farmer?->phone1 ?: ($farmer?->phone2 ?: null),
            'farmUuid' => $farm?->uuid,
            'farmName' => $farm?->name,
        ];
    }
}
