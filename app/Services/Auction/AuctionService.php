<?php

namespace App\Services\Auction;

use App\Enums\AuctionLotStatus as Lot;
use App\Enums\AuctionSessionStatus as Session;
use App\Enums\UserRole;
use App\Events\AuctionLobbyEvent;
use App\Events\AuctionRoomEvent;
use App\Jobs\SendVetServiceSms;
use App\Models\AuctionBid;
use App\Models\AuctionCentre;
use App\Models\AuctionEvent;
use App\Models\AuctionLot;
use App\Models\AuctionParticipant;
use App\Models\AuctionSale;
use App\Models\AuctionSession;
use App\Models\Farm;
use App\Models\FarmUser;
use App\Models\Livestock;
use App\Models\LivestockSaleHold;
use App\Models\User;
use App\Services\Live\LiveKitService;
use App\Services\VetServices\VetServiceException as AuctionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Business rules for live auctions. Every state change runs in a transaction
 * with a row lock on the lot/session and ends with a Reverb broadcast, so all
 * participants see the same thing at the same time.
 */
class AuctionService
{
    public const STALE_AFTER_SECONDS = 90;   // host silent -> session auto-ended
    public const PRESENT_WINDOW_SECONDS = 75; // viewer silent -> aged out

    public function __construct(
        private readonly LiveKitService $livekit,
        private readonly SaleHoldService $holds,
        private readonly AuctionCentreService $centres,
    ) {
    }

    // ------------------------------------------------------------------
    // Access
    // ------------------------------------------------------------------

    public function canHost(User $user): bool
    {
        return in_array($user->role, [UserRole::FARMER, UserRole::SYSTEM_USER], true)
            || $this->centres->hasHostingCentre($user);
    }

    /** Resolve and authorise the centre a session is to be hosted at, if any. */
    private function centreFor(User $host, array $data): ?AuctionCentre
    {
        if (empty($data['centreUuid'])) {
            if (in_array($host->role, [UserRole::FARMER, UserRole::SYSTEM_USER], true)) {
                return null; // farmers and admins may auction from their own farms
            }
            throw new AuctionException('Select the auction centre you are attached to.', 422, 'CENTRE_REQUIRED');
        }
        $centre = AuctionCentre::where('uuid', $data['centreUuid'])->first();
        if (! $centre || ! $centre->isActive()) {
            throw new AuctionException('Auction centre not found.', 404, 'CENTRE_NOT_FOUND');
        }
        if (! $this->centres->canHostAt($host, $centre)) {
            throw new AuctionException('You are not an auctioneer at this centre.', 403, 'NOT_CENTRE_MEMBER');
        }

        return $centre;
    }

    public function farmUuidsFor(User $user): array
    {
        if ($user->role === UserRole::FARMER) {
            return Farm::where('farmerId', $user->roleId)->pluck('uuid')->all();
        }
        if ($user->role === UserRole::FARM_INVITED_USER) {
            return FarmUser::find($user->roleId)?->getFarmUuidsArray() ?? [];
        }
        if ($user->role === UserRole::SYSTEM_USER) {
            return Farm::pluck('uuid')->all();
        }

        return [];
    }

    /** The session's auctioneer, or an administrator planning from the portal. */
    private function assertHost(User $user, AuctionSession $session): void
    {
        if (! $session->isHost($user->id) && $user->role !== UserRole::SYSTEM_USER) {
            throw new AuctionException('Only the auctioneer can do this.', 403, 'NOT_HOST');
        }
    }

    // ------------------------------------------------------------------
    // Sessions
    // ------------------------------------------------------------------

    /**
     * @param User $actor  the caller: the host themselves, or an administrator
     *                     planning on behalf of an officer (`$data['hostUserId']`).
     */
    public function createSession(User $actor, array $data): AuctionSession
    {
        $host = $actor;
        if (! empty($data['hostUserId']) && (int) $data['hostUserId'] !== (int) $actor->id) {
            if ($actor->role !== UserRole::SYSTEM_USER) {
                throw new AuctionException('Only administrators can schedule auctions for someone else.', 403, 'NOT_ADMIN');
            }
            $host = User::find((int) $data['hostUserId']);
            if (! $host) {
                throw new AuctionException('Assigned officer not found.', 404, 'OFFICER_NOT_FOUND');
            }
            if (empty($data['centreUuid'])) {
                throw new AuctionException('Portal-planned auctions must be at an auction centre.', 422, 'CENTRE_REQUIRED');
            }
        }
        if (! $this->canHost($host)) {
            throw new AuctionException('Only farmers or verified auction officers can host an auction.', 403, 'CANNOT_HOST');
        }
        $settings = array_merge(AuctionSession::defaultSettings(), $data['settings'] ?? []);
        $centre = $this->centreFor($host, $data);

        return DB::transaction(function () use ($host, $actor, $data, $settings, $centre) {
            $session = AuctionSession::create([
                'uuid' => $data['uuid'] ?? (string) Str::uuid(),
                'hostUserId' => $host->id,
                'centreId' => $centre?->id,
                'createdBy' => $actor->id !== $host->id ? $actor->id : null,
                'mode' => in_array($data['mode'] ?? 'live', ['live', 'physical'], true) ? ($data['mode'] ?? 'live') : 'live',
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => Session::SCHEDULED,
                'scheduledAt' => $data['scheduledAt'] ?? now(),
                'currency' => $data['currency'] ?? 'TZS',
                'settings' => $settings,
                'paymentInstructions' => $data['paymentInstructions'] ?? null,
            ]);
            foreach ($data['lots'] ?? [] as $i => $lot) {
                $this->addLot($actor, $session, $lot, $i + 1);
            }
            $this->event($session, 'session_created', $actor->id);

            return $session->refresh();
        });
    }

    public function updateSession(User $host, AuctionSession $session, array $data): AuctionSession
    {
        $this->assertHost($host, $session);
        if ($session->status !== Session::SCHEDULED) {
            throw new AuctionException('Only scheduled auctions can be edited.', 409, 'NOT_EDITABLE');
        }
        if (! empty($data['hostUserId']) && (int) $data['hostUserId'] !== (int) $session->hostUserId) {
            if ($host->role !== UserRole::SYSTEM_USER) {
                throw new AuctionException('Only administrators can reassign an auction.', 403, 'NOT_ADMIN');
            }
            $newHost = User::find((int) $data['hostUserId']);
            if (! $newHost || ! $this->canHost($newHost) || ($session->centreId && ! $this->centres->canHostAt($newHost, $session->centre))) {
                throw new AuctionException('That officer cannot host at this centre.', 422, 'OFFICER_NOT_ELIGIBLE');
            }
            $session->hostUserId = $newHost->id;
        }
        $session->fill(array_filter([
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'scheduledAt' => $data['scheduledAt'] ?? null,
            'paymentInstructions' => $data['paymentInstructions'] ?? null,
            'currency' => $data['currency'] ?? null,
            'mode' => in_array($data['mode'] ?? null, ['live', 'physical'], true) ? $data['mode'] : null,
        ], fn ($v) => $v !== null));
        if (isset($data['settings'])) {
            $session->settings = array_merge($session->config(), $data['settings']);
        }
        $session->save();

        return $session;
    }

