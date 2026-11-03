<?php

namespace App\Http\Controllers\Auction;

use App\Enums\AuctionSessionStatus as Status;
use App\Http\Controllers\Controller;
use App\Http\Controllers\VetServices\Concerns\RespondsVetServices;
use App\Models\AuctionSale;
use App\Models\AuctionSession;
use App\Services\Auction\AuctionService;
use App\Services\Live\LiveKitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Auction sessions: lobby, scheduling, going live, joining, presence, ending,
 * chat, state snapshots, and purchases.
 */
class AuctionSessionController extends Controller
{
    use RespondsVetServices;

    public function __construct(private readonly AuctionService $service, private readonly LiveKitService $livekit)
    {
    }

    /** GET auctions/realtime-config — Reverb + LiveKit client settings (no secrets). */
    public function realtimeConfig(): JsonResponse
    {
        return $this->ok('Realtime config', [
            'reverb' => [
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('broadcasting.connections.reverb.options.host'),
                'port' => (int) config('broadcasting.connections.reverb.options.port'),
                'scheme' => config('broadcasting.connections.reverb.options.scheme'),
                'authEndpoint' => url('/api/broadcasting/auth'),
            ],
            'livekit' => [
                'configured' => $this->livekit->configured(),
                'url' => $this->livekit->url(),
            ],
            'iceServers' => $this->livekit->iceServers(),
        ]);
    }

    /** GET auctions/sessions?scope=live|upcoming|mine|assigned|ended|all&centreUuid= */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->service->sweepStale();
        $scope = $request->query('scope', 'all');
        $q = AuctionSession::with(['host', 'centre']);
        if ($request->filled('centreUuid')) {
            $q->whereHas('centre', fn ($c) => $c->where('uuid', $request->query('centreUuid')));
        }
        match ($scope) {
            'live' => $q->live()->orderByDesc('viewers'),
            'upcoming' => $q->upcoming(),
            'mine' => $q->where('hostUserId', $user->id)->orderByDesc('created_at'),
            'assigned' => $q->where('hostUserId', $user->id)->whereNotNull('centreId')
                ->orderByRaw("FIELD(status, 'live', 'scheduled', 'ended', 'cancelled')")->orderBy('scheduledAt'),
            'ended' => $q->where('status', Status::ENDED)->orderByDesc('endedAt'),
            default => $q->whereIn('status', [Status::LIVE, Status::SCHEDULED])
                ->orderByRaw("FIELD(status, 'live', 'scheduled')")->orderBy('scheduledAt'),
        };
        $items = $q->limit(min(100, (int) $request->query('limit', 50)))->get();

