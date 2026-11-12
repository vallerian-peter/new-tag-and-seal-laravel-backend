<?php

namespace App\Http\Controllers;

use App\Services\FarmerNumberGenerator;
use App\Services\FarmerProfileFields;
use App\Services\MembershipRegistrationSms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FeatureMembershipController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $memberships = DB::table('feature_memberships')->where('user_id', $request->user()->id)->get()
            ->map(function ($row) use ($request) {
                $row->details = json_decode($row->details, true);
                if ($row->feature === 'farmer' && $row->farmer_id) {
                    $actor = $request->user();
                    $row->farmerProfile = DB::table('farmers')
                        ->where('id', $row->farmer_id)
                        ->where(function ($query) use ($actor) {
                            $query->where('createdBy', $actor->id);
                            if ($actor->role === 'farmer' && $actor->roleId) {
                                $query->orWhere('id', $actor->roleId);
                            }
                        })->first();
                }

                return $row;
            });

        return response()->json([
            'data' => $memberships,
            'delegated' => $this->delegatedFeatures($request->user()->id),
            'account' => ['username' => $request->user()->username, 'phone' => DB::table('phone_identities')->where('user_id', $request->user()->id)->value('phone'), 'phoneVerified' => DB::table('phone_identities')->where('user_id', $request->user()->id)->exists()],
            'assignments' => DB::table('career_onboardings')->where('user_id', $request->user()->id)->orderByDesc('id')->limit(50)->pluck('id')->map(fn ($id) => app(\App\Services\Hiring\OnboardingService::class)->present($id)),
        ]);
    }

    /**
     * Feature workspaces a user may enter through a staff grant rather than
     * their own membership (e.g. an employer's hiring team or a farm's staff).
     * Grants with no permissions, or whose owner no longer holds the matching
     * membership, do not open the workspace.
     *
     * @return list<string>
     */
    private function delegatedFeatures(int $userId): array
    {
        $delegated = [];
        $employerGrants = DB::table('employer_access_grants')
            ->join('feature_memberships', function ($join): void {
                $join->on('feature_memberships.user_id', '=', 'employer_access_grants.owner_user_id')
                    ->where('feature_memberships.feature', 'jobOwner');
            })
            ->where('employer_access_grants.member_user_id', $userId)
            ->pluck('employer_access_grants.permissions');
        if ($employerGrants->contains(fn ($raw) => ! empty(json_decode((string) $raw, true)))) {
            $delegated[] = 'jobOwner';
        }
        $farmGrants = DB::table('farm_access_grants')->where('user_id', $userId)->pluck('permissions');
        if ($farmGrants->contains(fn ($raw) => ! empty(json_decode((string) $raw, true)))) {
            $delegated[] = 'farmer';
        }

        return $delegated;
    }

    public function organizations(Request $request): JsonResponse
    {
        return response()->json(['data' => DB::table('feature_organizations')->where('owner_user_id', $request->user()->id)->get()]);
    }

    public function createOrganization(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'registrationNumber' => ['nullable', 'string', 'max:100']]);
        $id = DB::table('feature_organizations')->insertGetId([
            'owner_user_id' => $request->user()->id, 'name' => $data['name'],
            'registration_number' => $data['registrationNumber'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['data' => DB::table('feature_organizations')->find($id)], 201);
    }

    public function store(Request $request, MembershipRegistrationSms $sms): JsonResponse
    {
        $rules = [
            'feature' => ['required', Rule::in(['jobSeeker', 'jobOwner', 'farmer', 'milking', 'shop', 'marketplaceBuyer', 'marketplaceSeller'])],
            'organizationId' => ['nullable', 'integer'],
            'details' => ['required', 'array'],
            'details.name' => ['required', 'string', 'max:255'],
            'details.fullName' => ['nullable', 'string', 'max:255'],
            'details.firstName' => ['nullable', 'required_with:details.surname,details.middleName', 'string', 'max:255'],
            'details.middleName' => ['nullable', 'string', 'max:255'],
            'details.surname' => ['nullable', 'required_with:details.firstName,details.middleName', 'string', 'max:255'],
            'details.phone' => ['required', 'string', 'max:32'],
            'details.countryId' => ['required', 'integer', 'exists:countries,id'],
            'details.regionId' => ['nullable', 'integer', 'exists:regions,id'],
            'details.districtId' => ['nullable', 'integer', 'exists:districts,id'],
            'details.wardId' => ['nullable', 'integer', 'exists:wards,id'],
            'details.villageId' => ['nullable', 'integer', 'exists:villages,id'],
            'details.skills' => ['nullable', 'array', 'max:50'],
            'details.skills.*' => ['string', 'max:100'],
            'details.experience' => ['nullable', 'string', 'max:3000'],
            'details.type' => ['nullable', 'string', 'max:50'],
            'details.farmerType' => ['nullable', Rule::in(['individual', 'organization'])],
            'details.buyerType' => ['nullable', 'string', 'max:50'],
            'details.sellerType' => ['nullable', 'string', 'max:50'],
            'details.farmId' => ['nullable', 'integer'],
            'details.gender' => ['nullable', 'string', 'max:16'],
            'details.identityNumber' => ['nullable', 'string', 'max:64'],
            'details.dateOfBirth' => ['nullable', 'string', 'max:32'],
        ];
        if ($request->input('feature') === 'farmer') {
            $profile = FarmerProfileFields::rules(false, 'details.');
            $rules += $profile;
            $rules['details.dateOfBirth'] = $profile['details.dateOfBirth'];
            $rules['details.gender'] = $profile['details.gender'];
        }
        $data = $request->validate($rules);
        $organization = $data['organizationId'] ?? null;
        $details = $data['details'];
        $nameParts = collect([
            $details['firstName'] ?? null,
            $details['middleName'] ?? null,
            $details['surname'] ?? null,
        ])->map(fn ($part) => trim(preg_replace('/\s+/', ' ', (string) $part)))
            ->all();
        if ($nameParts[0] !== '' && $nameParts[2] !== '') {
            $details['firstName'] = $nameParts[0];
            $details['middleName'] = $nameParts[1];
            $details['surname'] = $nameParts[2];
            $details['name'] = implode(' ', array_filter($nameParts, fn ($part) => $part !== ''));
        } else {
            $details['name'] = trim(preg_replace('/\s+/', ' ', $details['name']));
        }
        if ($details['name'] === '') {
            return response()->json(['message' => 'The name field is required.'], 422);
        }
        $profile = FarmerProfileFields::columns($details, false);
        FarmerProfileFields::assertLocationChain($profile, 'details.');
        if ($organization && ! DB::table('feature_organizations')->where('id', $organization)->where('owner_user_id', $request->user()->id)->exists()) {
            abort(403);
        }
        $isOrgType = ($data['details']['type'] ?? $data['details']['farmerType'] ?? null) === 'organization';
        if (in_array($data['feature'], ['jobOwner', 'farmer'], true) && $isOrgType !== ($organization !== null)) {
            return response()->json(['message' => 'Organization selection does not match selected type.'], 422);
        }

        return DB::transaction(function () use ($request, $data, $details, $organization, $sms, $profile) {
            $userId = $request->user()->id;
            $isNewMembership = ! DB::table('feature_memberships')
                ->where('user_id', $userId)->where('feature', $data['feature'])->exists();
            DB::table('phone_identities')->where('user_id', $userId)->update([
                'display_name' => $details['name'],
                'updated_at' => now(),
            ]);
            $linkedFarmerId = $data['feature'] === 'farmer'
                ? DB::table('feature_memberships')->where('user_id', $userId)->where('feature', 'farmer')->value('farmer_id')
                : null;
            if ($data['feature'] === 'jobSeeker') {
                $existing = DB::table('feature_memberships')
                    ->where('user_id', $userId)->where('feature', 'jobSeeker')
                    ->value('details');
                $previous = $existing ? json_decode($existing, true, 512, JSON_THROW_ON_ERROR) : [];
                $details = array_merge($previous ?: [], $details);
                if (array_key_exists('fullName', $details)) {
                    $details['fullName'] = $details['name'];
                }
            }
            DB::table('feature_memberships')->upsert([[
                'user_id' => $userId, 'feature' => $data['feature'], 'organization_id' => $organization,
                'details' => json_encode($details, JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now(),
            ]], ['user_id', 'feature'], ['organization_id', 'details', 'updated_at']);

            // When registering as farmer, ensure a corresponding farmer profile exists
            if ($data['feature'] === 'farmer') {
                $legacyNameParts = preg_split('/\s+/', trim($details['name']), 2);
                $firstName = $details['firstName'] ?? $legacyNameParts[0] ?? 'Farmer';
                $middleName = $details['middleName'] ?? null;
                $surname = $details['surname'] ?? $legacyNameParts[1] ?? '';
                $farmerType = $details['farmerType'] ?? $details['type'] ?? 'individual';

                $actor = $request->user();
                $existingFarmer = $linkedFarmerId
                    ? DB::table('farmers')->where('id', $linkedFarmerId)->first()
                    : null;
                if (! $existingFarmer && $actor->role === 'farmer' && $actor->roleId) {
                    $existingFarmer = DB::table('farmers')->where('id', $actor->roleId)->first();
                }
                if (! $existingFarmer) {
                    $existingFarmer = DB::table('farmers')->where('phone1', $details['phone'])->first();
                }
                if ($existingFarmer) {
                    abort_unless(($actor->role === 'farmer' && (int) $actor->roleId === (int) $existingFarmer->id) || (int) $existingFarmer->createdBy === (int) $actor->id, 409, 'FARMER_OWNERSHIP_REVIEW_REQUIRED');
                    $phoneInUse = DB::table('farmers')->where('phone1', $details['phone'])->where('id', '!=', $existingFarmer->id)->exists();
                    abort_if($phoneInUse, 409, 'FARMER_PHONE_ALREADY_IN_USE');
                }
                $optional = FarmerProfileFields::forTable(Arr::except($profile, FarmerProfileFields::REQUIRED_COLUMNS));
                if (! $existingFarmer) {
                    $farmerId = DB::table('farmers')->insertGetId([
                        ...$optional,
                        'farmerNo' => FarmerNumberGenerator::generate(),
                        'firstName' => $firstName,
                        'middleName' => $middleName,
                        'surname' => $surname,
                        'phone1' => $details['phone'],
                        'gender' => $details['gender'] ?? 'male',
                        'farmerType' => $farmerType,
                        'identityNumber' => $details['identityNumber'] ?? null,
                        'dateOfBirth' => $details['dateOfBirth'] ?? null,
                        'countryId' => $details['countryId'],
                        'regionId' => $details['regionId'] ?? null,
                        'districtId' => $details['districtId'] ?? null,
                        'wardId' => $details['wardId'] ?? null,
                        'villageId' => $details['villageId'] ?? null,
                        'createdBy' => $userId,
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $farmerId = $existingFarmer->id;
                    $updates = [
                        'firstName' => $firstName,
                        'middleName' => $middleName,
                        'surname' => $surname,
                        'phone1' => $details['phone'],
                        'farmerType' => $farmerType,
                        'countryId' => $details['countryId'],
                        'regionId' => $details['regionId'] ?? null,
                        'districtId' => $details['districtId'] ?? null,
                        'wardId' => $details['wardId'] ?? null,
                        'villageId' => $details['villageId'] ?? null,
                        'updated_at' => now(),
                    ];
                    if (! empty($profile['gender'])) {
                        $updates['gender'] = $profile['gender'];
                    }
                    DB::table('farmers')->where('id', $farmerId)->update([...$updates, ...$optional]);
                }

                DB::table('feature_memberships')
                    ->where('user_id', $userId)
                    ->whereIn('feature', ['farmer', 'marketplaceSeller'])
                    ->update(['farmer_id' => $farmerId]);
            } elseif ($data['feature'] === 'marketplaceSeller') {
                $actor = $request->user();
                $farmerId = DB::table('feature_memberships')
                    ->where('user_id', $userId)
                    ->whereNotNull('farmer_id')
                    ->value('farmer_id');
                if (! $farmerId && $actor->role === 'farmer' && $actor->roleId) {
                    $farmerId = (int) $actor->roleId;
                }
                if (! $farmerId && ! empty($details['farmId'])) {
                    $farmerId = DB::table('farms')->where('id', (int) $details['farmId'])->value('farmerId');
                }
                if (! $farmerId) {
                    $farmerId = DB::table('farmers')->where('createdBy', $userId)->value('id');
                }
                if (! $farmerId && ! empty($details['phone'])) {
                    $cleanPhone = preg_replace('/[^0-9]/', '', (string) $details['phone']);
                    $suffix = substr($cleanPhone, -9);
                    if ($suffix !== '') {
                        $farmerId = DB::table('farmers')
                            ->where('phone1', 'like', "%{$suffix}%")
                            ->orWhere('phone2', 'like', "%{$suffix}%")
                            ->value('id');
                    }
                }
                if ($farmerId) {
                    DB::table('feature_memberships')
                        ->where('user_id', $userId)
                        ->where('feature', 'marketplaceSeller')
                        ->update(['farmer_id' => (int) $farmerId]);
                }
            }

            if ($isNewMembership) {
                $farmerNo = $data['feature'] === 'farmer'
                    ? DB::table('farmers')->where('id', $farmerId)->value('farmerNo')
                    : null;
                $sms->featureRegistered($userId, $details['phone'], $data['feature'], $details['name'], $farmerNo);
            }

            return $this->index($request);
        });
    }
}