    /**
     * Run a finished auction again: a new scheduled session with the same
     * title, mode, centre, settings and payment instructions, relisting every
     * animal that did not sell at its previous prices. The original session
     * and its history are kept untouched.
     */
    public function republishSession(User $actor, AuctionSession $session, array $data = []): AuctionSession
    {
        $this->assertHost($actor, $session);
        if (! in_array($session->status, [Session::ENDED, Session::CANCELLED], true)) {
            throw new AuctionException('Only ended or cancelled auctions can be republished.', 409, 'NOT_FINISHED');
        }
        $lots = $session->lots()->whereIn('status', [Lot::UNSOLD, Lot::CANCELLED, Lot::QUEUED])->get();
        if (! empty($data['lotUuids'])) {
            $lots = $lots->whereIn('uuid', (array) $data['lotUuids'])->values();
        }
        if ($lots->isEmpty()) {
            throw new AuctionException('Every animal in this auction was sold. There is nothing to relist.', 422, 'NOTHING_TO_REPUBLISH');
        }

        $payload = [
            'title' => $data['title'] ?? $session->title,
            'description' => $session->description,
            'scheduledAt' => $data['scheduledAt'] ?? now()->addDay(),
            'currency' => $session->currency,
            'paymentInstructions' => $data['paymentInstructions'] ?? $session->paymentInstructions,
            'settings' => $session->config(),
            'mode' => $data['mode'] ?? ($session->mode ?? 'live'),
            'centreUuid' => $session->centre?->uuid,
            'hostUserId' => $session->hostUserId,
            'lots' => $lots->map(fn (AuctionLot $l) => array_filter([
                'livestockUuid' => $l->livestockUuid,
                'farmUuid' => $l->farmUuid,
                'livestock' => $l->livestockSnapshot,
                'title' => $l->title,
                'description' => $l->description,
                'photos' => $l->photos,
                'startingPrice' => (float) $l->startingPrice,
                'minIncrement' => (float) $l->minIncrement,
                'reservePrice' => $l->reservePrice,
                'durationSeconds' => $l->durationSeconds,
            ], fn ($v) => $v !== null))->values()->all(),
        ];

        $new = $this->createSession($actor, $payload);
        $this->event($session, 'session_republished', $actor->id, null, ['newUuid' => $new->uuid]);
        $this->event($new, 'session_republished_from', $actor->id, null, ['fromUuid' => $session->uuid]);
        $this->emit(new AuctionLobbyEvent('changed', ['uuid' => $new->uuid]));

        return $new;
    }

    public function cancelSession(User $host, AuctionSession $session): AuctionSession
    {
        $this->assertHost($host, $session);
        if ($session->status === Session::LIVE) {
            return $this->endSession($host, $session);
        }
        DB::transaction(function () use ($session, $host) {
            $session->update(['status' => Session::CANCELLED, 'endedAt' => now()]);
            foreach ($session->lots as $lot) {
                if ($lot->status === Lot::QUEUED) {
                    $lot->update(['status' => Lot::CANCELLED]);
                    $this->holds->release(LivestockSaleHold::CHANNEL_AUCTION, $lot->uuid);
                }
            }
            $this->event($session, 'session_cancelled', $host->id);
        });
        $this->emit(new AuctionLobbyEvent('changed', ['uuid' => $session->uuid]));

        return $session->refresh();
    }

    /** Go live: flips status, returns the host's publish token. */
    public function startSession(User $host, AuctionSession $session): array
    {
        $this->assertHost($host, $session);
        if ($session->status === Session::ENDED || $session->status === Session::CANCELLED) {
            throw new AuctionException('This auction has already finished.', 410, 'FINISHED');
        }
        if (! $this->canHost($host)) {
            throw new AuctionException('Your auction officer account is not verified yet.', 403, 'NOT_VERIFIED');
        }
        if ($session->lots()->count() === 0) {
            throw new AuctionException('Add at least one animal before going live.', 422, 'NO_LOTS');
        }
        if (! $session->isPhysical() && ! $this->livekit->configured()) {
            throw new AuctionException('Live video is not configured on the server yet.', 503, 'LIVE_NOT_CONFIGURED');
        }

        // End any other live session this host forgot about.
        AuctionSession::live()->where('hostUserId', $host->id)->where('id', '!=', $session->id)
            ->get()->each(fn ($s) => $this->endSession($host, $s));

        $wasLive = $session->status === Session::LIVE;
        $session->status = Session::LIVE;
        $session->startedAt ??= now();
        $session->lastHeartbeatAt = now();
        $session->save();

        $this->upsertParticipant($session, $host, 'host');

        if (! $wasLive) {
            if (! $session->isPhysical() && $this->livekit->egress('ListEgress', ['room_name' => $session->uuid]) !== null) {
                $egressId = $this->livekit->startRoomRecording($session->uuid, "auction-{$session->uuid}.mp4");
                if ($egressId) {
                    $session->update(['egressId' => $egressId, 'recordingStatus' => 'recording']);
                }
            }
            $this->event($session, 'session_started', $host->id);
            $this->emit(new AuctionRoomEvent($session->uuid, 'started', ['session' => $session->toWire($host->id)]));
            $this->emit(new AuctionLobbyEvent('started', ['uuid' => $session->uuid, 'title' => $session->title]));
        }

        return $this->joinPayload($session, $host, true);
    }

    public function join(User $user, AuctionSession $session): array
    {
        $this->sweepStale();
        $session->refresh();
        if ($session->status === Session::ENDED || $session->status === Session::CANCELLED) {
            throw new AuctionException('This auction has ended.', 410, 'ENDED');
        }
        $isHost = $session->isHost($user->id);
        if (! $isHost && $session->status !== Session::LIVE) {
            throw new AuctionException('This auction has not started yet.', 425, 'NOT_STARTED');
        }
        $this->upsertParticipant($session, $user, $isHost ? 'host' : 'buyer');
        $this->syncViewers($session);

        return $this->joinPayload($session, $user, $isHost);
    }

