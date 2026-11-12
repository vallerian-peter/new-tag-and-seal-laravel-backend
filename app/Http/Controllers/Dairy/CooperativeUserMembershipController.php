<?php

namespace App\Http\Controllers\Dairy;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\Dairy\CooperativeAccess;
use App\Services\Dairy\CooperativeMembershipNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CooperativeUserMembershipController extends Controller
{
    public function myMemberships(Request $request): JsonResponse
    {
        $identity = DB::table('phone_identities')->where('user_id', $request->user()->id)
            ->first(['phone', 'display_name']);

        return response()->json([
            'status' => true,
            'data' => [
                'phone' => $identity?->phone,
                'display_name' => $identity?->display_name,
                'cooperatives' => DB::table('cooperative_amcos')->where('is_active', true)
                    ->orderBy('name')->get(['uuid', 'name']),
                'identity_card_types' => DB::table('identity_card_types')->orderBy('id')->get(['id', 'name']),
                'memberships' => DB::table('cooperative_user_memberships as um')
                    ->join('cooperative_amcos as a', 'a.uuid', '=', 'um.amcos_uuid')
                    ->leftJoin('identity_card_types as ict', 'ict.id', '=', 'um.identity_card_type_id')
                    ->where('um.user_id', $request->user()->id)
                    ->whereIn('um.status', ['pending', 'approved', 'inactive'])
                    ->orderBy('a.name')
                    ->get([
                        'um.uuid', 'um.amcos_uuid', 'um.status', 'um.identity_card_type_id', 'um.identity_number',
                        'ict.name as identity_card_type_name', 'a.name as amcos_name', 'a.is_active as amcos_is_active',
                    ]),
            ],
        ]);
    }

    /**
     * POST /api/v2/dairy/amcos-membership
     * Request AMCOS staff membership. It stays PENDING until an administrator
     * (or the portal) approves it; selecting a cooperative never grants access.
     */
    public function saveAmcosUserMembership(Request $request): JsonResponse
    {
        $user = $request->user();
        $locale = $request->header('Accept-Language') ?? $request->input('locale') ?? app()->getLocale();
        $isSwahili = str_starts_with(strtolower((string) $locale), 'sw');

        // Check if the applicant is already registered as a farmer
        $isFarmer = $user->role === UserRole::FARMER
            || ($user->roleId && DB::table('farmers')->where('id', $user->roleId)->exists())
            || DB::table('cooperative_farmer_memberships')->where('farmer_user_id', $user->id)->exists();

        if (! $isFarmer) {
            $identity = DB::table('phone_identities')->where('user_id', $user->id)->first(['phone']);
            if ($identity?->phone) {
                $isFarmer = DB::table('farmers')->where('phone1', $identity->phone)->orWhere('phone2', $identity->phone)->exists()
                    || DB::table('cooperative_farmer_memberships')->where('farmer_phone', $identity->phone)->exists();
            }
        }

        if ($isFarmer) {
            $msg = $isSwahili
                ? 'Wewe tayari umesajiliwa kama mkulima. Mkulima hawezi kuomba uanachama wa mtendaji wa ushirika (AMCOS).'
                : 'You are already registered as a farmer. Farmers cannot apply for cooperative staff membership.';
            return response()->json([
                'status' => false,
                'code' => 'FARMER_CANNOT_BE_STAFF',
                'message' => $msg,
            ], 422);
        }

        $data = $request->validate([
            'amcos_uuid' => ['required', 'string', Rule::exists('cooperative_amcos', 'uuid')->where('is_active', true)],
            'role' => 'nullable|in:amcos_user,centre_clerk',
            'applicant_name' => 'required|string|max:255',
            'applicant_gender' => 'required|in:male,female',
            'applicant_date_of_birth' => 'required|date_format:Y-m-d|before_or_equal:today',
            'identity_card_type_id' => 'nullable|integer|exists:identity_card_types,id',
            'identityCardTypeId' => 'nullable|integer|exists:identity_card_types,id',
            'identity_number' => 'nullable|string|max:100',
            'identityNumber' => 'nullable|string|max:100',
        ]);

        $name = trim(preg_replace('/\s+/', ' ', $data['applicant_name']));
        if ($name === '') {
            return response()->json(['status' => false, 'message' => 'Applicant name is required.'], 422);
        }

        $identityTypeId = $data['identity_card_type_id'] ?? $data['identityCardTypeId'] ?? null;
        $identityNumber = trim($data['identity_number'] ?? $data['identityNumber'] ?? '');

        if ($identityTypeId) {
            $idType = DB::table('identity_card_types')->where('id', $identityTypeId)->first();
            $typeName = strtoupper($idType?->name ?? '');

            if (str_contains($typeName, 'NIDA')) {
                // Must format as yyyymmdd-#####-#####-## (20 digits)
                $digits = preg_replace('/\D/', '', $identityNumber);
                if (strlen($digits) !== 20) {
                    $msg = $isSwahili
                        ? 'Namba ya NIDA inapaswa kuwa na tarakimu 20 (mfano 19900101-00000-00000-00).'
                        : 'NIDA number must have 20 digits (e.g. 19900101-00000-00000-00).';
                    return response()->json(['status' => false, 'message' => $msg], 422);
                }
                // Canonical format YYYYMMDD-XXXXX-XXXXX-XX
                $identityNumber = substr($digits, 0, 8) . '-' . substr($digits, 8, 5) . '-' . substr($digits, 13, 5) . '-' . substr($digits, 18, 2);
            } elseif (str_contains($typeName, 'LICEN') || str_contains($typeName, 'DRIVER')) {
                if ($identityNumber === '' || ! preg_match('/^[A-Za-z0-9-]{8,15}$/', $identityNumber)) {
                    $msg = $isSwahili
                        ? 'Nambari ya leseni ya kuendesha si sahihi (mfano tarakimu 8-12).'
                        : 'Invalid driving license number (e.g. 8-12 alphanumeric characters).';
                    return response()->json(['status' => false, 'message' => $msg], 422);
                }
                $identityNumber = strtoupper($identityNumber);
            } elseif ($identityNumber === '') {
                $msg = $isSwahili ? 'Nambari ya kitambulisho inahitajika.' : 'Identity number is required.';
                return response()->json(['status' => false, 'message' => $msg], 422);
            }
        }

        $response = DB::transaction(function () use ($data, $user, $identityTypeId, $identityNumber, $name) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->firstOrFail();
            if (! DB::table('phone_identities')->where('user_id', $user->id)->exists()) {
                return response()->json(['status' => false, 'message' => 'Sign in with a verified phone before requesting membership.'], 422);
            }
            $amcos = DB::table('cooperative_amcos')->where('uuid', $data['amcos_uuid'])
                ->where('is_active', true)->lockForUpdate()->firstOrFail();
            $existing = DB::table('cooperative_user_memberships')
                ->where('user_id', $user->id)->where('amcos_uuid', $amcos->uuid)
                ->lockForUpdate()->first();

            if ($existing && $existing->status === 'inactive') {
                return response()->json(['status' => false, 'message' => 'This membership is inactive. Contact your cooperative administrator.'], 409);
            }

            DB::table('phone_identities')->where('user_id', $user->id)->update([
                'display_name' => $name,
                'updated_at' => now(),
            ]);

            if ($existing && in_array($existing->status, ['approved', 'pending'], true)) {
                DB::table('cooperative_user_memberships')->where('id', $existing->id)->update([
                    'applicant_name' => $name,
                    'updated_at' => now(),
                ]);
                $existing = DB::table('cooperative_user_memberships')->where('id', $existing->id)->first();

                return response()->json([
                    'status' => true,
                    'message' => $existing->status === 'approved' ? 'Membership already approved.' : 'Membership request is awaiting approval.',
                    'data' => $existing,
                ]);
            }

            $uuid = (string) Str::uuid();
            if ($existing) {
                DB::table('cooperative_user_memberships')->where('id', $existing->id)->delete();
            }
            DB::table('cooperative_user_memberships')->insert([
                'uuid' => $uuid,
                'user_id' => $user->id,
                'amcos_uuid' => $amcos->uuid,
                'mcu_uuid' => $amcos->mcu_uuid,
                'role' => $data['role'] ?? 'amcos_user',
                'assigned_centre_uuids' => json_encode([]),
                'status' => 'pending',
                'applicant_name' => trim($data['applicant_name']),
                'applicant_gender' => $data['applicant_gender'],
                'applicant_date_of_birth' => $data['applicant_date_of_birth'],
                'identity_card_type_id' => $identityTypeId,
                'identity_number' => $identityNumber !== '' ? $identityNumber : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            app(CooperativeMembershipNotifications::class)->record($uuid, $user->id, 'submitted');

            return response()->json([
                'status' => true,
                'message' => 'Membership request submitted and awaiting approval.',
                'data' => DB::table('cooperative_user_memberships')->where('uuid', $uuid)->first(),
            ], 201);
        });

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            app(CooperativeMembershipNotifications::class)->dispatchPending();
        }

        return $response;
    }

    /**
     * GET /api/v2/dairy/amcos-membership/pending?amcos_uuid=
     * Administrators review staff requests for their cooperative.
     */
    public function pending(Request $request): JsonResponse
    {
        $amcosUuid = (string) $request->query('amcos_uuid');
        $super = $request->user()->role === UserRole::SYSTEM_USER && $request->user()->platform_role === 'superAdmin';
        $scopes = collect();
        if ($amcosUuid !== '' && ! $super) {
            CooperativeAccess::requireStaff($request->user()->id, $amcosUuid, true);
        }
        if ($amcosUuid === '' && ! $super) {
            $scopes = DB::table('cooperative_user_memberships')->where('user_id', $request->user()->id)
                ->where('status', 'approved')->whereIn('role', CooperativeAccess::ADMIN_ROLES)->get();
            if ($scopes->isEmpty()) {
                CooperativeAccess::deny();
            }
        }

        $rows = DB::table('cooperative_user_memberships as um')
            ->leftJoin('users as u', 'u.id', '=', 'um.user_id')
            ->leftJoin('phone_identities as pi', 'pi.user_id', '=', 'u.id')
            ->join('cooperative_amcos as a', 'a.uuid', '=', 'um.amcos_uuid')
            ->leftJoin('farmers as f', function ($join) {
                $join->on('f.id', '=', 'u.roleId')->where('u.role', '=', UserRole::FARMER);
            })
            ->when($amcosUuid !== '', fn ($q) => $q->where('um.amcos_uuid', $amcosUuid))
            ->when($amcosUuid === '' && ! $super, function ($q) use ($scopes) {
                $q->where(function ($scope) use ($scopes) {
                    foreach ($scopes as $membership) {
                        if ($membership->role === 'mcu_admin' && $membership->mcu_uuid !== null) {
                            $scope->orWhere('a.mcu_uuid', $membership->mcu_uuid);
                        } else {
                            $scope->orWhere('a.uuid', $membership->amcos_uuid);
                        }
                    }
                });
            })
            ->where('um.status', 'pending')
            ->select('um.*', 'u.username', 'pi.phone', 'pi.display_name', 'a.name as amcos_name',
                'f.id as farmer_id', 'f.farmerNo as farmer_number', 'f.firstName as farmer_first_name',
                'f.middleName as farmer_middle_name', 'f.surname as farmer_surname',
                'f.gender as farmer_gender', 'f.dateOfBirth as farmer_date_of_birth',
                'f.farmerType as farmer_type', 'f.status as farmer_status')
            ->get()->map(function ($row) {
                $row->farmer_details = $row->farmer_id === null ? null : [
                    'farmer_number' => $row->farmer_number,
                    'first_name' => $row->farmer_first_name,
                    'middle_name' => $row->farmer_middle_name,
                    'surname' => $row->farmer_surname,
                    'gender' => $row->farmer_gender,
                    'date_of_birth' => $row->farmer_date_of_birth,
                    'farmer_type' => $row->farmer_type,
                    'status' => $row->farmer_status,
                ];
                foreach (['farmer_id', 'farmer_number', 'farmer_first_name', 'farmer_middle_name', 'farmer_surname', 'farmer_gender', 'farmer_date_of_birth', 'farmer_type', 'farmer_status'] as $field) {
                    unset($row->$field);
                }
                return $row;
            });

        $amcosUuids = $rows->pluck('amcos_uuid')->unique()->all();
        $centres = DB::table('cooperative_collection_centres')->whereIn('amcos_uuid', $amcosUuids)
            ->where('is_active', true)->orderBy('name')->get(['uuid', 'name', 'amcos_uuid']);
        return response()->json(['status' => true, 'data' => $rows, 'centres' => $centres]);
    }

    /**
     * POST /api/v2/dairy/amcos-membership/{uuid}/review
     * Approve, reject or deactivate a staff membership and assign centres.
     */
    public function review(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'decision' => 'required|in:approved,rejected,inactive',
            'role' => 'nullable|in:amcos_admin,amcos_user,centre_clerk',
            'assigned_centre_uuids' => 'nullable|array',
            'assigned_centre_uuids.*' => 'string',
        ]);

        $response = DB::transaction(function () use ($request, $data, $uuid) {
            $membership = DB::table('cooperative_user_memberships')->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if (! ($request->user()->role === UserRole::SYSTEM_USER && $request->user()->platform_role === 'superAdmin')) {
                CooperativeAccess::requireStaff($request->user()->id, $membership->amcos_uuid, true);
            }
            $expected = $data['decision'] === 'inactive' ? 'approved' : 'pending';
            if ($membership->status !== $expected) {
                return response()->json(['status' => false, 'message' => 'This membership has already changed. Refresh and try again.'], 409);
            }
            if ($data['decision'] === 'approved' && ! DB::table('cooperative_amcos')->where('uuid', $membership->amcos_uuid)->where('is_active', true)->exists()) {
                return response()->json(['status' => false, 'message' => 'An inactive cooperative cannot approve new memberships.'], 409);
            }

            $requestedCentres = array_values(array_unique($data['assigned_centre_uuids'] ?? []));
            $centres = DB::table('cooperative_collection_centres')
                ->where('amcos_uuid', $membership->amcos_uuid)
                ->where('is_active', true)
                ->whereIn('uuid', $requestedCentres)->pluck('uuid')->all();
            if (count($centres) !== count($requestedCentres)) {
                return response()->json(['status' => false, 'message' => 'All assigned centres must be active and belong to this cooperative.'], 422);
            }

            DB::table('cooperative_membership_reviews')->insert([
                'membership_uuid' => $membership->uuid,
                'applicant_user_id' => $membership->user_id,
                'amcos_uuid' => $membership->amcos_uuid,
                'reviewer_user_id' => $request->user()->id,
                'decision' => $data['decision'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if (in_array($data['decision'], ['approved', 'rejected'], true)) {
                app(CooperativeMembershipNotifications::class)->record($membership->uuid, $membership->user_id, $data['decision']);
            }

            if ($data['decision'] === 'rejected') {
                DB::table('cooperative_user_memberships')->where('id', $membership->id)->delete();
                return response()->json(['status' => true, 'message' => 'Membership request disapproved.', 'data' => null]);
            }

            DB::table('cooperative_user_memberships')->where('id', $membership->id)->update([
                'status' => $data['decision'],
                'role' => $data['decision'] === 'approved' ? ($data['role'] ?? $membership->role) : $membership->role,
                'assigned_centre_uuids' => $data['decision'] === 'approved' ? json_encode($centres) : $membership->assigned_centre_uuids,
                'approved_by_user_id' => $data['decision'] === 'approved' ? $request->user()->id : $membership->approved_by_user_id,
                'approved_at' => $data['decision'] === 'approved' ? now() : $membership->approved_at,
                'updated_at' => now(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Membership updated.',
                'data' => DB::table('cooperative_user_memberships')->where('uuid', $uuid)->first(),
            ]);
        });

        if (
            $response->getStatusCode() >= 200
            && $response->getStatusCode() < 300
            && in_array($data['decision'], ['approved', 'rejected'], true)
        ) {
            app(CooperativeMembershipNotifications::class)->dispatchPending();
        }

        return $response;
    }
}
