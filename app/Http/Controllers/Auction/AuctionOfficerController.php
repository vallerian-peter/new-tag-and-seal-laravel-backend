<?php

namespace App\Http\Controllers\Auction;

use App\Http\Controllers\Controller;
use App\Http\Controllers\VetServices\Concerns\RespondsVetServices;
use App\Models\AuctionCentre;
use App\Models\AuctionOfficer;
use App\Services\Auction\AuctionCentreService;
use App\Services\Auction\AuctionOfficerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Auction officers: self-service for the app and management for the portal.
 */
class AuctionOfficerController extends Controller
{
    use RespondsVetServices;

    public function __construct(
        private readonly AuctionOfficerService $officers,
        private readonly AuctionCentreService $centres,
    ) {
    }

    // ------------------------------------------------------------------
    // App
    // ------------------------------------------------------------------

    /** GET auctions/officers/me */
    public function me(Request $request): JsonResponse
    {
        $officer = $this->officers->officerForUser($request->user());
        if (! $officer) {
            return $this->fail('No auction officer profile for this account.', 404, 'NO_OFFICER_PROFILE');
        }

        return $this->ok('Officer profile', $officer->toWire(true));
    }

    /** POST auctions/officers/apply — any signed-in account may apply once. */
    public function apply(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'centreUuid' => 'required|string|max:100',
            'firstName' => 'required|string|max:100',
            'middleName' => 'nullable|string|max:100',
            'lastName' => 'required|string|max:100',
            'phone' => 'required|string|max:30',
            'email' => 'nullable|email|max:255',
            'nationalId' => 'nullable|string|max:50',
            'licenceNo' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:255',
            'regionId' => 'nullable|integer',
            'districtId' => 'nullable|integer',
            'bio' => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        return $this->guard(function () use ($request, $validator) {
            $officer = $this->officers->apply($request->user(), $validator->validated());

            return $this->ok('Application submitted. An administrator will review it before you can conduct auctions.', $officer->toWire(true), 201);
        });
    }