    public function heartbeat(User $user, AuctionSession $session): array
    {
        if ($session->isHost($user->id) && $session->isLive()) {
            $session->timestamps = false;
            $session->forceFill(['lastHeartbeatAt' => now()])->save();
            $session->timestamps = true;
        }
        AuctionParticipant::where('sessionId', $session->id)->where('userId', $user->id)->update(['lastSeenAt' => now()]);
        $viewers = $this->syncViewers($session);
        $this->closeExpiredLots($session);
        $this->sweepStale($session);
        $session->refresh();

        return ['live' => $session->isLive(), 'viewers' => $viewers, 'serverTime' => now()->toIso8601String()];
    }

    public function leave(User $user, AuctionSession $session): void
    {
        if ($session->isHost($user->id)) {
            return;
        }
        AuctionParticipant::where('sessionId', $session->id)->where('userId', $user->id)->update(['lastSeenAt' => now()->subSeconds(self::PRESENT_WINDOW_SECONDS + 1)]);
        $this->syncViewers($session);
    }

    public function endSession(User $host, AuctionSession $session): AuctionSession
    {
        $this->assertHost($host, $session);
        if ($session->status !== Session::LIVE) {
            return $session;
        }
        DB::transaction(function () use ($session, $host) {
            $live = $session->liveLot();
            if ($live) {
                $this->closeLot($live, $host->id, 'ended_by_host');
            }
            foreach ($session->lots()->where('status', Lot::QUEUED)->get() as $lot) {
                $lot->update(['status' => Lot::UNSOLD, 'closedAt' => now()]);
                $this->holds->release(LivestockSaleHold::CHANNEL_AUCTION, $lot->uuid);
            }
            $session->update(['status' => Session::ENDED, 'endedAt' => now(), 'viewers' => 0]);
            if ($session->egressId) {
                $this->livekit->stopRecording($session->egressId);
                $session->update(['recordingStatus' => 'processing']);
            }
            $this->event($session, 'session_ended', $host->id, null, $this->summary($session));
        });
        $session->refresh();
        $this->emit(new AuctionRoomEvent($session->uuid, 'ended', $this->summary($session)));
        $this->emit(new AuctionLobbyEvent('ended', ['uuid' => $session->uuid]));

        return $session;
    }

    public function summary(AuctionSession $session): array
    {
        return [
            'soldCount' => (int) $session->soldCount,
            'lotsCount' => (int) $session->lotsCount,
            'grossSales' => (float) $session->grossSales,
            'viewerPeak' => (int) $session->viewerPeak,
            'durationSeconds' => $session->startedAt ? max(0, ($session->endedAt ?? now())->getTimestamp() - $session->startedAt->getTimestamp()) : 0,
        ];
    }

    // ------------------------------------------------------------------
    // Lots
    // ------------------------------------------------------------------

    public function addLot(User $host, AuctionSession $session, array $data, ?int $lotNumber = null): AuctionLot
    {
        $this->assertHost($host, $session);
        if ($session->status === Session::ENDED || $session->status === Session::CANCELLED) {
            throw new AuctionException('This auction has finished.', 410, 'FINISHED');
        }
        $animal = Livestock::with('farm')->where('uuid', $data['livestockUuid'])->first();
        $sellerUserId = null;
        $sellerCard = null;
        if ($session->centreId) {
            // Centre sessions consign animals farmers bring to the market: any
            // tagged animal on record, with its owner recorded as the seller.
            if (! $animal) {
                throw new AuctionException('Animal not found. Only animals registered in Tag & Seal can be consigned.', 404, 'ANIMAL_NOT_FOUND');
            }
            $farmUuid = $animal->farmUuid;
            $owner = $this->centres->ownerCard($animal->farm);
            $sellerUserId = $owner['userId'];
            $sellerCard = ['name' => $owner['name'], 'phone' => $owner['phone'], 'farmName' => $owner['farmName']];
        } else {
            $farmUuids = $this->farmUuidsFor($host);
            $farmUuid = $data['farmUuid'] ?? $animal?->farmUuid;
            if (! $farmUuid || ! in_array($farmUuid, $farmUuids, true)) {
                throw new AuctionException('You can only auction animals from your own farms.', 403, 'ANIMAL_NOT_OWNED');
            }
        }
        if ($session->lots()->where('livestockUuid', $data['livestockUuid'])->exists()) {
            throw new AuctionException('This animal is already a lot in this auction.', 409, 'DUPLICATE_LOT');
        }

        return DB::transaction(function () use ($session, $data, $animal, $farmUuid, $host, $lotNumber, $sellerUserId, $sellerCard) {
            $uuid = $data['uuid'] ?? (string) Str::uuid();
            $this->holds->acquire($data['livestockUuid'], LivestockSaleHold::CHANNEL_AUCTION, $uuid, $host->id);
            $number = $lotNumber ?? ((int) $session->lots()->max('lotNumber') + 1);
            $snapshot = $this->livestockSnapshot($animal, $data['livestock'] ?? null, $data['livestockUuid']);
            $lot = AuctionLot::create([
                'uuid' => $uuid,
                'sessionId' => $session->id,
                'livestockUuid' => $data['livestockUuid'],
                'farmUuid' => $farmUuid,
                'sellerUserId' => $sellerUserId,
                'sellerCard' => $sellerCard,
                'livestockSnapshot' => $snapshot,
                'lotNumber' => $number,
                'title' => $data['title'] ?? ($snapshot['name'] ?? $snapshot['identificationNumber'] ?? "Lot $number"),
                'description' => $data['description'] ?? null,
                'photos' => $data['photos'] ?? null,
                'startingPrice' => $data['startingPrice'],
                'minIncrement' => $data['minIncrement'],
                'reservePrice' => $data['reservePrice'] ?? null,
                'durationSeconds' => $data['durationSeconds'] ?? 120,
                'status' => Lot::QUEUED,
                'currentPrice' => 0,
            ]);
            $session->increment('lotsCount');

            return $lot;
        });
    }

    public function updateLot(User $host, AuctionLot $lot, array $data): AuctionLot
    {
        $session = $lot->session;
        $this->assertHost($host, $session);
        if ($lot->status !== Lot::QUEUED) {
            throw new AuctionException('Only queued lots can be edited.', 409, 'LOT_NOT_EDITABLE');
        }
        $lot->fill(array_intersect_key($data, array_flip(['title', 'description', 'photos', 'startingPrice', 'minIncrement', 'reservePrice', 'durationSeconds'])))->save();

        return $lot;
    }

