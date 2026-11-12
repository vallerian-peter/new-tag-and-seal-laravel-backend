<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Controllers\VetServices\Concerns\RespondsVetServices;
use App\Models\AuctionBid;
use App\Models\AuctionLot;
use App\Models\AuctionSession;
use App\Services\Auction\AuctionService;
use App\Services\Auction\SaleHoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Lots on the block and the bids placed on them. */
class AuctionLotController extends Controller
{
    use RespondsVetServices;

    public function __construct(private readonly AuctionService $service, private readonly SaleHoldService $holds)
    {
    }

    private const LOT_RULES = [
        'uuid' => 'nullable|string|max:100',
        'livestockUuid' => 'required|string|max:100',
        'farmUuid' => 'nullable|string|max:100',
        'livestock' => 'nullable|array',
        'title' => 'nullable|string|max:120',
        'description' => 'nullable|string|max:2000',
        'photos' => 'nullable|array|max:6',
        'startingPrice' => 'required|numeric|min:0',
        'minIncrement' => 'required|numeric|min:1',
        'reservePrice' => 'nullable|numeric|min:0',
        'durationSeconds' => 'nullable|integer|min:30|max:1800',
    ];

    /** POST auctions/sessions/{uuid}/lots */
    public function store(Request $request, string $uuid): JsonResponse
    {
        $validator = Validator::make($request->all(), self::LOT_RULES);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        return $this->guard(function () use ($request, $uuid, $validator) {
            $session = AuctionSession::where('uuid', $uuid)->firstOrFail();
            $lot = $this->service->addLot($request->user(), $session, $validator->validated());

            return $this->ok('Lot added', $lot->toWire($request->user()->id, true), 201);
        });
    }

