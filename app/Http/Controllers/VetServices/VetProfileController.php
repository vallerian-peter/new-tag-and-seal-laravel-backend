<?php

namespace App\Http\Controllers\VetServices;

use App\Enums\VetSpecialization;
use App\Enums\VetVerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\VetServices\Concerns\RespondsVetServices;
use App\Models\Vet;
use App\Services\VetServices\VetDispatchService;
use App\Services\VetServices\VetServiceRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Veterinarian profile: self-service application, availability, live location,
 * and the farmer-facing "nearby vets" lookup.
 */
class VetProfileController extends Controller
{
    use RespondsVetServices;

    public function __construct(
        private readonly VetServiceRequestService $service,
        private readonly VetDispatchService $dispatcher,
    ) {
    }

    /** GET vet-services/vets/me */
    public function me(Request $request): JsonResponse
    {
        $vet = $this->service->vetForUser($request->user());
        if (! $vet) {
            return $this->fail('No veterinarian profile for this account.', 404, 'NO_VET_PROFILE');
        }

        return $this->ok('Vet profile retrieved', $this->privateProfile($vet));
    }

    /** POST vet-services/vets/apply — any signed-in account may apply once. */
    public function apply(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($this->service->vetForUser($user)) {
            return $this->fail('You already have a veterinarian profile.', 409, 'ALREADY_APPLIED');
        }

        $validator = Validator::make($request->all(), [
            'fullName' => 'required|string|max:255',
            'phoneNumber' => 'required|string|max:30',
            'email' => 'nullable|email|max:255',
            'medicalLicenseNo' => 'required|string|max:100',
            'licenseAuthority' => 'nullable|string|max:255',
            'licenseExpiryDate' => 'nullable|date',
            'specializations' => 'required|array|min:1',
            'specializations.*' => 'string|in:'.implode(',', VetSpecialization::all()),
            'yearsOfExperience' => 'nullable|integer|min:0|max:60',
            'clinicName' => 'nullable|string|max:255',
            'bio' => 'nullable|string|max:1000',
            'address' => 'nullable|string|max:255',
            'countryId' => 'nullable|integer|exists:countries,id',
            'regionId' => 'nullable|integer|exists:regions,id',
            'districtId' => 'nullable|integer|exists:districts,id',
            'serviceAreaDistrictIds' => 'nullable|array',
            'serviceAreaDistrictIds.*' => 'integer',
            'serviceRadiusKm' => 'nullable|integer|min:5|max:'.VetDispatchService::MAX_RADIUS_KM,
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'gender' => 'nullable|in:male,female',
            'dateOfBirth' => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        $data = $validator->validated();
        $email = $data['email'] ?? $user->email;
        if ($email && Vet::where('email', $email)->exists()) {
            return $this->fail('A veterinarian with this email already exists.', 409, 'EMAIL_TAKEN');
        }

        $vet = Vet::create(array_merge($data, [
            'uuid' => (string) Str::uuid(),
            'userId' => $user->id,
            'referenceNo' => 'VET-'.strtoupper(Str::random(6)),
            'email' => $email,
            'status' => 'active',
            'verificationStatus' => VetVerificationStatus::PENDING,
            'isAvailable' => false,
            'locationUpdatedAt' => isset($data['latitude']) ? now() : null,
        ]));

        return $this->ok('Application submitted. An administrator will verify your credentials.', $this->privateProfile($vet), 201);
    }

    /** PUT vet-services/vets/me */
    public function update(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $vet = $this->service->requireVet($request->user());

            $validator = Validator::make($request->all(), [
                'fullName' => 'sometimes|string|max:255',
                'phoneNumber' => 'sometimes|string|max:30',
                'medicalLicenseNo' => 'sometimes|string|max:100',
                'licenseAuthority' => 'sometimes|nullable|string|max:255',
                'licenseExpiryDate' => 'sometimes|nullable|date',
                'specializations' => 'sometimes|array|min:1',
                'specializations.*' => 'string|in:'.implode(',', VetSpecialization::all()),
                'yearsOfExperience' => 'sometimes|nullable|integer|min:0|max:60',
                'clinicName' => 'sometimes|nullable|string|max:255',
                'bio' => 'sometimes|nullable|string|max:1000',
                'address' => 'sometimes|nullable|string|max:255',
                'regionId' => 'sometimes|nullable|integer|exists:regions,id',
                'districtId' => 'sometimes|nullable|integer|exists:districts,id',
                'serviceAreaDistrictIds' => 'sometimes|nullable|array',
                'serviceRadiusKm' => 'sometimes|integer|min:5|max:'.VetDispatchService::MAX_RADIUS_KM,
            ]);
            if ($validator->fails()) {
                return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
            }

            $data = $validator->validated();
            // Changing licence details re-opens verification.
            $credentialKeys = ['medicalLicenseNo', 'licenseAuthority', 'licenseExpiryDate'];
            $credentialsChanged = collect($credentialKeys)
                ->contains(fn ($k) => array_key_exists($k, $data) && $data[$k] != $vet->{$k});
            if ($credentialsChanged && $vet->verificationStatus === VetVerificationStatus::VERIFIED) {
                $data['verificationStatus'] = VetVerificationStatus::PENDING;
                $data['verifiedAt'] = null;
                $data['verifiedBy'] = null;
                $data['isAvailable'] = false;
            }

            $vet->fill($data)->save();

            return $this->ok('Profile updated', $this->privateProfile($vet->refresh()));
        });
    }

