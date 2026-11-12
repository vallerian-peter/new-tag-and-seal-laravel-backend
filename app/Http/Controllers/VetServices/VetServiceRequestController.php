<?php

namespace App\Http\Controllers\VetServices;

use App\Enums\VetProblemCategory;
use App\Enums\VetRequestUrgency;
use App\Enums\VetServiceRequestStatus as Status;
use App\Http\Controllers\Controller;
use App\Http\Controllers\VetServices\Concerns\RespondsVetServices;
use App\Models\User;
use App\Models\Vet;
use App\Models\VetServiceRequest;
use App\Models\VetServiceRequestOffer;
use App\Services\VetServices\VetDispatchService;
use App\Services\VetServices\VetServiceRequestService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VetServiceRequestController extends Controller
{
    use RespondsVetServices;

    public function __construct(private readonly VetServiceRequestService $service)
    {
    }

    // ==================================================================
    // Farmer side
    // ==================================================================

    /** GET vet-services/requests?status=open|history|all&limit= */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->service->sweepPending($user->id);

        $scope = $request->query('status', 'all');
        $q = VetServiceRequest::with('vet')->where('farmerUserId', $user->id);
        if ($scope === 'open') {
            $q->open();
        } elseif ($scope === 'history') {
            $q->whereIn('status', Status::terminal());
        } elseif (in_array($scope, Status::all(), true)) {
            $q->where('status', $scope);
        }
        $items = $q->orderByDesc('lastActivityAt')->orderByDesc('id')
            ->limit(min(100, (int) $request->query('limit', 50)))->get();

        return $this->ok('Requests', [
            'requests' => $this->wireMany($items, $user),
            'serverTime' => now()->toIso8601String(),
        ]);
    }

    /** POST vet-services/requests */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'nullable|string|max:100',
            'farmUuid' => 'required|string|max:100',
            'livestockUuid' => 'nullable|string|max:100',
            'livestock' => 'nullable|array',
            'preferredVetId' => 'nullable|integer|exists:vets,id',
            'problemCategory' => 'required|string|in:'.implode(',', VetProblemCategory::all()),
            'description' => 'required|string|min:5|max:2000',
            'urgency' => 'nullable|string|in:'.implode(',', VetRequestUrgency::all()),
            'symptoms' => 'nullable|array|max:20',
            'symptoms.*' => 'string|max:100',
            'photos' => 'nullable|array|max:5',
            'photos.*' => 'string|max:500',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'locationAddress' => 'nullable|string|max:255',
            'contactPhone' => 'nullable|string|max:30',
            'searchRadiusKm' => 'nullable|integer|min:5|max:'.VetDispatchService::MAX_RADIUS_KM,
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        return $this->guard(function () use ($request, $validator) {
            $created = $this->service->create($request->user(), $validator->validated());
            $created->load('vet');

            return $this->ok(
                $created->offersCount > 0
                    ? "Request sent to {$created->offersCount} nearby veterinarian(s)."
                    : 'Request created. We are still looking for a veterinarian nearby.',
                $this->wire($created, $request->user()),
                201
            );
        });
    }

    /** GET vet-services/requests/{uuid} */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();
        $req = VetServiceRequest::with(['vet', 'farmer'])->where('uuid', $uuid)->first();
        if (! $req || ! $this->service->canView($user, $req)) {
            return $this->fail('Request not found', 404, 'NOT_FOUND');
        }

        $data = $this->wire($req, $user);
        $data['events'] = $req->events->map->toWire()->values()->all();
        if ($this->service->vetForUser($user)) {
            $offer = VetServiceRequestOffer::where('requestId', $req->id)
                ->where('vetId', $this->service->vetForUser($user)->id)->first();
            $data['offer'] = $offer ? [
                'status' => $offer->status,
                'distanceKm' => $offer->distanceKm,
                'notifiedAt' => $offer->notifiedAt?->toIso8601String(),
            ] : null;
        }

        return $this->ok('Request', $data);
    }

    /** POST vet-services/requests/{uuid}/cancel {reason?} */
    public function cancel(Request $request, string $uuid): JsonResponse
    {
        return $this->guard(function () use ($request, $uuid) {
            $req = $this->find($uuid);
            $req = $this->service->cancelByFarmer($request->user(), $req, $request->input('reason'));

            return $this->ok('Request cancelled', $this->wire($req->load('vet'), $request->user()));
        });
    }

    /** POST vet-services/requests/{uuid}/retry-dispatch — widen the search. */
    public function retryDispatch(Request $request, string $uuid): JsonResponse
    {
        return $this->guard(function () use ($request, $uuid) {
            $req = $this->find($uuid);
            $created = $this->service->retryDispatch($request->user(), $req);
            $req->refresh()->load('vet');

            return $this->ok(
                $created > 0 ? "Sent to {$created} more veterinarian(s)." : 'No additional veterinarians found yet.',
                $this->wire($req, $request->user())
            );
        });
    }

    /** POST vet-services/requests/{uuid}/rate {rating, review?, tags?} */
    public function rate(Request $request, string $uuid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rating' => 'required|integer|min:1|max:5',
            'review' => 'nullable|string|max:1000',
            'tags' => 'nullable|array|max:6',
            'tags.*' => 'string|max:40',
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        return $this->guard(function () use ($request, $uuid) {
            $req = $this->find($uuid);
            $req = $this->service->rate(
                $request->user(), $req, (int) $request->rating, $request->input('review'), $request->input('tags', [])
            );

            return $this->ok('Thank you for rating this service', $this->wire($req->load('vet'), $request->user()));
        });
    }

    /**
     * GET vet-services/requests/feed?since=ISO8601
     *
     * Delta feed for both roles: everything the viewer is involved in that changed
     * after `since`, plus pending offers for vets. Designed to be polled cheaply.
     */
    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();
        $since = $request->query('since') ? Carbon::parse($request->query('since'))->subSecond() : null;
        $vet = $this->service->vetForUser($user);

        if (! $vet) {
            $this->service->sweepPending($user->id);
        }

        $q = VetServiceRequest::with(['vet', 'farmer'])
            ->where(function ($w) use ($user, $vet) {
                $w->where('farmerUserId', $user->id);
                if ($vet) {
                    $w->orWhere('vetId', $vet->id)
                        ->orWhereIn('id', VetServiceRequestOffer::where('vetId', $vet->id)
                            ->where('status', VetServiceRequestOffer::OFFERED)
                            ->select('requestId'));
                }
            });
        if ($since) {
            $q->where(function ($w) use ($since) {
                $w->where('lastActivityAt', '>', $since)->orWhere('updated_at', '>', $since);
            });
        }
        $items = $q->orderByDesc('lastActivityAt')->limit(100)->get();

        return $this->ok('Feed', [
            'requests' => $this->wireMany($items, $user),
            'serverTime' => now()->toIso8601String(),
            'vetAvailable' => $vet?->isAvailable,
        ]);
    }

    /** GET vet-services/requests/{uuid}/events */
    public function events(Request $request, string $uuid): JsonResponse
    {
        $req = $this->find($uuid);
        if (! $this->service->canView($request->user(), $req)) {
            return $this->fail('Request not found', 404, 'NOT_FOUND');
        }

        return $this->ok('Timeline', $req->events->map->toWire()->values()->all());
    }

    // ==================================================================
    // Vet side
    // ==================================================================

    /** GET vet-services/vets/me/inbox — open offers for this vet, nearest first. */
    public function inbox(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $user = $request->user();
            $vet = $this->service->requireVet($user);
            $this->service->sweepPending();

            $offers = VetServiceRequestOffer::with(['request.farmer', 'request.vet'])
                ->where('vetId', $vet->id)
                ->where('status', VetServiceRequestOffer::OFFERED)
                ->whereHas('request', fn ($q) => $q->where('status', Status::PENDING))
                ->orderBy('distanceKm')
                ->limit(50)
                ->get();

            $items = $offers->map(function (VetServiceRequestOffer $offer) use ($user) {
                $data = $this->wire($offer->request, $user);
                $data['offer'] = [
                    'status' => $offer->status,
                    'distanceKm' => $offer->distanceKm,
                    'round' => $offer->round,
                    'notifiedAt' => $offer->notifiedAt?->toIso8601String(),
                ];

                return $data;
            })->values()->all();

            return $this->ok('Inbox', [
                'requests' => $items,
                'isVerified' => $vet->isVerified,
                'verificationStatus' => $vet->verificationStatus,
                'isAvailable' => (bool) $vet->isAvailable,
                'serverTime' => now()->toIso8601String(),
            ]);
        });
    }

    /** GET vet-services/vets/me/jobs?status=active|history */
    public function jobs(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $user = $request->user();
            $vet = $this->service->requireVet($user);
            $scope = $request->query('status', 'active');

            $q = VetServiceRequest::with(['vet', 'farmer'])->where('vetId', $vet->id);
            $scope === 'history' ? $q->whereIn('status', Status::terminal()) : $q->active();
            $items = $q->orderByDesc('lastActivityAt')->limit(100)->get();

            return $this->ok('Jobs', [
                'requests' => $this->wireMany($items, $user),
                'stats' => [
                    'active' => VetServiceRequest::where('vetId', $vet->id)->active()->count(),
                    'completed' => (int) $vet->completedJobsCount,
                    'ratingAverage' => (float) $vet->ratingAverage,
                    'ratingCount' => (int) $vet->ratingCount,
                ],
                'serverTime' => now()->toIso8601String(),
            ]);
        });
    }

    public function accept(Request $request, string $uuid): JsonResponse
    {
        return $this->guard(function () use ($request, $uuid) {
            $req = $this->service->accept($request->user(), $this->find($uuid));

            return $this->ok('Request accepted. The farmer has been notified.', $this->wire($req->load('farmer'), $request->user()));
        });
    }

    public function decline(Request $request, string $uuid): JsonResponse
    {
        return $this->guard(function () use ($request, $uuid) {
            $this->service->decline($request->user(), $this->find($uuid), $request->input('reason'));

            return $this->ok('Request declined', ['uuid' => $uuid]);
        });
    }

    public function withdraw(Request $request, string $uuid): JsonResponse
    {
        return $this->guard(function () use ($request, $uuid) {
            $req = $this->service->withdraw($request->user(), $this->find($uuid), $request->input('reason'));

            return $this->ok('You withdrew from this request', $this->wire($req, $request->user()));
        });
    }

    /** POST vet-services/requests/{uuid}/advance {status, expectedVersion?} */
    public function advance(Request $request, string $uuid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:'.implode(',', array_values(Status::vetProgression())),
            'expectedVersion' => 'nullable|integer',
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        return $this->guard(function () use ($request, $uuid) {
            $req = $this->service->advance(
                $request->user(),
                $this->find($uuid),
                $request->input('status'),
                $request->filled('expectedVersion') ? (int) $request->expectedVersion : null,
            );

            return $this->ok('Status updated', $this->wire($req->load(['vet', 'farmer']), $request->user()));
        });
    }

    /** POST vet-services/requests/{uuid}/treatment */
    public function recordTreatment(Request $request, string $uuid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'nullable|string|max:100',
            'diagnosis' => 'nullable|string|max:500',
            'diseaseId' => 'nullable|integer|exists:diseases,id',
            'diseaseName' => 'nullable|string|max:255',
            'medicineId' => 'nullable|integer|exists:medicines,id',
            'medicineName' => 'nullable|string|max:255',
            'quantity' => 'nullable|string|max:100',
            'withdrawalPeriod' => 'nullable|string|max:100',
            'medicationDate' => 'nullable|date',
            'nextMedicationDate' => 'nullable|date',
            'remarks' => 'nullable|string|max:2000',
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        return $this->guard(function () use ($request, $uuid, $validator) {
            $req = $this->service->recordTreatment($request->user(), $this->find($uuid), $validator->validated());

            return $this->ok('Treatment recorded on the animal profile', $this->wire($req->load(['vet', 'farmer']), $request->user()));
        });
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function find(string $uuid): VetServiceRequest
    {
        return VetServiceRequest::where('uuid', $uuid)->firstOrFail();
    }

    private function wire(VetServiceRequest $req, User $viewer): array
    {
        return $req->toWire($viewer->id, $this->service->unreadCountFor($viewer, $req));
    }

    /** @param \Illuminate\Support\Collection<int, VetServiceRequest> $items */
    private function wireMany($items, User $viewer): array
    {
        if ($items->isEmpty()) {
            return [];
        }
        // One grouped query for unread counts instead of one per request.
        $unread = \App\Models\VetServiceMessage::whereIn('requestId', $items->pluck('id'))
            ->where('senderUserId', '!=', $viewer->id)
            ->whereNull('readAt')
            ->selectRaw('requestId, COUNT(*) as c')
            ->groupBy('requestId')
            ->pluck('c', 'requestId');

        return $items->map(fn (VetServiceRequest $r) => $r->toWire($viewer->id, (int) ($unread[$r->id] ?? 0)))->values()->all();
    }
}