    /** PUT auctions/lots/{uuid} */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $rules = array_map(fn ($r) => str_replace('required', 'sometimes', $r), self::LOT_RULES);
        unset($rules['livestockUuid'], $rules['uuid'], $rules['farmUuid'], $rules['livestock']);
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        return $this->guard(function () use ($request, $uuid, $validator) {
            $lot = $this->service->updateLot($request->user(), $this->find($uuid), $validator->validated());

            return $this->ok('Lot updated', $lot->toWire($request->user()->id, true));
        });
    }

    /** DELETE auctions/lots/{uuid} */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        return $this->guard(function () use ($request, $uuid) {
            $this->service->removeLot($request->user(), $this->find($uuid));

            return $this->ok('Lot removed', ['uuid' => $uuid]);
        });
    }

    /** POST auctions/lots/{uuid}/open — host puts the lot on the block. */
    public function open(Request $request, string $uuid): JsonResponse
    {
        return $this->guard(function () use ($request, $uuid) {
            $lot = $this->service->openLot($request->user(), $this->find($uuid));

            return $this->ok('Lot is open for bidding', $lot->toWire($request->user()->id, true));
        });
    }

    /** POST auctions/lots/{uuid}/close — host ends bidding early. */
    public function close(Request $request, string $uuid): JsonResponse
    {
        return $this->guard(function () use ($request, $uuid) {
            $lot = $this->service->closeLotNow($request->user(), $this->find($uuid));

            $message = match ($lot->status) {
                'awaiting_decision' => 'Bidding closed. Decide sold or not sold.',
                'sold' => 'Sold!',
                default => 'Lot closed without a sale',
            };

            return $this->ok($message, $lot->toWire($request->user()->id, true));
        });
    }

    /** POST auctions/lots/{uuid}/bid {amount, uuid?} */
    public function bid(Request $request, string $uuid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'uuid' => 'nullable|string|max:100',
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        return $this->guard(function () use ($request, $uuid) {
            $result = $this->service->placeBid($request->user(), $this->find($uuid), (float) $request->amount, $request->input('uuid'));

            return $this->ok('You are the leading bidder', [
                'bid' => $result['bid']->toWire($request->user()->id),
                'lot' => $result['lot']->toWire($request->user()->id, false),
                'extended' => $result['extended'],
            ], 201);
        });
    }

    /** POST auctions/lots/{uuid}/weight-reading {kg, stable?, unit?, deviceName?} — live needle, not stored. */
    public function weightReading(Request $request, string $uuid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'kg' => 'required|numeric|min:0|max:10000',
            'stable' => 'nullable|boolean',
            'unit' => 'nullable|string|max:8',
            'deviceName' => 'nullable|string|max:100',
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        return $this->guard(function () use ($request, $uuid) {
            $this->service->recordWeightReading(
                $request->user(),
                $this->find($uuid),
                (float) $request->input('kg'),
                (bool) $request->boolean('stable'),
                (string) $request->input('unit', 'kg'),
                $request->input('deviceName'),
            );

            return $this->ok('Reading shared');
        });
    }

    /** POST auctions/lots/{uuid}/weight {kg, unit?, stable?, source?, deviceName?} — locks the weight. */
    public function lockWeight(Request $request, string $uuid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'kg' => 'required|numeric|gt:0|max:10000',
            'unit' => 'nullable|string|max:8',
            'stable' => 'nullable|boolean',
            'source' => 'nullable|in:scale,manual',
            'deviceName' => 'nullable|string|max:100',
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        return $this->guard(function () use ($request, $uuid, $validator) {
            $lot = $this->service->lockWeight($request->user(), $this->find($uuid), $validator->validated());

            return $this->ok('Weight recorded', $lot->toWire($request->user()->id, true));
        });
    }

    /**
     * POST auctions/lots/{uuid}/physical-sale
     * {amount, buyerName, buyerPhone?, buyerUserId?, paid?, weightKg?, weightUnit?, weightStable?, weightSource?, weightDeviceName?}
     */
    public function physicalSale(Request $request, string $uuid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|gt:0',
            'buyerName' => 'required|string|max:150',
            'buyerPhone' => 'nullable|string|max:30',
            'buyerUserId' => 'nullable|integer',
            'paid' => 'nullable|boolean',
            'weightKg' => 'nullable|numeric|gt:0|max:10000',
            'weightUnit' => 'nullable|string|max:8',
            'weightStable' => 'nullable|boolean',
            'weightSource' => 'nullable|in:scale,manual',
            'weightDeviceName' => 'nullable|string|max:100',
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        return $this->guard(function () use ($request, $uuid, $validator) {
            $result = $this->service->recordPhysicalSale($request->user(), $this->find($uuid), $validator->validated());

            return $this->ok('Sold', [
                'lot' => $result['lot']->toWire($request->user()->id, true),
                'sale' => $this->service->receipt($request->user(), $result['sale']),
            ], 201);
        });
    }

    /** POST auctions/lots/{uuid}/pass {reason?} — unsold at the ring. */
    public function pass(Request $request, string $uuid): JsonResponse
    {
        return $this->guard(function () use ($request, $uuid) {
            $lot = $this->service->passLot($request->user(), $this->find($uuid), $request->input('reason'));

            return $this->ok('Lot passed', $lot->toWire($request->user()->id, true));
        });
    }

    /** POST auctions/lots/{uuid}/decide {decision: sold|unsold, note?} — auctioneer's final word. */
    public function decide(Request $request, string $uuid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'decision' => 'required|in:sold,unsold',
            'note' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        return $this->guard(function () use ($request, $uuid) {
            $lot = $this->service->decideLot($request->user(), $this->find($uuid), $request->input('decision'), $request->input('note'));

            return $this->ok($lot->status === 'sold' ? 'Sold!' : 'Marked not sold', $lot->toWire($request->user()->id, true));
        });
    }

    /** GET auctions/lots/{uuid}/bids */
    public function bids(Request $request, string $uuid): JsonResponse
    {
        $lot = $this->find($uuid);
        $bids = AuctionBid::where('lotId', $lot->id)->orderByDesc('id')->limit(200)->get();

        return $this->ok('Bids', $bids->map->toWire($request->user()->id)->values()->all());
    }

    /** GET auctions/my-bids — lots I bid on, with outcome. */
    public function myBids(Request $request): JsonResponse
    {
        $user = $request->user();
        $lotIds = AuctionBid::where('bidderUserId', $user->id)->distinct()->pluck('lotId');
        $lots = AuctionLot::with(['session.host', 'currentBid'])->whereIn('id', $lotIds)->orderByDesc('updated_at')->limit(100)->get();

        return $this->ok('My bids', $lots->map(function (AuctionLot $lot) use ($user) {
            $w = $lot->toWire($user->id, false);
            $w['session'] = $lot->session?->toWire($user->id);
            $w['myHighest'] = (float) AuctionBid::where('lotId', $lot->id)->where('bidderUserId', $user->id)->max('amount');

            return $w;
        })->values()->all());
    }

    /**
     * GET auctions/holds?livestockUuids[]=... — marketplace lock lookup so the
     * app can hide animals that are already for sale on any channel.
     */
    public function holds(Request $request): JsonResponse
    {
        $uuids = (array) $request->query('livestockUuids', []);
        if (empty($uuids) && $request->filled('farmUuid')) {
            $uuids = \App\Models\Livestock::where('farmUuid', $request->query('farmUuid'))->pluck('uuid')->all();
        }
        $holds = $this->holds->activeForMany(array_slice($uuids, 0, 500));

        return $this->ok('Sale holds', array_values(array_map(fn ($h) => $h->toWire(), $holds)));
    }

    private function find(string $uuid): AuctionLot
    {
        return AuctionLot::with('session')->where('uuid', $uuid)->firstOrFail();
    }
}