    /** POST vet-services/vets/me/availability  {isAvailable, latitude?, longitude?} */
    public function setAvailability(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $vet = $this->service->requireVet($request->user());
            $validator = Validator::make($request->all(), [
                'isAvailable' => 'required|boolean',
                'latitude' => 'nullable|numeric|between:-90,90',
                'longitude' => 'nullable|numeric|between:-180,180',
            ]);
            if ($validator->fails()) {
                return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
            }

            $wantsOnline = (bool) $request->boolean('isAvailable');
            if ($wantsOnline && ! $vet->isVerified) {
                return $this->fail('You can go online once your credentials are verified.', 403, 'VET_NOT_VERIFIED');
            }

            $vet->isAvailable = $wantsOnline;
            if ($request->filled('latitude') && $request->filled('longitude')) {
                $vet->latitude = (float) $request->latitude;
                $vet->longitude = (float) $request->longitude;
                $vet->locationUpdatedAt = now();
            }
            if ($wantsOnline && ! $vet->hasLocation) {
                return $this->fail('Share your location to go online so farmers nearby can find you.', 422, 'LOCATION_REQUIRED');
            }
            $vet->save();

            return $this->ok($wantsOnline ? 'You are now online' : 'You are now offline', $this->privateProfile($vet));
        });
    }

    /** POST vet-services/vets/me/location  {latitude, longitude} — cheap, called periodically while online. */
    public function updateLocation(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $vet = $this->service->requireVet($request->user());
            $validator = Validator::make($request->all(), [
                'latitude' => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180',
            ]);
            if ($validator->fails()) {
                return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
            }

            Vet::where('id', $vet->id)->update([
                'latitude' => (float) $request->latitude,
                'longitude' => (float) $request->longitude,
                'locationUpdatedAt' => now(),
            ]);

            return $this->ok('Location updated', ['locationUpdatedAt' => now()->toIso8601String()]);
        });
    }

    /** POST vet-services/vets/me/license-document (multipart: document) */
    public function uploadLicenseDocument(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $vet = $this->service->requireVet($request->user());
            $validator = Validator::make($request->all(), [
                'document' => 'required|file|mimes:jpg,jpeg,png,pdf|max:8192',
            ]);
            if ($validator->fails()) {
                return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
            }

            $path = $request->file('document')->store("vet-licenses/{$vet->id}", 'public');
            $vet->licenseDocumentPath = $path;
            if ($vet->verificationStatus === VetVerificationStatus::REJECTED) {
                $vet->verificationStatus = VetVerificationStatus::PENDING;
            }
            $vet->save();

            return $this->ok('Licence document uploaded', $this->privateProfile($vet));
        });
    }

    /** GET vet-services/vets/nearby?latitude&longitude&radiusKm — farmer-facing. */
    public function nearby(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radiusKm' => 'nullable|numeric|min:1|max:'.VetDispatchService::MAX_RADIUS_KM,
            'limit' => 'nullable|integer|min:1|max:50',
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        $vets = $this->dispatcher->nearby(
            (float) $request->latitude,
            (float) $request->longitude,
            (float) ($request->radiusKm ?? 50),
            (int) ($request->limit ?? 30),
        );

        return $this->ok('Nearby veterinarians', [
            'vets' => $vets->map(fn (Vet $v) => $v->toPublicCard())->values()->all(),
            'availableCount' => $vets->where('isAvailable', true)->count(),
            'radiusKm' => (float) ($request->radiusKm ?? 50),
        ]);
    }

    /** GET vet-services/vets/{uuid} — public card with recent reviews. */
    public function show(string $uuid): JsonResponse
    {
        $vet = Vet::verified()->where('uuid', $uuid)->first();
        if (! $vet) {
            return $this->fail('Veterinarian not found', 404, 'NOT_FOUND');
        }
        $reviews = $vet->ratings()->latest()->limit(10)->get()->map(fn ($r) => [
            'rating' => $r->rating,
            'review' => $r->review,
            'tags' => $r->tags ?? [],
            'createdAt' => $r->created_at?->toIso8601String(),
        ])->values();

        return $this->ok('Veterinarian', array_merge($vet->toPublicCard(), ['reviews' => $reviews]));
    }

    public static function privateProfileFor(Vet $vet): array
    {
        return array_merge($vet->toPublicCard(), [
            'userId' => $vet->userId,
            'referenceNo' => $vet->referenceNo,
            'email' => $vet->email,
            'address' => $vet->address,
            'medicalLicenseNo' => $vet->medicalLicenseNo,
            'licenseAuthority' => $vet->licenseAuthority,
            'licenseExpiryDate' => optional($vet->licenseExpiryDate)->toDateString(),
            'hasLicenseDocument' => ! empty($vet->licenseDocumentPath),
            'regionId' => $vet->regionId,
            'districtId' => $vet->districtId,
            'serviceAreaDistrictIds' => $vet->serviceAreaDistrictIds ?? [],
            'serviceRadiusKm' => $vet->serviceRadiusKm,
            'verificationStatus' => $vet->verificationStatus,
            'verificationNotes' => $vet->verificationNotes,
            'verifiedAt' => optional($vet->verifiedAt)->toIso8601String(),
            'status' => $vet->status,
            'createdAt' => $vet->created_at?->toIso8601String(),
        ]);
    }

    private function privateProfile(Vet $vet): array
    {
        return self::privateProfileFor($vet);
    }
}