    public function removeLot(User $host, AuctionLot $lot): void
    {
        $session = $lot->session;
        $this->assertHost($host, $session);
        if ($lot->status !== Lot::QUEUED) {
            throw new AuctionException('Only queued lots can be removed.', 409, 'LOT_NOT_EDITABLE');
        }
        DB::transaction(function () use ($lot, $session) {
            $this->holds->release(LivestockSaleHold::CHANNEL_AUCTION, $lot->uuid);
            $lot->delete();
            $session->decrement('lotsCount');
        });
    }

    /** Host puts a lot on the block. Only one lot is live at a time. */
    public function openLot(User $host, AuctionLot $lot): AuctionLot
    {
        $session = $lot->session;
        $this->assertHost($host, $session);
        if (! $session->isLive()) {
            throw new AuctionException('Start the live session before opening a lot.', 409, 'SESSION_NOT_LIVE');
        }
        if ($lot->status !== Lot::QUEUED) {
            throw new AuctionException('This lot is not waiting to be opened.', 409, 'LOT_NOT_QUEUED');
        }

        $lot = DB::transaction(function () use ($lot, $session, $host) {
            $current = AuctionLot::where('sessionId', $session->id)->where('status', Lot::LIVE)->lockForUpdate()->first();
            if ($current) {
                $this->closeLot($current, $host->id, 'host_opened_next');
            }
            $locked = AuctionLot::whereKey($lot->id)->lockForUpdate()->first();
            $locked->update([
                'status' => Lot::LIVE,
                'openedAt' => now(),
                'closesAt' => $session->isPhysical() ? null : now()->addSeconds($locked->durationSeconds),
                'currentPrice' => 0,
                'bidCount' => 0,
                'version' => $locked->version + 1,
            ]);
            $this->event($session, 'lot_opened', $host->id, $locked->id, ['lotUuid' => $locked->uuid]);

            return $locked;
        });

        $this->emit(new AuctionRoomEvent($session->uuid, 'lot_opened', ['lot' => $lot->toWire(null, false)]));

        return $lot;
    }

    /** Host ends bidding early; the normal path is expiry via heartbeat/poll. */
    public function closeLotNow(User $host, AuctionLot $lot): AuctionLot
    {
        $this->assertHost($host, $lot->session);
        if (! $lot->isLive()) {
            throw new AuctionException('This lot is not open.', 409, 'LOT_NOT_LIVE');
        }

        return DB::transaction(function () use ($lot, $host) {
            $locked = AuctionLot::whereKey($lot->id)->lockForUpdate()->first();

            return $this->closeLot($locked, $host->id, 'closed_by_host');
        });
    }

    /**
     * Place a bid. The whole decision happens under a row lock so two bids at
     * the same moment are serialised and the higher one wins deterministically.
     */
    public function placeBid(User $bidder, AuctionLot $lot, float $amount, ?string $bidUuid = null): array
    {
        $session = $lot->session;
        if ($session->isHost($bidder->id)) {
            throw new AuctionException('The auctioneer cannot bid on their own animals.', 403, 'HOST_CANNOT_BID');
        }
        if ($session->isPhysical()) {
            throw new AuctionException('This is an in-person auction. The auctioneer records the sale at the ring.', 409, 'PHYSICAL_SESSION');
        }
        $participant = AuctionParticipant::where('sessionId', $session->id)->where('userId', $bidder->id)->first();
        if (! $participant) {
            throw new AuctionException('Join the auction before bidding.', 403, 'NOT_JOINED');
        }
        if ($bidUuid) {
            $dup = AuctionBid::where('uuid', $bidUuid)->first();
            if ($dup) {
                return ['bid' => $dup, 'lot' => $lot->refresh(), 'extended' => false];
            }
        }

        $result = DB::transaction(function () use ($lot, $bidder, $amount, $bidUuid, $participant, $session) {
            $locked = AuctionLot::whereKey($lot->id)->lockForUpdate()->first();
            if (! $locked->isLive()) {
                throw new AuctionException('Bidding on this lot is closed.', 409, 'LOT_CLOSED');
            }
            if ($locked->closesAt->lte(now())) {
                $this->closeLot($locked, null, 'expired');
                throw new AuctionException('Time is up for this lot.', 409, 'LOT_CLOSED');
            }
            $minimum = $locked->minimumNextBid();
            if ($amount + 0.0001 < $minimum) {
                throw new AuctionException(
                    'Your bid must be at least '.number_format($minimum, 0).' '.$session->currency.'.',
                    422,
                    'BID_TOO_LOW'
                );
            }
            if ($locked->currentBidId) {
                $current = AuctionBid::find($locked->currentBidId);
                if ($current && (int) $current->bidderUserId === (int) $bidder->id) {
                    throw new AuctionException('You are already the leading bidder.', 409, 'ALREADY_LEADING');
                }
                AuctionBid::where('id', $locked->currentBidId)->update(['status' => 'outbid']);
            }
            $bid = AuctionBid::create([
                'uuid' => $bidUuid ?: (string) Str::uuid(),
                'lotId' => $locked->id,
                'sessionId' => $session->id,
                'bidderUserId' => $bidder->id,
                'bidderNumber' => $participant->bidderNumber,
                'amount' => $amount,
                'status' => 'leading',
                'created_at' => now(),
            ]);

            // Anti-sniping: a bid in the final seconds pushes the clock out.
            $cfg = $session->config();
            $extended = false;
            $remaining = $locked->closesAt->getTimestamp() - now()->getTimestamp();
            $closesAt = $locked->closesAt;
            if ($remaining < (int) $cfg['antiSnipeSeconds']) {
                $closesAt = now()->addSeconds((int) $cfg['extendBySeconds']);
                $extended = true;
            }
            $locked->update([
                'currentPrice' => $amount,
                'currentBidId' => $bid->id,
                'bidCount' => $locked->bidCount + 1,
                'closesAt' => $closesAt,
                'extendedCount' => $extended ? $locked->extendedCount + 1 : $locked->extendedCount,
                'version' => $locked->version + 1,
            ]);
            $this->event($session, 'bid', $bidder->id, $locked->id, ['amount' => $amount, 'bidderNumber' => $participant->bidderNumber, 'extended' => $extended]);

            return ['bid' => $bid, 'lot' => $locked->refresh(), 'extended' => $extended];
        });

        $this->emit(new AuctionRoomEvent($session->uuid, 'bid', [
            'lotUuid' => $result['lot']->uuid,
            'bid' => $result['bid']->toWire(),
            'currentPrice' => (float) $result['lot']->currentPrice,
            'minimumNextBid' => $result['lot']->minimumNextBid(),
            'bidCount' => $result['lot']->bidCount,
            'closesAt' => $result['lot']->closesAt?->toIso8601String(),
            'secondsRemaining' => $result['lot']->secondsRemaining(),
            'extended' => $result['extended'],
            'reserveMet' => $result['lot']->reserveMet(),
            'version' => $result['lot']->version,
        ]));

        return $result;
    }

