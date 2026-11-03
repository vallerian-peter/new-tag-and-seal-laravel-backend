<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Controllers\VetServices\Concerns\RespondsVetServices;
use App\Models\AuctionCentre;
use App\Models\AuctionCentreMember;
use App\Models\AuctionSession;
use App\Models\User;
use App\Services\Auction\AuctionCentreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Auction centres for the app (my centres, tag lookup) and the admin portal
 * (centre CRUD and member management).
 */
class AuctionCentreController extends Controller
{
    use RespondsVetServices;

    public function __construct(private readonly AuctionCentreService $centres)
    {
    }

    // ------------------------------------------------------------------
    // App
    // ------------------------------------------------------------------

    /** GET auctions/centres — active centres, for choosing where to apply. */
    public function index(Request $request): JsonResponse
    {
        $q = AuctionCentre::active()->orderBy('name');
        if ($request->filled('q')) {
            $like = '%'.$request->query('q').'%';
            $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like));
        }

        return $this->ok('Auction centres', $q->limit(200)->get()->map(fn (AuctionCentre $c) => $c->summary() + ['regionName' => $c->regionId ? $c->region?->name : null, 'districtName' => $c->districtId ? $c->district?->name : null])->values()->all());
    }

    /** GET auctions/centres/mine — centres the caller is attached to (incl. pending). */
    public function mine(Request $request): JsonResponse
    {
        $memberships = $this->centres->membershipsFor($request->user(), true);

        return $this->ok('My auction centres', $memberships->map(function (AuctionCentreMember $m) {
            $data = $m->centre->toWire($m);
            $data['canHost'] = $m->canHost();

            return $data;
        })->values()->all());
    }

    /** GET auctions/centres/{uuid} */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $centre = AuctionCentre::where('uuid', $uuid)->first();
        if (! $centre) {
            return $this->fail('Auction centre not found', 404, 'NOT_FOUND');
        }
        $membership = $this->centres->membershipFor($request->user(), $centre);
        $data = $centre->toWire($membership, true);
        $data['canHost'] = $this->centres->canHostAt($request->user(), $centre);

        return $this->ok('Auction centre', $data);
    }

    /** GET auctions/centres/{uuid}/livestock?q=TAG — tag lookup across all farms. */
    public function livestock(Request $request, string $uuid): JsonResponse
    {
        $centre = AuctionCentre::where('uuid', $uuid)->first();
        if (! $centre) {
            return $this->fail('Auction centre not found', 404, 'NOT_FOUND');
        }
        if (! $this->centres->canAccess($request->user(), $centre)) {
            return $this->fail('You are not attached to this auction centre.', 403, 'NOT_CENTRE_MEMBER');
        }
        $q = (string) $request->query('q', '');
        $limit = (int) $request->query('limit', 30);

        return $this->ok('Animals', [
            'query' => $q,
            'animals' => $this->centres->searchLivestock($centre, $q, $limit),
        ]);
    }

    /** GET auctions/centres/{uuid}/sessions — recent and upcoming sessions at a centre. */
    public function sessions(Request $request, string $uuid): JsonResponse
    {
        $centre = AuctionCentre::where('uuid', $uuid)->first();
        if (! $centre) {
            return $this->fail('Auction centre not found', 404, 'NOT_FOUND');
        }
        $sessions = AuctionSession::with(['host', 'centre'])
            ->where('centreId', $centre->id)
            ->orderByRaw("FIELD(status, 'live', 'scheduled', 'ended', 'cancelled')")
            ->orderByDesc('scheduledAt')
            ->limit(50)
            ->get();

        return $this->ok('Centre auctions', [
            'centre' => $centre->summary(),
            'sessions' => $sessions->map(fn (AuctionSession $s) => $s->toWire($request->user()->id))->values()->all(),
        ]);
    }

    // ------------------------------------------------------------------
    // Admin portal
    // ------------------------------------------------------------------

    /** GET admin/entities/auction-centres?q=&status= */
    public function adminIndex(Request $request): JsonResponse
    {
        $query = AuctionCentre::query()->with(['region', 'district']);
        if ($request->filled('q')) {
            $like = '%'.$request->query('q').'%';
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        $items = $query->orderBy('name')->limit(min(500, (int) $request->query('limit', 200)))->get();

        return $this->ok('Auction centres', $items->map(fn (AuctionCentre $c) => $c->toWire(null, true))->values()->all());
    }

    /** POST admin/entities/auction-centres */
    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules());
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }
        $centre = AuctionCentre::create(array_merge($validator->validated(), ['createdBy' => $request->user()->id]));

        return $this->ok('Auction centre created', $centre->toWire(null, true), 201);
    }

    /** GET admin/entities/auction-centres/{centre} */
    public function adminShow(string $centre): JsonResponse
    {
        $model = $this->findCentre($centre);
        if (! $model) {
            return $this->fail('Auction centre not found', 404, 'NOT_FOUND');
        }
        $data = $model->toWire(null, true);
        $data['members'] = $model->members()->with('user')->orderBy('role')->get()->map->toWire()->values()->all();

        return $this->ok('Auction centre', $data);
    }

    /** PUT admin/entities/auction-centres/{centre} */
    public function adminUpdate(Request $request, string $centre): JsonResponse
    {
        $model = $this->findCentre($centre);
        if (! $model) {
            return $this->fail('Auction centre not found', 404, 'NOT_FOUND');
        }
        $validator = Validator::make($request->all(), $this->rules($model->id, true));
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }
        $model->fill($validator->validated())->save();

        return $this->ok('Auction centre updated', $model->refresh()->toWire(null, true));
    }

    /** DELETE admin/entities/auction-centres/{centre} — deactivates when sessions exist. */
    public function adminDestroy(string $centre): JsonResponse
    {
        $model = $this->findCentre($centre);
        if (! $model) {
            return $this->fail('Auction centre not found', 404, 'NOT_FOUND');
        }
        if ($model->sessions()->exists()) {
            $model->update(['status' => AuctionCentre::STATUS_INACTIVE]);

            return $this->ok('Auction centre has auctions on record and was deactivated instead of deleted', $model->toWire(null, true));
        }
        $model->delete();

        return $this->ok('Auction centre deleted');
    }

    /** GET admin/entities/auction-centres/{centre}/members */
    public function adminMembers(string $centre): JsonResponse
    {
        $model = $this->findCentre($centre);
        if (! $model) {
            return $this->fail('Auction centre not found', 404, 'NOT_FOUND');
        }

        return $this->ok('Members', $model->members()->with('user')->orderBy('role')->get()->map->toWire()->values()->all());
    }

    /**
     * POST admin/entities/auction-centres/{centre}/members
     * Body: { userId | email | username, role, status? }. Re-adding updates the role.
     */
    public function adminAddMember(Request $request, string $centre): JsonResponse
    {
        $model = $this->findCentre($centre);
        if (! $model) {
            return $this->fail('Auction centre not found', 404, 'NOT_FOUND');
        }
        $validator = Validator::make($request->all(), [
            'userId' => 'nullable|integer',
            'email' => 'nullable|string|max:255',
            'username' => 'nullable|string|max:255',
            'role' => ['required', Rule::in(AuctionCentre::ROLES)],
            'status' => ['nullable', Rule::in([AuctionCentreMember::STATUS_ACTIVE, AuctionCentreMember::STATUS_INACTIVE])],
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->fail('User not found. Provide a userId, email or username of an existing account.', 404, 'USER_NOT_FOUND');
        }
        $member = AuctionCentreMember::updateOrCreate(
            ['centreId' => $model->id, 'userId' => $user->id],
            [
                'role' => $request->input('role'),
                'status' => $request->input('status', AuctionCentreMember::STATUS_ACTIVE),
                'assignedBy' => $request->user()->id,
            ],
        );

        return $this->ok('Member saved', $member->load('user')->toWire(), 201);
    }

    /** PUT admin/entities/auction-centres/{centre}/members/{member} */
    public function adminUpdateMember(Request $request, string $centre, int $member): JsonResponse
    {
        $model = $this->findCentre($centre);
        $row = $model ? AuctionCentreMember::where('centreId', $model->id)->find($member) : null;
        if (! $row) {
            return $this->fail('Member not found', 404, 'NOT_FOUND');
        }
        $validator = Validator::make($request->all(), [
            'role' => ['nullable', Rule::in(AuctionCentre::ROLES)],
            'status' => ['nullable', Rule::in([AuctionCentreMember::STATUS_ACTIVE, AuctionCentreMember::STATUS_INACTIVE])],
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }
        $row->fill(array_filter($validator->validated(), fn ($v) => $v !== null))->save();

        return $this->ok('Member updated', $row->load('user')->toWire());
    }

    /** DELETE admin/entities/auction-centres/{centre}/members/{member} */
    public function adminRemoveMember(string $centre, int $member): JsonResponse
    {
        $model = $this->findCentre($centre);
        $row = $model ? AuctionCentreMember::where('centreId', $model->id)->find($member) : null;
        if (! $row) {
            return $this->fail('Member not found', 404, 'NOT_FOUND');
        }
        $row->delete();

        return $this->ok('Member removed');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function rules(?int $ignoreId = null, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'code' => [$req, 'string', 'max:30', Rule::unique('auction_centres', 'code')->ignore($ignoreId)],
            'name' => [$req, 'string', 'min:2', 'max:150'],
            'description' => 'nullable|string|max:2000',
            'regionId' => 'nullable|integer',
            'districtId' => 'nullable|integer',
            'wardId' => 'nullable|integer',
            'address' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'phone' => 'nullable|string|max:30',
            'status' => ['nullable', Rule::in([AuctionCentre::STATUS_ACTIVE, AuctionCentre::STATUS_INACTIVE])],
        ];
    }

    private function findCentre(string $key): ?AuctionCentre
    {
        return ctype_digit($key)
            ? AuctionCentre::find((int) $key)
            : AuctionCentre::where('uuid', $key)->orWhere('code', strtoupper($key))->first();
    }

    private function resolveUser(Request $request): ?User
    {
        if ($request->filled('userId')) {
            return User::find((int) $request->input('userId'));
        }
        if ($request->filled('email')) {
            return User::where('email', $request->input('email'))->first();
        }
        if ($request->filled('username')) {
            return User::where('username', $request->input('username'))->first();
        }

        return null;
    }
}