        return $this->ok('Auctions', [
            'sessions' => $items->map(fn (AuctionSession $s) => $s->toWire($user->id))->values()->all(),
            'liveCount' => AuctionSession::live()->count(),
            'serverTime' => now()->toIso8601String(),
        ]);
    }

    /** POST auctions/sessions */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'nullable|string|max:100',
            'title' => 'required|string|min:3|max:120',
            'centreUuid' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:2000',
            'scheduledAt' => 'nullable|date',
            'currency' => 'nullable|string|max:8',
            'paymentInstructions' => 'nullable|string|max:1000',
            'settings' => 'nullable|array',
            'settings.chat' => 'nullable|boolean',
            'settings.antiSnipeSeconds' => 'nullable|integer|min:0|max:120',
            'settings.extendBySeconds' => 'nullable|integer|min:5|max:120',
            'settings.paymentWindowHours' => 'nullable|integer|min:1|max:720',
            'lots' => 'nullable|array|max:50',
            'lots.*.uuid' => 'nullable|string|max:100',
            'lots.*.livestockUuid' => 'required|string|max:100',
            'lots.*.farmUuid' => 'nullable|string|max:100',
            'lots.*.livestock' => 'nullable|array',
            'lots.*.title' => 'nullable|string|max:120',
            'lots.*.description' => 'nullable|string|max:2000',
            'lots.*.photos' => 'nullable|array|max:6',
            'lots.*.startingPrice' => 'required|numeric|min:0',
            'lots.*.minIncrement' => 'required|numeric|min:1',
            'lots.*.reservePrice' => 'nullable|numeric|min:0',
            'lots.*.durationSeconds' => 'nullable|integer|min:30|max:1800',
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        return $this->guard(function () use ($request, $validator) {
            $session = $this->service->createSession($request->user(), $validator->validated());

            return $this->ok('Auction created', $session->load(['host', 'centre'])->toWire($request->user()->id, true), 201);
        });
    }

    /** GET auctions/sessions/{uuid} */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $session = AuctionSession::with(['host', 'centre', 'lots.currentBid'])->where('uuid', $uuid)->first();
        if (! $session) {
            return $this->fail('Auction not found', 404, 'NOT_FOUND');
        }
        $this->service->closeExpiredLots($session);

        return $this->ok('Auction', $session->refresh()->load(['host', 'lots.currentBid'])->toWire($request->user()->id, true));
    }

    /** PUT auctions/sessions/{uuid} */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|min:3|max:120',
            'description' => 'sometimes|nullable|string|max:2000',
            'scheduledAt' => 'sometimes|date',
            'currency' => 'sometimes|string|max:8',
            'paymentInstructions' => 'sometimes|nullable|string|max:1000',
            'settings' => 'sometimes|array',
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        return $this->guard(function () use ($request, $uuid, $validator) {
            $session = $this->service->updateSession($request->user(), $this->find($uuid), $validator->validated());

            return $this->ok('Auction updated', $session->load(['host', 'lots'])->toWire($request->user()->id, true));
        });
    }

    public function cancel(Request $request, string $uuid): JsonResponse
    {
        return $this->guard(function () use ($request, $uuid) {
            $session = $this->service->cancelSession($request->user(), $this->find($uuid));

            return $this->ok('Auction cancelled', $session->load('host')->toWire($request->user()->id, true));
        });
    }

    /** POST auctions/sessions/{uuid}/start — host goes live, gets a publish token. */
    public function start(Request $request, string $uuid): JsonResponse
    {
        return $this->guard(function () use ($request, $uuid) {
            return $this->ok('You are live', $this->service->startSession($request->user(), $this->find($uuid)));
        });
    }

    /** POST auctions/sessions/{uuid}/join — viewer joins, gets a subscribe token. */
    public function join(Request $request, string $uuid): JsonResponse
    {
        return $this->guard(function () use ($request, $uuid) {
            return $this->ok('Joined', $this->service->join($request->user(), $this->find($uuid)));
        });
    }

    public function heartbeat(Request $request, string $uuid): JsonResponse
    {
        return $this->guard(function () use ($request, $uuid) {
            return $this->ok('ok', $this->service->heartbeat($request->user(), $this->find($uuid)));
        });
    }

    public function leave(Request $request, string $uuid): JsonResponse
    {
        return $this->guard(function () use ($request, $uuid) {
            $this->service->leave($request->user(), $this->find($uuid));

            return $this->ok('Left');
        });
    }

    public function end(Request $request, string $uuid): JsonResponse
    {
        return $this->guard(function () use ($request, $uuid) {
            $session = $this->service->endSession($request->user(), $this->find($uuid));

            return $this->ok('Auction ended', array_merge($session->load('host')->toWire($request->user()->id, true), ['summary' => $this->service->summary($session)]));
        });
    }

    /** GET auctions/sessions/{uuid}/state — full snapshot (reconnect / polling fallback). */
    public function state(Request $request, string $uuid): JsonResponse
    {
        return $this->guard(function () use ($request, $uuid) {
            return $this->ok('State', $this->service->state($request->user(), $this->find($uuid)));
        });
    }

    /** POST auctions/sessions/{uuid}/chat {text} */
    public function chat(Request $request, string $uuid): JsonResponse
    {
        $validator = Validator::make($request->all(), ['text' => 'required|string|min:1|max:200']);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        return $this->guard(function () use ($request, $uuid) {
            return $this->ok('Sent', $this->service->chat($request->user(), $this->find($uuid), trim($request->text)), 201);
        });
    }

    /** GET auctions/sessions/{uuid}/events — retained bidding history. */
    public function events(Request $request, string $uuid): JsonResponse
    {
        $session = $this->find($uuid);

        return $this->ok('History', $session->events()->limit(1000)->get()->map->toWire()->values()->all());
    }

    /** GET auctions/purchases — sales where I'm the buyer (payment instructions). */
    public function purchases(Request $request): JsonResponse
    {
        $user = $request->user();
        $sales = AuctionSale::with(['lot', 'session', 'seller'])->where('buyerUserId', $user->id)->orderByDesc('id')->limit(100)->get();

        return $this->ok('Purchases', $sales->map(fn (AuctionSale $s) => $s->toWire($user->id))->values()->all());
    }

    /** GET auctions/sales — sales where I'm the seller. */
    public function sales(Request $request): JsonResponse
    {
        $user = $request->user();
        $sales = AuctionSale::with(['lot', 'session', 'buyer'])->where('sellerUserId', $user->id)->orderByDesc('id')->limit(200)->get();

        return $this->ok('Sales', $sales->map(fn (AuctionSale $s) => $s->toWire($user->id))->values()->all());
    }

    /** POST auctions/sales/{uuid}/mark-paid {reference?} */
    public function markPaid(Request $request, string $uuid): JsonResponse
    {
        return $this->guard(function () use ($request, $uuid) {
            $sale = AuctionSale::where('uuid', $uuid)->firstOrFail();
            $sale = $this->service->markSalePaid($request->user(), $sale, $request->input('reference'));

            return $this->ok('Payment confirmed', $sale->load(['lot', 'session', 'buyer'])->toWire($request->user()->id));
        });
    }

    private function find(string $uuid): AuctionSession
    {
        return AuctionSession::where('uuid', $uuid)->firstOrFail();
    }
}