    /** Closes any live lot whose clock ran out. Called from heartbeat and state polls. */
    public function closeExpiredLots(AuctionSession $session): void
    {
        $expired = AuctionLot::where('sessionId', $session->id)->where('status', Lot::LIVE)
            ->whereNotNull('closesAt')->where('closesAt', '<=', now())->pluck('id');
        foreach ($expired as $id) {
            DB::transaction(function () use ($id) {
                $locked = AuctionLot::whereKey($id)->lockForUpdate()->first();
                if ($locked && $locked->isLive() && $locked->closesAt->lte(now())) {
                    $this->closeLot($locked, null, 'expired');
                }
            });
        }
    }

    /**
     * Decides the outcome of a lot. Must be called with the row already locked
     * inside a transaction. Broadcasts after the caller's transaction commits.
     */
    private function closeLot(AuctionLot $lot, ?int $actorUserId, string $reason): AuctionLot
    {
        $session = $lot->session;
        $leading = $lot->currentBidId ? AuctionBid::find($lot->currentBidId) : null;
        $sold = $leading !== null && $lot->reserveMet();
        $sale = null;

        if ($sold) {
            $cfg = $session->config();
            $lot->update([
                'status' => Lot::SOLD,
                'closedAt' => now(),
                'winnerUserId' => $leading->bidderUserId,
                'winningBidId' => $leading->id,
                'version' => $lot->version + 1,
            ]);
            $leading->update(['status' => 'won']);
            AuctionBid::where('lotId', $lot->id)->where('id', '!=', $leading->id)->whereIn('status', ['outbid', 'leading'])->update(['status' => 'lost']);
            $sale = AuctionSale::create([
                'uuid' => (string) Str::uuid(),
                'lotId' => $lot->id,
                'sessionId' => $session->id,
                'buyerUserId' => $leading->bidderUserId,
                'sellerUserId' => $lot->sellerUserId ?: $session->hostUserId,
                'livestockUuid' => $lot->livestockUuid,
                'amount' => $leading->amount,
                'currency' => $session->currency,
                'status' => 'awaiting_payment',
                'paymentInstructions' => $session->paymentInstructions,
                'paymentDeadlineAt' => now()->addHours((int) $cfg['paymentWindowHours']),
            ]);
            $this->holds->consume(LivestockSaleHold::CHANNEL_AUCTION, $lot->uuid);
            AuctionSession::whereKey($session->id)->update([
                'soldCount' => DB::raw('soldCount + 1'),
                'grossSales' => DB::raw('grossSales + '.((float) $leading->amount)),
            ]);
        } else {
            $lot->update(['status' => Lot::UNSOLD, 'closedAt' => now(), 'version' => $lot->version + 1]);
            AuctionBid::where('lotId', $lot->id)->whereIn('status', ['outbid', 'leading'])->update(['status' => 'lost']);
            $this->holds->release(LivestockSaleHold::CHANNEL_AUCTION, $lot->uuid);
        }
        $this->event($session, $sold ? 'lot_sold' : 'lot_unsold', $actorUserId, $lot->id, [
            'reason' => $reason,
            'amount' => $leading?->amount,
            'bidderNumber' => $leading?->bidderNumber,
            'reserveMet' => $lot->reserveMet(),
        ]);

        DB::afterCommit(function () use ($session, $lot, $sold, $leading, $sale, $reason) {
            $this->emit(new AuctionRoomEvent($session->uuid, 'lot_closed', [
                'lotUuid' => $lot->uuid,
                'status' => $lot->status,
                'sold' => $sold,
                'reason' => $reason,
                'amount' => $leading ? (float) $leading->amount : null,
                'bidderNumber' => $leading?->bidderNumber,
                'winnerUserId' => $sold ? $leading->bidderUserId : null,
                'saleUuid' => $sale?->uuid,
                'version' => $lot->version,
            ]));
            if ($sold) {
                $this->notifyWinner($session, $lot, $sale);
                $this->notifySeller($session, $lot, $sale);
            }
        });

        return $lot->refresh();
    }

    // ------------------------------------------------------------------
    // Chat & state
    // ------------------------------------------------------------------

    public function chat(User $user, AuctionSession $session, string $text): array
    {
        if (! $session->isLive()) {
            throw new AuctionException('This auction is not live.', 409, 'NOT_LIVE');
        }
        if (! ($session->config()['chat'] ?? true) && ! $session->isHost($user->id)) {
            throw new AuctionException('Chat is turned off for this auction.', 403, 'CHAT_OFF');
        }
        $participant = AuctionParticipant::where('sessionId', $session->id)->where('userId', $user->id)->first();
        $payload = [
            'userId' => $user->id,
            'bidderNumber' => $participant?->bidderNumber,
            'name' => $session->isHost($user->id) ? ($user->farmerCard()['name'] ?? 'Auctioneer') : 'Bidder #'.($participant?->bidderNumber ?? '?'),
            'isHost' => $session->isHost($user->id),
            'text' => Str::limit($text, 200, ''),
            'uuid' => (string) Str::uuid(),
        ];
        $this->event($session, 'chat', $user->id, null, ['text' => $payload['text']]);
        $this->emit(new AuctionRoomEvent($session->uuid, 'chat', $payload));

        return $payload;
    }

    /** Complete snapshot for (re)joining clients and for polling fallback. */
    public function state(User $user, AuctionSession $session): array
    {
        $this->closeExpiredLots($session);
        $session->refresh()->load(['host', 'lots.currentBid']);
        $isHost = $session->isHost($user->id);
        $live = $session->liveLot();
        $participant = AuctionParticipant::where('sessionId', $session->id)->where('userId', $user->id)->first();
        $recent = $live
            ? AuctionBid::where('lotId', $live->id)->orderByDesc('id')->limit(20)->get()->map->toWire($user->id)->values()->all()
            : [];

        return [
            'session' => $session->toWire($user->id, true),
            'liveLot' => $live?->toWire($user->id, $isHost),
            'recentBids' => $recent,
            'me' => [
                'userId' => $user->id,
                'isHost' => $isHost,
                'bidderNumber' => $participant?->bidderNumber,
            ],
            'viewers' => $session->viewers,
            'serverTime' => now()->toIso8601String(),
        ];
    }