    /** PUT auctions/officers/me — contact details only; verification fields are the portal's. */
    public function update(Request $request): JsonResponse
    {
        $officer = $this->officers->officerForUser($request->user());
        if (! $officer) {
            return $this->fail('No auction officer profile for this account.', 404, 'NO_OFFICER_PROFILE');
        }
        $validator = Validator::make($request->all(), [
            'phone' => 'sometimes|string|max:30',
            'address' => 'sometimes|nullable|string|max:255',
            'licenceNo' => 'sometimes|nullable|string|max:100',
            'nationalId' => 'sometimes|nullable|string|max:50',
            'bio' => 'sometimes|nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }
        $officer->fill($validator->validated())->save();

        return $this->ok('Profile updated', $officer->refresh()->toWire(true));
    }

    // ------------------------------------------------------------------
    // Admin portal
    // ------------------------------------------------------------------

    /** GET admin/entities/auction-officers?verificationStatus=&status=&centreUuid=&q= */
    public function adminIndex(Request $request): JsonResponse
    {
        $q = AuctionOfficer::query()->with(['user', 'requestedCentre']);
        if ($request->filled('verificationStatus')) {
            $q->where('verificationStatus', $request->query('verificationStatus'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }
        if ($request->filled('centreUuid')) {
            $centre = AuctionCentre::where('uuid', $request->query('centreUuid'))->first();
            $userIds = $centre ? $centre->members()->pluck('userId')->all() : [];
            $q->where(fn ($w) => $w->whereIn('userId', $userIds)->orWhere('requestedCentreId', $centre?->id ?? 0));
        }
        if ($request->filled('q')) {
            $like = '%'.$request->query('q').'%';
            $q->where(fn ($w) => $w->where('firstName', 'like', $like)->orWhere('lastName', 'like', $like)
                ->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like)->orWhere('officerNo', 'like', $like));
        }
        $items = $q->orderByRaw("FIELD(verificationStatus, 'pending', 'verified', 'rejected')")
            ->orderByDesc('created_at')
            ->limit(min(500, (int) $request->query('limit', 200)))
            ->get();

        return $this->ok('Auction officers', [
            'officers' => $items->map(fn (AuctionOfficer $o) => $o->toWire(true))->values()->all(),
            'pendingCount' => AuctionOfficer::pending()->count(),
        ]);
    }

    /** POST admin/entities/auction-officers — create a verified officer with an account. */
    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'centreUuid' => 'required|string|max:100',
            'role' => ['nullable', Rule::in(AuctionCentre::ROLES)],
            'firstName' => 'required|string|max:100',
            'middleName' => 'nullable|string|max:100',
            'lastName' => 'required|string|max:100',
            'phone' => 'required|string|max:30',
            'email' => 'required|email|max:255',
            'username' => 'nullable|string|max:100',
            'password' => 'nullable|string|min:6|max:100',
            'nationalId' => 'nullable|string|max:50',
            'licenceNo' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:255',
            'regionId' => 'nullable|integer',
            'districtId' => 'nullable|integer',
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        return $this->guard(function () use ($request, $validator) {
            $officer = $this->officers->createByAdmin($request->user(), $validator->validated());

            return $this->ok('Auction officer created. Login details were sent by SMS.', $officer->toWire(true), 201);
        });
    }

    /** GET admin/entities/auction-officers/{officer} */
    public function adminShow(string $officer): JsonResponse
    {
        $model = $this->find($officer);
        if (! $model) {
            return $this->fail('Auction officer not found', 404, 'NOT_FOUND');
        }

        return $this->ok('Auction officer', $model->toWire(true));
    }

    /** PUT admin/entities/auction-officers/{officer} */
    public function adminUpdate(Request $request, string $officer): JsonResponse
    {
        $model = $this->find($officer);
        if (! $model) {
            return $this->fail('Auction officer not found', 404, 'NOT_FOUND');
        }
        $validator = Validator::make($request->all(), [
            'firstName' => 'sometimes|string|max:100',
            'middleName' => 'sometimes|nullable|string|max:100',
            'lastName' => 'sometimes|string|max:100',
            'phone' => 'sometimes|string|max:30',
            'email' => 'sometimes|nullable|email|max:255',
            'nationalId' => 'sometimes|nullable|string|max:50',
            'licenceNo' => 'sometimes|nullable|string|max:100',
            'address' => 'sometimes|nullable|string|max:255',
            'regionId' => 'sometimes|nullable|integer',
            'districtId' => 'sometimes|nullable|integer',
            'bio' => 'sometimes|nullable|string|max:1000',
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }
        $data = $validator->validated();
        if (($data['status'] ?? null) === 'inactive') {
            $this->officers->deactivate($model);
            unset($data['status']);
        }
        $model->fill($data)->save();

        return $this->ok('Auction officer updated', $model->refresh()->toWire(true));
    }

    /**
     * POST admin/entities/auction-officers/{officer}/verify
     * Body: { decision: verified|rejected, notes?, centreUuid?, role? }
     */
    public function adminVerify(Request $request, string $officer): JsonResponse
    {
        $model = $this->find($officer);
        if (! $model) {
            return $this->fail('Auction officer not found', 404, 'NOT_FOUND');
        }
        $validator = Validator::make($request->all(), [
            'decision' => ['required', Rule::in([AuctionOfficer::VERIFIED, AuctionOfficer::REJECTED])],
            'notes' => 'nullable|string|max:1000',
            'centreUuid' => 'nullable|string|max:100',
            'role' => ['nullable', Rule::in(AuctionCentre::ROLES)],
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }
        $centre = $request->filled('centreUuid') ? AuctionCentre::where('uuid', $request->input('centreUuid'))->first() : null;
        if ($request->filled('centreUuid') && ! $centre) {
            return $this->fail('Auction centre not found', 404, 'CENTRE_NOT_FOUND');
        }

        return $this->guard(function () use ($request, $model, $centre) {
            $updated = $this->officers->verify(
                $model,
                $request->user(),
                $request->input('decision'),
                $request->input('notes'),
                $centre,
                $request->input('role'),
            );

            return $this->ok(
                $updated->verificationStatus === AuctionOfficer::VERIFIED ? 'Auction officer verified' : 'Auction officer rejected',
                $updated->toWire(true),
            );
        });
    }

    /** DELETE admin/entities/auction-officers/{officer} — deactivates rather than deletes. */
    public function adminDestroy(string $officer): JsonResponse
    {
        $model = $this->find($officer);
        if (! $model) {
            return $this->fail('Auction officer not found', 404, 'NOT_FOUND');
        }
        $this->officers->deactivate($model);

        return $this->ok('Auction officer deactivated', $model->refresh()->toWire(true));
    }

    private function find(string $key): ?AuctionOfficer
    {
        return ctype_digit($key)
            ? AuctionOfficer::find((int) $key)
            : AuctionOfficer::where('uuid', $key)->orWhere('officerNo', strtoupper($key))->first();
    }
}
