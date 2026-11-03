<?php

namespace App\Http\Controllers\Vet;

use App\Enums\VetServiceRequestStatus;
use App\Enums\VetVerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\VetServices\VetProfileController;
use App\Models\Vet;
use App\Models\VetServiceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Admin (SystemUser-only) management of veterinarians: CRUD, credential
 * verification, and oversight of service requests.
 */
class VetController extends Controller
{
    private const RELATIONS = ['country', 'region', 'district', 'user:id,username,email,role,status'];

    // ============================================================================
    // Admin CRUD
    // ============================================================================

    /** GET admin/entities/vets?verificationStatus=pending|verified|rejected&search= */
    public function adminIndex(Request $request): JsonResponse
    {
        $q = Vet::with(self::RELATIONS)->withCount([
            'serviceRequests as completedRequestsCount' => fn ($r) => $r->where('status', VetServiceRequestStatus::COMPLETED),
        ]);

        if ($request->filled('verificationStatus')) {
            $q->where('verificationStatus', $request->verificationStatus);
        }
        if ($request->filled('search')) {
            $s = '%'.$request->search.'%';
            $q->where(fn ($w) => $w->where('fullName', 'like', $s)
                ->orWhere('email', 'like', $s)
                ->orWhere('phoneNumber', 'like', $s)
                ->orWhere('medicalLicenseNo', 'like', $s)
                ->orWhere('referenceNo', 'like', $s));
        }

        $vets = $q->orderByRaw("FIELD(verificationStatus, 'pending', 'verified', 'rejected')")
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Vets retrieved successfully',
            'data' => $vets->map(fn (Vet $v) => $this->adminRow($v))->values(),
            'meta' => [
                'pending' => Vet::where('verificationStatus', VetVerificationStatus::PENDING)->count(),
                'verified' => Vet::where('verificationStatus', VetVerificationStatus::VERIFIED)->count(),
                'rejected' => Vet::where('verificationStatus', VetVerificationStatus::REJECTED)->count(),
            ],
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'referenceNo' => 'nullable|string|max:255|unique:vets,referenceNo',
            'medicalLicenseNo' => 'nullable|string|max:255',
            'licenseAuthority' => 'nullable|string|max:255',
            'licenseExpiryDate' => 'nullable|date',
            'fullName' => 'required_without_all:firstName,lastName|string|max:255',
            'firstName' => 'nullable|string|max:255',
            'middleName' => 'nullable|string|max:255',
            'lastName' => 'nullable|string|max:255',
            'phoneNumber' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255|unique:vets,email',
            'address' => 'nullable|string',
            'countryId' => 'nullable|integer|exists:countries,id',
            'regionId' => 'nullable|integer|exists:regions,id',
            'districtId' => 'nullable|integer|exists:districts,id',
            'gender' => 'nullable|in:male,female',
            'dateOfBirth' => 'nullable|date',
            'identityCardTypeId' => 'nullable|integer|exists:identity_card_types,id',
            'identityNo' => 'nullable|string|max:255',
            'specializations' => 'nullable|array',
            'serviceRadiusKm' => 'nullable|integer|min:5|max:150',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'status' => 'nullable|string|in:active,notActive',
            'verificationStatus' => 'nullable|in:'.implode(',', VetVerificationStatus::all()),
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        if (empty($data['fullName'])) {
            $data['fullName'] = trim(collect([$request->firstName, $request->middleName, $request->lastName])->filter()->implode(' '));
        }
        unset($data['firstName'], $data['middleName'], $data['lastName']);
        $data['uuid'] = (string) Str::uuid();
        $data['referenceNo'] = $data['referenceNo'] ?? 'VET-'.strtoupper(Str::random(6));
        $data['status'] = $data['status'] ?? 'active';
        if (($data['verificationStatus'] ?? null) === VetVerificationStatus::VERIFIED) {
            $data['verifiedAt'] = now();
            $data['verifiedBy'] = $request->user()->id;
        }
        if (isset($data['latitude'], $data['longitude'])) {
            $data['locationUpdatedAt'] = now();
        }

        $vet = Vet::create($data)->load(self::RELATIONS);

        return response()->json(['status' => true, 'message' => 'Vet created successfully', 'data' => $this->adminRow($vet)], 201);
    }

    public function adminShow(Vet $vet): JsonResponse
    {
        $vet->load(self::RELATIONS);
        $row = $this->adminRow($vet);
        $row['recentRequests'] = VetServiceRequest::where('vetId', $vet->id)
            ->orderByDesc('id')->limit(10)->get()->map->toWire()->values();
        $row['ratings'] = $vet->ratings()->latest()->limit(10)->get();

        return response()->json(['status' => true, 'message' => 'Vet retrieved successfully', 'data' => $row], 200);
    }