    public function markSalePaid(User $seller, AuctionSale $sale, ?string $reference): AuctionSale
    {
        if ((int) $sale->sellerUserId !== (int) $seller->id && $seller->role !== UserRole::SYSTEM_USER) {
            throw new AuctionException('Only the seller can confirm payment.', 403, 'NOT_SELLER');
        }
        $sale->update(['status' => 'paid', 'paidAt' => now(), 'paymentReference' => $reference, 'confirmedByUserId' => $seller->id]);

        return $sale;
    }

    // ------------------------------------------------------------------
    // Housekeeping
    // ------------------------------------------------------------------

    /** Sessions whose host went silent are ended so the lobby stays truthful. */
    public function sweepStale(?AuctionSession $only = null): void
    {
        $q = AuctionSession::live()->where(function ($w) {
            $w->whereNull('lastHeartbeatAt')->orWhere('lastHeartbeatAt', '<', now()->subSeconds(self::STALE_AFTER_SECONDS));
        })->where('startedAt', '<', now()->subSeconds(self::STALE_AFTER_SECONDS));
        if ($only) {
            $q->where('id', $only->id);
        }
        foreach ($q->limit(10)->get() as $session) {
            $host = User::find($session->hostUserId);
            if ($host) {
                $this->endSession($host, $session);
            }
        }
    }

    private function syncViewers(AuctionSession $session): int
    {
        $viewers = AuctionParticipant::where('sessionId', $session->id)
            ->where('role', 'buyer')
            ->where('lastSeenAt', '>=', now()->subSeconds(self::PRESENT_WINDOW_SECONDS))
            ->count();
        if ((int) $session->viewers !== $viewers) {
            $session->timestamps = false;
            $session->forceFill(['viewers' => $viewers, 'viewerPeak' => max((int) $session->viewerPeak, $viewers)])->save();
            $session->timestamps = true;
            $this->emit(new AuctionRoomEvent($session->uuid, 'viewers', ['viewers' => $viewers]));
        }

        return $viewers;
    }

    private function upsertParticipant(AuctionSession $session, User $user, string $role): AuctionParticipant
    {
        $existing = AuctionParticipant::where('sessionId', $session->id)->where('userId', $user->id)->first();
        if ($existing) {
            $existing->update(['lastSeenAt' => now(), 'role' => $role]);

            return $existing;
        }

        return DB::transaction(function () use ($session, $user, $role) {
            $next = (int) AuctionParticipant::where('sessionId', $session->id)->lockForUpdate()->max('bidderNumber') + 1;

            return AuctionParticipant::create([
                'sessionId' => $session->id,
                'userId' => $user->id,
                'bidderNumber' => $next,
                'role' => $role,
                'joinedAt' => now(),
                'lastSeenAt' => now(),
            ]);
        });
    }

    // ------------------------------------------------------------------
    // Weighing & in-person sales (ported from Shamba Bora instant auctions)
    // ------------------------------------------------------------------

    /**
     * A transient reading from the auctioneer's scale. Nothing is stored; it
     * is broadcast so every viewer watches the needle settle in real time.
     */
    public function recordWeightReading(User $host, AuctionLot $lot, float $kg, bool $stable, string $unit = 'kg', ?string $deviceName = null): void
    {
        $session = $lot->session;
        $this->assertHost($host, $session);
        if (! $session->isLive()) {
            throw new AuctionException('Start the session before weighing.', 409, 'SESSION_NOT_LIVE');
        }
        if (! in_array($lot->status, [Lot::QUEUED, Lot::LIVE], true)) {
            throw new AuctionException('This lot has already closed.', 409, 'LOT_CLOSED');
        }
        $this->emit(new AuctionRoomEvent($session->uuid, 'lot_weight_reading', [
            'lotUuid' => $lot->uuid,
            'kg' => round($kg, 2),
            'unit' => $unit,
            'stable' => $stable,
            'deviceName' => $deviceName,
        ]));
    }

    /** Locks the captured weight on the lot and tells the room. */
    public function lockWeight(User $host, AuctionLot $lot, array $data): AuctionLot
    {
        $session = $lot->session;
        $this->assertHost($host, $session);
        if (in_array($lot->status, [Lot::SOLD, Lot::CANCELLED], true)) {
            throw new AuctionException('This lot has already closed.', 409, 'LOT_CLOSED');
        }
        $kg = round((float) ($data['kg'] ?? 0), 2);
        if ($kg <= 0) {
            throw new AuctionException('Weight must be greater than zero.', 422, 'WEIGHT_REQUIRED');
        }
        $lot->update($this->weightAttributes($host, $kg, $data, $lot->version));
        $lot->refresh();
        $this->event($session, 'lot_weighed', $host->id, $lot->id, [
            'kg' => $kg,
            'stable' => (bool) $lot->weightStable,
            'source' => $lot->weightSource,
            'deviceName' => $lot->weightDeviceName,
        ]);
        $this->emit(new AuctionRoomEvent($session->uuid, 'lot_weight', [
            'lotUuid' => $lot->uuid,
            'weight' => $lot->weightWire(),
            'pricePerKg' => $lot->pricePerKg(),
            'version' => $lot->version,
        ]));

        return $lot;
    }

    private function weightAttributes(User $by, float $kg, array $data, int $currentVersion = 0): array
    {
        return [
            'weightKg' => $kg,
            'weightUnit' => $data['unit'] ?? 'kg',
            'weightStable' => (bool) ($data['stable'] ?? false),
            'weightSource' => ($data['source'] ?? 'scale') === 'manual' ? 'manual' : 'scale',
            'weightDeviceName' => $data['deviceName'] ?? null,
            'weighedAt' => now(),
            'weighedBy' => $by->id,
            'version' => $currentVersion + 1,
        ];
    }

    /**
     * Market-day sale recorded at the ring by the auctioneer: the buyer may
     * be a walk-in with no account. Produces the receipt and the SMS to both
     * the farmer and the buyer.
     *
     * @return array{lot: AuctionLot, sale: AuctionSale}
     */
    public function recordPhysicalSale(User $host, AuctionLot $lot, array $data): array
    {
        $session = $lot->session;
        $this->assertHost($host, $session);
        if (! $session->isPhysical()) {
            throw new AuctionException('This auction sells through live bidding.', 409, 'NOT_PHYSICAL');
        }
        if (! $session->isLive()) {
            throw new AuctionException('Begin the market day before recording sales.', 409, 'SESSION_NOT_LIVE');
        }
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new AuctionException('Enter the price the animal sold for.', 422, 'AMOUNT_REQUIRED');
        }
        $buyerUser = ! empty($data['buyerUserId']) ? User::find((int) $data['buyerUserId']) : null;
        $paid = (bool) ($data['paid'] ?? true);
        $cfg = $session->config();

