<?php

namespace App\Http\Controllers\Auction;

use App\Enums\AuctionSessionStatus as Status;
use App\Http\Controllers\Controller;
use App\Http\Controllers\VetServices\Concerns\RespondsVetServices;
use App\Models\AuctionCentre;
use App\Models\AuctionLot;
use App\Models\AuctionOfficer;
use App\Models\AuctionSession;
use App\Models\Livestock;
use App\Services\Auction\AuctionCentreService;
use App\Services\Auction\AuctionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Portal-side auction planning: schedule a session at a centre, assign the
 * officer who will run it, and attach the animals to be sold. The officer
 * then sees it in the app and starts it on the day.
 */
class AuctionPlanController extends Controller
{
    use RespondsVetServices;

    public function __construct(
        private readonly AuctionService $service,
        private readonly AuctionCentreService $centres,
    ) {
    }

    /** GET admin/entities/auction-sessions?centreUuid=&status=&officerUserId=&from=&to=&q= */
    public function index(Request $request): JsonResponse
    {
        $q = AuctionSession::with(['host', 'centre']);
        if ($request->filled('centreUuid')) {
            $q->whereHas('centre', fn ($c) => $c->where('uuid', $request->query('centreUuid')));
        }
        if ($request->filled('status')) {
            $q->whereIn('status', explode(',', (string) $request->query('status')));
        }
        if ($request->filled('officerUserId')) {
            $q->where('hostUserId', (int) $request->query('officerUserId'));
        }
        if ($request->filled('from')) {
            $q->where('scheduledAt', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $q->where('scheduledAt', '<=', $request->query('to'));
        }
        if ($request->filled('q')) {
            $q->where('title', 'like', '%'.$request->query('q').'%');
        }
        $items = $q->orderByRaw("FIELD(status, 'live', 'scheduled', 'ended', 'cancelled')")
            ->orderByDesc('scheduledAt')
            ->limit(min(500, (int) $request->query('limit', 200)))
            ->get();

        return $this->ok('Auction sessions', $items->map(fn (AuctionSession $s) => $this->row($s))->values()->all());
    }

    /**
     * POST admin/entities/auction-sessions
     * Body: centreUuid, officerUserId | officerUuid, title, scheduledAt, description?,
     *       paymentInstructions?, currency?, settings?, lots[]{ livestockUuid | identificationNumber,
     *       startingPrice, minIncrement, reservePrice?, durationSeconds?, title? }
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), array_merge([
            'centreUuid' => 'required|string|max:100',
            'officerUserId' => 'nullable|integer',
            'officerUuid' => 'nullable|string|max:100',
            'title' => 'required|string|min:3|max:120',
            'mode' => 'nullable|in:live,physical',
            'description' => 'nullable|string|max:2000',
            'scheduledAt' => 'required|date',
            'currency' => 'nullable|string|max:8',
            'paymentInstructions' => 'nullable|string|max:1000',
            'settings' => 'nullable|array',
            'settings.chat' => 'nullable|boolean',
            'settings.antiSnipeSeconds' => 'nullable|integer|min:0|max:120',
            'settings.extendBySeconds' => 'nullable|integer|min:5|max:120',
            'settings.paymentWindowHours' => 'nullable|integer|min:1|max:720',
            'lots' => 'nullable|array|max:100',
        ], $this->lotRules('lots.*.')));
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        return $this->guard(function () use ($request, $validator) {
            $data = $validator->validated();
            $host = $this->resolveOfficer($request);
            if (! $host) {
                return $this->fail('Assign a verified auction officer (officerUserId or officerUuid).', 422, 'OFFICER_REQUIRED');
            }
            $data['hostUserId'] = $host->id;
            $data['lots'] = array_map(fn ($l) => $this->normaliseLot($l), $data['lots'] ?? []);
            $session = $this->service->createSession($request->user(), $data);

            return $this->ok('Auction scheduled', $this->row($session->load(['host', 'centre', 'lots'])), 201);
        });
    }

    /** GET admin/entities/auction-sessions/{uuid} */
    public function show(string $uuid): JsonResponse
    {
        $session = AuctionSession::with(['host', 'centre', 'lots.currentBid'])->where('uuid', $uuid)->first();
        if (! $session) {
            return $this->fail('Auction not found', 404, 'NOT_FOUND');
        }

        return $this->ok('Auction', $this->row($session));
    }

    /** PUT admin/entities/auction-sessions/{uuid} — edits and officer reassignment while scheduled. */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $session = AuctionSession::where('uuid', $uuid)->first();
        if (! $session) {
            return $this->fail('Auction not found', 404, 'NOT_FOUND');
        }
        $validator = Validator::make($request->all(), [
            'officerUserId' => 'nullable|integer',
            'officerUuid' => 'nullable|string|max:100',
            'title' => 'sometimes|string|min:3|max:120',
            'mode' => 'sometimes|in:live,physical',
            'description' => 'sometimes|nullable|string|max:2000',
            'scheduledAt' => 'sometimes|date',
            'currency' => 'sometimes|string|max:8',
            'paymentInstructions' => 'sometimes|nullable|string|max:1000',
            'settings' => 'sometimes|array',
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        return $this->guard(function () use ($request, $validator, $session) {
            $data = $validator->validated();
            if ($request->filled('officerUserId') || $request->filled('officerUuid')) {
                $host = $this->resolveOfficer($request);
                if (! $host) {
                    return $this->fail('Officer not found or not verified.', 422, 'OFFICER_REQUIRED');
                }
                $data['hostUserId'] = $host->id;
            }
            $updated = $this->service->updateSession($request->user(), $session, $data);

            return $this->ok('Auction updated', $this->row($updated->load(['host', 'centre', 'lots'])));
        });
    }

    /** POST admin/entities/auction-sessions/{uuid}/cancel */
    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $session = AuctionSession::where('uuid', $uuid)->first();
        if (! $session) {
            return $this->fail('Auction not found', 404, 'NOT_FOUND');
        }

        return $this->guard(function () use ($request, $session) {
            $updated = $this->service->cancelSession($request->user(), $session);

            return $this->ok('Auction cancelled', $this->row($updated->load(['host', 'centre'])));
        });
    }

    /** POST admin/entities/auction-sessions/{uuid}/lots — attach an animal by uuid or tag number. */
    public function addLot(Request $request, string $uuid): JsonResponse
    {
        $session = AuctionSession::where('uuid', $uuid)->first();
        if (! $session) {
            return $this->fail('Auction not found', 404, 'NOT_FOUND');
        }
        $validator = Validator::make($request->all(), $this->lotRules());
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        return $this->guard(function () use ($request, $validator, $session) {
            $lot = $this->service->addLot($request->user(), $session, $this->normaliseLot($validator->validated()));

            return $this->ok('Lot added', $lot->toWire(null, true), 201);
        });
    }

    /** PUT admin/entities/auction-lots/{uuid} */
    public function updateLot(Request $request, string $uuid): JsonResponse
    {
        $lot = AuctionLot::with('session')->where('uuid', $uuid)->first();
        if (! $lot) {
            return $this->fail('Lot not found', 404, 'NOT_FOUND');
        }
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:120',
            'description' => 'sometimes|nullable|string|max:2000',
            'startingPrice' => 'sometimes|numeric|min:0',
            'minIncrement' => 'sometimes|numeric|min:1',
            'reservePrice' => 'sometimes|nullable|numeric|min:0',
            'durationSeconds' => 'sometimes|integer|min:30|max:1800',
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        return $this->guard(function () use ($request, $validator, $lot) {
            $updated = $this->service->updateLot($request->user(), $lot, $validator->validated());

            return $this->ok('Lot updated', $updated->toWire(null, true));
        });
    }

    /** DELETE admin/entities/auction-lots/{uuid} */
    public function removeLot(Request $request, string $uuid): JsonResponse
    {
        $lot = AuctionLot::with('session')->where('uuid', $uuid)->first();
        if (! $lot) {
            return $this->fail('Lot not found', 404, 'NOT_FOUND');
        }

        return $this->guard(function () use ($request, $lot) {
            $this->service->removeLot($request->user(), $lot);

            return $this->ok('Lot removed');
        });
    }

    /** GET admin/entities/auction-livestock?q=TAG — find animals to assign, across all farms. */
    public function livestock(Request $request): JsonResponse
    {
        $q = (string) $request->query('q', '');

        return $this->ok('Animals', [
            'query' => $q,
            'animals' => $this->centres->searchLivestock(null, $q, (int) $request->query('limit', 30)),
        ]);
    }

    /** GET admin/entities/auction-centres/{centre}/officers — who can be assigned at a centre. */
    public function assignableOfficers(string $centre): JsonResponse
    {
        $model = ctype_digit($centre)
            ? AuctionCentre::find((int) $centre)
            : AuctionCentre::where('uuid', $centre)->orWhere('code', strtoupper($centre))->first();
        if (! $model) {
            return $this->fail('Auction centre not found', 404, 'NOT_FOUND');
        }
        $members = $model->activeMembers()->with('user')->get()
            ->filter(fn ($m) => $m->canHost())
            ->map(function ($m) {
                $officer = AuctionOfficer::where('userId', $m->userId)->first();
                $row = $m->toWire();
                $row['officer'] = $officer?->toWire(false, false);
                $row['assignable'] = $officer === null || $officer->isVerified();

                return $row;
            })->values()->all();

        return $this->ok('Assignable officers', $members);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function lotRules(string $prefix = ''): array
    {
        return [
            $prefix.'uuid' => 'nullable|string|max:100',
            $prefix.'livestockUuid' => 'nullable|string|max:100',
            $prefix.'identificationNumber' => 'nullable|string|max:100',
            $prefix.'title' => 'nullable|string|max:120',
            $prefix.'description' => 'nullable|string|max:2000',
            $prefix.'startingPrice' => 'required|numeric|min:0',
            $prefix.'minIncrement' => 'required|numeric|min:1',
            $prefix.'reservePrice' => 'nullable|numeric|min:0',
            $prefix.'durationSeconds' => 'nullable|integer|min:30|max:1800',
        ];
    }

    /** Accepts a tag number instead of a uuid, as the portal user will type tags. */
    private function normaliseLot(array $lot): array
    {
        if (empty($lot['livestockUuid']) && ! empty($lot['identificationNumber'])) {
            $animal = Livestock::where('identificationNumber', $lot['identificationNumber'])
                ->orWhere('rfidTagId', $lot['identificationNumber'])
                ->first();
            if (! $animal) {
                throw new \App\Services\VetServices\VetServiceException(
                    "No animal with tag {$lot['identificationNumber']}.",
                    404,
                    'ANIMAL_NOT_FOUND',
                );
            }
            $lot['livestockUuid'] = $animal->uuid;
        }
        if (empty($lot['livestockUuid'])) {
            throw new \App\Services\VetServices\VetServiceException('Each lot needs a livestockUuid or identificationNumber.', 422, 'VALIDATION');
        }
        unset($lot['identificationNumber']);

        return $lot;
    }

    private function resolveOfficer(Request $request): ?\App\Models\User
    {
        $officer = null;
        if ($request->filled('officerUuid')) {
            $officer = AuctionOfficer::where('uuid', $request->input('officerUuid'))->first();
        } elseif ($request->filled('officerUserId')) {
            $officer = AuctionOfficer::where('userId', (int) $request->input('officerUserId'))->first();
            if (! $officer) {
                // A centre manager without an officer profile may still be assigned.
                return \App\Models\User::find((int) $request->input('officerUserId'));
            }
        }
        if (! $officer || ! $officer->isVerified() || ! $officer->userId) {
            return null;
        }

        return $officer->user;
    }

    private function row(AuctionSession $s): array
    {
        $data = $s->toWire(null, $s->relationLoaded('lots'));
        $data['createdBy'] = $s->createdBy;
        $data['officer'] = $s->hostUserId
            ? AuctionOfficer::where('userId', $s->hostUserId)->first()?->toWire(false, false)
            : null;

        return $data;
    }
}