    public function adminUpdate(Request $request, Vet $vet): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'referenceNo' => 'sometimes|nullable|string|max:255|unique:vets,referenceNo,'.$vet->id,
            'medicalLicenseNo' => 'sometimes|nullable|string|max:255',
            'licenseAuthority' => 'sometimes|nullable|string|max:255',
            'licenseExpiryDate' => 'sometimes|nullable|date',
            'fullName' => 'sometimes|required|string|max:255',
            'phoneNumber' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|nullable|email|max:255|unique:vets,email,'.$vet->id,
            'address' => 'sometimes|nullable|string',
            'countryId' => 'sometimes|nullable|integer|exists:countries,id',
            'regionId' => 'sometimes|nullable|integer|exists:regions,id',
            'districtId' => 'sometimes|nullable|integer|exists:districts,id',
            'gender' => 'sometimes|nullable|in:male,female',
            'dateOfBirth' => 'sometimes|nullable|date',
            'identityCardTypeId' => 'sometimes|nullable|integer|exists:identity_card_types,id',
            'identityNo' => 'sometimes|nullable|string|max:255',
            'specializations' => 'sometimes|nullable|array',
            'serviceRadiusKm' => 'sometimes|integer|min:5|max:150',
            'isAvailable' => 'sometimes|boolean',
            'status' => 'sometimes|nullable|string|in:active,notActive',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $vet->fill($validator->validated())->save();
        $vet->load(self::RELATIONS);

        return response()->json(['status' => true, 'message' => 'Vet updated successfully', 'data' => $this->adminRow($vet)], 200);
    }

    public function adminDestroy(Vet $vet): JsonResponse
    {
        $vet->delete();

        return response()->json(['status' => true, 'message' => 'Vet deleted successfully'], 200);
    }

    // ============================================================================
    // Credential verification
    // ============================================================================

    /** POST admin/entities/vets/{vet}/verify {decision: verified|rejected, notes?} */
    public function adminVerify(Request $request, Vet $vet): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'decision' => 'required|in:'.VetVerificationStatus::VERIFIED.','.VetVerificationStatus::REJECTED,
            'notes' => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $approved = $request->decision === VetVerificationStatus::VERIFIED;
        $vet->verificationStatus = $request->decision;
        $vet->verificationNotes = $request->input('notes');
        $vet->verifiedAt = $approved ? now() : null;
        $vet->verifiedBy = $approved ? $request->user()->id : null;
        if (! $approved) {
            $vet->isAvailable = false;
        }
        $vet->save();

        return response()->json([
            'status' => true,
            'message' => $approved ? 'Veterinarian verified' : 'Veterinarian rejected',
            'data' => $this->adminRow($vet->load(self::RELATIONS)),
        ], 200);
    }

    /** GET admin/entities/vets/{vet}/license-document — streams the uploaded credential. */
    public function adminLicenseDocument(Vet $vet)
    {
        if (! $vet->licenseDocumentPath || ! Storage::disk('public')->exists($vet->licenseDocumentPath)) {
            return response()->json(['status' => false, 'message' => 'No licence document uploaded'], 404);
        }

        return Storage::disk('public')->response($vet->licenseDocumentPath);
    }

    /** GET admin/entities/vet-service-requests?status= */
    public function adminRequests(Request $request): JsonResponse
    {
        $q = VetServiceRequest::with(['vet', 'farmer']);
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }
        $items = $q->orderByDesc('id')->limit(min(500, (int) $request->query('limit', 100)))->get();

        return response()->json([
            'status' => true,
            'message' => 'Service requests retrieved',
            'data' => $items->map->toWire()->values(),
            'meta' => VetServiceRequest::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status'),
        ], 200);
    }

    private function adminRow(Vet $vet): array
    {
        return array_merge(VetProfileController::privateProfileFor($vet), [
            'country' => $vet->relationLoaded('country') ? $vet->country : null,
            'region' => $vet->relationLoaded('region') ? $vet->region : null,
            'district' => $vet->relationLoaded('district') ? $vet->district : null,
            'user' => $vet->relationLoaded('user') ? $vet->user : null,
            'gender' => $vet->gender,
            'dateOfBirth' => optional($vet->dateOfBirth)->toDateString(),
            'identityCardTypeId' => $vet->identityCardTypeId,
            'identityNo' => $vet->identityNo,
            'completedRequestsCount' => $vet->completedRequestsCount ?? null,
            'updatedAt' => $vet->updated_at?->toIso8601String(),
        ]);
    }
}