        $result = DB::transaction(function () use ($session, $lot, $host, $data, $amount, $buyerUser, $paid, $cfg) {
            $locked = AuctionLot::whereKey($lot->id)->lockForUpdate()->first();
            if (! in_array($locked->status, [Lot::QUEUED, Lot::LIVE], true)) {
                throw new AuctionException('This lot has already closed.', 409, 'LOT_CLOSED');
            }
            $attrs = [
                'status' => Lot::SOLD,
                'closedAt' => now(),
                'currentPrice' => $amount,
                'winnerUserId' => $buyerUser?->id,
                'version' => $locked->version + 1,
            ];
            if (! empty($data['weightKg']) && (float) $data['weightKg'] > 0) {
                $attrs = array_merge($attrs, $this->weightAttributes($host, round((float) $data['weightKg'], 2), [
                    'unit' => $data['weightUnit'] ?? 'kg',
                    'stable' => $data['weightStable'] ?? false,
                    'source' => $data['weightSource'] ?? 'scale',
                    'deviceName' => $data['weightDeviceName'] ?? null,
                ], $locked->version));
            }
            $locked->update($attrs);
            $locked->refresh();

            $sale = AuctionSale::create([
                'uuid' => (string) Str::uuid(),
                'receiptNo' => self::receiptNo(),
                'lotId' => $locked->id,
                'sessionId' => $session->id,
                'buyerUserId' => $buyerUser?->id,
                'buyerName' => trim((string) ($data['buyerName'] ?? '')) ?: ($buyerUser?->farmerCard()['name'] ?? null),
                'buyerPhone' => $data['buyerPhone'] ?? ($buyerUser?->farmerCard()['phone'] ?? null),
                'sellerUserId' => $locked->sellerUserId ?: $session->hostUserId,
                'livestockUuid' => $locked->livestockUuid,
                'amount' => $amount,
                'weightKg' => $locked->weightKg,
                'pricePerKg' => $locked->pricePerKg($amount),
                'currency' => $session->currency,
                'status' => $paid ? 'paid' : 'awaiting_payment',
                'channel' => 'physical',
                'paymentInstructions' => $session->paymentInstructions,
                'paymentDeadlineAt' => $paid ? null : now()->addHours((int) $cfg['paymentWindowHours']),
                'paidAt' => $paid ? now() : null,
                'confirmedByUserId' => $paid ? $host->id : null,
                'recordedByUserId' => $host->id,
            ]);
            $this->holds->consume(LivestockSaleHold::CHANNEL_AUCTION, $locked->uuid);
            AuctionSession::whereKey($session->id)->update([
                'soldCount' => DB::raw('soldCount + 1'),
                'grossSales' => DB::raw('grossSales + '.$amount),
            ]);
            $this->event($session, 'lot_sold', $host->id, $locked->id, [
                'reason' => 'physical',
                'amount' => $amount,
                'buyerName' => $sale->buyerName,
                'weightKg' => $locked->weightKg,
                'receiptNo' => $sale->receiptNo,
            ]);

            DB::afterCommit(function () use ($session, $locked, $sale, $amount, $buyerUser) {
                $this->emit(new AuctionRoomEvent($session->uuid, 'lot_closed', [
                    'lotUuid' => $locked->uuid,
                    'status' => Lot::SOLD,
                    'sold' => true,
                    'reason' => 'physical',
                    'amount' => $amount,
                    'bidderNumber' => null,
                    'winnerUserId' => $buyerUser?->id,
                    'saleUuid' => $sale->uuid,
                    'weight' => $locked->weightWire(),
                    'version' => $locked->version,
                ]));
                $this->notifySeller($session, $locked, $sale);
                $this->notifyPhysicalBuyer($session, $locked, $sale);
            });

            return ['lot' => $locked, 'sale' => $sale];
        });

        return $result;
    }

    /** The animal did not sell at the ring: release it for another day. */
    public function passLot(User $host, AuctionLot $lot, ?string $reason = null): AuctionLot
    {
        $session = $lot->session;
        $this->assertHost($host, $session);
        if (! in_array($lot->status, [Lot::QUEUED, Lot::LIVE], true)) {
            throw new AuctionException('This lot has already closed.', 409, 'LOT_CLOSED');
        }

        return DB::transaction(function () use ($session, $lot, $host, $reason) {
            $locked = AuctionLot::whereKey($lot->id)->lockForUpdate()->first();
            if (! in_array($locked->status, [Lot::QUEUED, Lot::LIVE], true)) {
                throw new AuctionException('This lot has already closed.', 409, 'LOT_CLOSED');
            }
            $locked->update(['status' => Lot::UNSOLD, 'closedAt' => now(), 'version' => $locked->version + 1]);
            $this->holds->release(LivestockSaleHold::CHANNEL_AUCTION, $locked->uuid);
            $this->event($session, 'lot_unsold', $host->id, $locked->id, ['reason' => $reason ?: 'passed']);
            DB::afterCommit(fn () => $this->emit(new AuctionRoomEvent($session->uuid, 'lot_closed', [
                'lotUuid' => $locked->uuid,
                'status' => Lot::UNSOLD,
                'sold' => false,
                'reason' => $reason ?: 'passed',
                'amount' => null,
                'bidderNumber' => null,
                'winnerUserId' => null,
                'saleUuid' => null,
                'version' => $locked->version,
            ])));

            return $locked->refresh();
        });
    }

    /** Everything printed on a receipt. Buyer, seller, auctioneer and admins may read it. */
    public function receipt(User $viewer, AuctionSale $sale): array
    {
        $sale->load(['lot', 'session.centre', 'session.host', 'buyer', 'seller']);
        $session = $sale->session;
        $allowed = $viewer->role === UserRole::SYSTEM_USER
            || ($sale->buyerUserId !== null && (int) $sale->buyerUserId === (int) $viewer->id)
            || (int) $sale->sellerUserId === (int) $viewer->id
            || (int) ($sale->recordedByUserId ?? 0) === (int) $viewer->id
            || ($session !== null && $session->isHost($viewer->id));
        if (! $allowed) {
            throw new AuctionException('You cannot view this receipt.', 403, 'FORBIDDEN');
        }
        $lot = $sale->lot;

        return array_merge($sale->toWire($viewer->id, true), [
            'buyerName' => $sale->buyerName ?: ($sale->buyer?->farmerCard()['name'] ?? null),
            'buyerPhone' => $sale->buyerPhone ?: ($sale->buyer?->farmerCard()['phone'] ?? null),
            'sellerName' => $lot?->sellerCard['name'] ?? $sale->seller?->farmerCard()['name'] ?? null,
            'sellerFarm' => $lot?->sellerCard['farmName'] ?? null,
            'sellerPhone' => $lot?->sellerCard['phone'] ?? $sale->seller?->farmerCard()['phone'] ?? null,
            'weight' => $lot?->weightWire(),
            'centre' => $session?->centre?->summary(),
            'officer' => $session?->host?->farmerCard()['name'] ?? null,
            'session' => $session ? [
                'uuid' => $session->uuid,
                'title' => $session->title,
                'mode' => $session->mode ?? 'live',
                'scheduledAt' => $session->scheduledAt?->toIso8601String(),
            ] : null,
        ]);
    }

    public static function receiptNo(): string
    {
        do {
            $no = 'TS-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
        } while (AuctionSale::where('receiptNo', $no)->exists());

        return $no;
    }

    private function notifyPhysicalBuyer(AuctionSession $session, AuctionLot $lot, AuctionSale $sale): void
    {
        $phone = $sale->buyerPhone;
        if (! $phone) {
            return;
        }
        $animal = $lot->livestockSnapshot['name'] ?? $lot->livestockSnapshot['identificationNumber'] ?? $lot->title;
        $amount = number_format((float) $sale->amount, 0).' '.$sale->currency;
        $weight = $lot->weightKg ? ' '.number_format((float) $lot->weightKg, 1).' kg' : '';
        $venue = $session->centre?->name ?? $session->title;
        $msg = "Tag & Seal receipt {$sale->receiptNo}: {$animal} (Lot {$lot->lotNumber}){$weight} for {$amount} at {$venue}. "
            .($sale->status === 'paid' ? 'Paid. ' : 'Payment pending. ')
            .'Asante.';
        SendVetServiceSms::dispatchAfterResponse([$phone], $msg);
    }

    private function joinPayload(AuctionSession $session, User $user, bool $isHost): array
    {
        $name = $isHost ? ($user->farmerCard()['name'] ?? $user->username) : 'Bidder';
        $mediaOn = ! $session->isPhysical() && $this->livekit->configured();
        $token = $mediaOn
            ? $this->livekit->token($session->uuid, 'user-'.$user->id, $name, $isHost)
            : null;

        return array_merge($this->state($user, $session), [
            'livekit' => [
                'url' => $mediaOn ? $this->livekit->url() : null,
                'token' => $token,
                'configured' => $mediaOn,
                'iceServers' => $this->livekit->iceServers(),
            ],
        ]);
    }

    private function notifyWinner(AuctionSession $session, AuctionLot $lot, AuctionSale $sale): void
    {
        $buyer = User::find($sale->buyerUserId);
        $phone = $buyer?->farmerCard()['phone'] ?? null;
        if (! $phone) {
            return;
        }
        $animal = $lot->livestockSnapshot['name'] ?? $lot->livestockSnapshot['identificationNumber'] ?? $lot->title;
        $amount = number_format((float) $sale->amount, 0).' '.$sale->currency;
        $msg = "Tag & Seal: You won {$animal} (Lot {$lot->lotNumber}) in \"{$session->title}\" for {$amount}. "
            .'Payment: '.($session->paymentInstructions ?: 'see the app for instructions')
            .'. Pay before '.$sale->paymentDeadlineAt?->format('d M H:i').'.';
        SendVetServiceSms::dispatchAfterResponse([$phone], $msg);
    }

    /** Centre lots belong to a farmer who is not the host: tell them the result. */
    private function notifySeller(AuctionSession $session, AuctionLot $lot, AuctionSale $sale): void
    {
        if (! $lot->sellerUserId || (int) $lot->sellerUserId === (int) $session->hostUserId) {
            return;
        }
        $phone = $lot->sellerCard['phone'] ?? User::find($lot->sellerUserId)?->farmerCard()['phone'] ?? null;
        if (! $phone) {
            return;
        }
        $animal = $lot->livestockSnapshot['name'] ?? $lot->livestockSnapshot['identificationNumber'] ?? $lot->title;
        $amount = number_format((float) $sale->amount, 0).' '.$sale->currency;
        $venue = $session->centreId ? ($session->centre?->name ?? 'the auction centre') : $session->title;
        $msg = "Tag & Seal: Your animal {$animal} (Lot {$lot->lotNumber}) sold for {$amount} at {$venue}. "
            .'The buyer has until '.$sale->paymentDeadlineAt?->format('d M H:i').' to pay. Check the app for details.';
        SendVetServiceSms::dispatchAfterResponse([$phone], $msg);
    }

    private function livestockSnapshot(?Livestock $animal, ?array $client, string $uuid): array
    {
        if (! $animal) {
            return array_merge(['uuid' => $uuid], $client ?? []);
        }

        return [
            'uuid' => $animal->uuid,
            'name' => $animal->name,
            'identificationNumber' => $animal->identificationNumber,
            'gender' => $animal->gender,
            'dateOfBirth' => optional($animal->dateOfBirth)->toDateString(),
            'speciesId' => $animal->speciesId,
            'breedId' => $animal->breedId,
            'speciesName' => $client['speciesName'] ?? null,
            'breedName' => $client['breedName'] ?? null,
            'weightKg' => $client['weightKg'] ?? $animal->weightAsOnRegistration,
            'primaryColor' => $animal->primaryColor,
            'rfidTagId' => $animal->rfidTagId,
            'barcodeTagId' => $animal->barcodeTagId,
        ];
    }

    /**
     * Broadcast without letting a realtime outage break the request: bids and
     * state changes are already committed, clients also poll `state` as a
     * fallback.
     */
    private function emit(object $event): void
    {
        try {
            broadcast($event);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Auction broadcast failed: '.$e->getMessage(), ['event' => get_class($event)]);
        }
    }

    private function event(AuctionSession $session, string $type, ?int $actorUserId, ?int $lotId = null, ?array $payload = null): void
    {
        AuctionEvent::create([
            'sessionId' => $session->id,
            'lotId' => $lotId,
            'type' => $type,
            'actorUserId' => $actorUserId,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
