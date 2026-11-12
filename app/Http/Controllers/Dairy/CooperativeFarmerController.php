<?php

namespace App\Http\Controllers\Dairy;

use App\Http\Controllers\Controller;
use App\Services\Dairy\CooperativeAccess;
use App\Services\Dairy\CooperativeFarmerMutation;
use App\Services\FarmerNumberGenerator;
use App\Services\FarmerProfileFields;
use App\Services\Hiring\HiringNotifications;
use App\Services\MembershipRegistrationSms;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CooperativeFarmerController extends Controller
{
    /**
     * GET /api/v2/dairy/farmers
     * Lists enrolled AMCOS farmers (with ledger balances) and searches existing Tag & Seal farmers for non-conflicting enrollment.
     */
    public function listFarmers(Request $request): JsonResponse
    {
        $amcosUuid = $request->query('amcos_uuid');
        $centreUuid = $request->query('centre_uuid');
        $search = trim((string) $request->query('search', ''));

        if (! $amcosUuid) {
            CooperativeAccess::deny('Select a cooperative first.');
        }
        $staff = CooperativeAccess::requireStaff($request->user()->id, $amcosUuid);

        $query = DB::table('cooperative_farmer_memberships as fm')
            ->leftJoin('cooperative_amcos as a', 'a.uuid', '=', 'fm.amcos_uuid')
            ->leftJoin('cooperative_mcus as m', 'm.uuid', '=', 'fm.mcu_uuid')
            ->leftJoin('farmers as f', 'f.id', '=', 'fm.farmer_id')
            ->select(
                'fm.*',
                'f.farmerNo as farmer_no',
                'a.name as amcos_name',
                'a.default_unit',
                'm.name as mcu_name',
                ...FarmerProfileFields::selects('f', true),
            )
            ->orderByDesc('fm.updated_at');

        if ($amcosUuid) {
            $query->where('fm.amcos_uuid', $amcosUuid)
                ->whereNotIn('fm.status', ['archived', 'deleted']);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('fm.farmer_name', 'like', "%{$search}%")
                    ->orWhere('fm.farmer_phone', 'like', "%{$search}%")
                    ->orWhere('fm.member_number', 'like', "%{$search}%");
            });
        }

        $enrolled = $query->get()->map(function ($row) {
            $row->enrolled_centre_uuids = json_decode((string) ($row->enrolled_centre_uuids ?? '[]'), true) ?: [];
            $ledger = DB::table('farmer_cooperative_account_ledgers')
                ->where('farmer_user_id', $row->farmer_user_id)
                ->where('amcos_uuid', $row->amcos_uuid)
                ->selectRaw('COALESCE(SUM(credit_amount), 0) as total_credits, COALESCE(SUM(debit_amount), 0) as total_debits')
                ->first();
            $row->total_credits = round((float) ($ledger->total_credits ?? 0), 2);
            $row->total_debits = round((float) ($ledger->total_debits ?? 0), 2);
            $row->net_balance = round($row->total_credits - $row->total_debits, 2);
            $row->total_accepted_quantity = round((float) DB::table('cooperative_intakes')
                ->where('farmer_user_id', $row->farmer_user_id)
                ->where('amcos_uuid', $row->amcos_uuid)
                ->sum('accepted_quantity'), 2);
            return $row;
        });

        if ($centreUuid) {
            $enrolled = $enrolled->filter(function ($row) use ($centreUuid) {
                return empty($row->enrolled_centre_uuids) || in_array($centreUuid, $row->enrolled_centre_uuids, true);
            })->values();
        }

        $directory = collect();
        if ($search !== '') {
            $directory = DB::table('farmers as f')
                ->leftJoin('users as u', function ($join) {
                    $join->on('u.roleId', '=', 'f.id')->where('u.role', '=', 'farmer');
                })
                ->where(function ($q) use ($search) {
                    $q->where('f.firstName', 'like', "%{$search}%")
                        ->orWhere('f.surname', 'like', "%{$search}%")
                        ->orWhere('f.phone1', 'like', "%{$search}%")
                        ->orWhere('f.farmerNo', 'like', "%{$search}%");
                })
                ->limit(20)
                ->get(['f.id as farmer_id', 'f.farmerNo', 'f.firstName', 'f.surname', 'f.phone1 as phone', 'f.createdBy', 'u.id as user_id'])
                ->map(function ($f) {
                    return [
                        'farmer_id' => $f->farmer_id,
                        'user_id' => $f->user_id ?? $f->createdBy,
                        'farmer_no' => $f->farmerNo,
                        'name' => trim("{$f->firstName} {$f->surname}"),
                        'phone' => $f->phone,
                    ];
                });
        }

        return response()->json([
            'status' => true,
            'data' => [
                'enrolled_farmers' => $enrolled,
                'directory_matches' => $directory,
            ],
        ]);
    }
    /**
     * POST /api/v2/dairy/farmers/request-otp
     * AMCOS_USER registers a farmer to AMCOS & Collection Centres without conflicting with existing Tag & Seal accounts,
     * enforces that a farmer can ONLY belong to ONE AMCOS, and sends an SMS OTP to the farmer's phone.
     */
    public function requestFarmerEnrollmentOtp(Request $request, SmsService $smsService, HiringNotifications $notifications): JsonResponse
    {
        $data = $request->validate([
            'uuid' => 'nullable|string|max:64',
            'amcos_uuid' => 'required|string|exists:cooperative_amcos,uuid',
            'phone' => 'required|string|max:32',
            'name' => 'nullable|string|max:255',
        ]);

        $amcos = DB::table('cooperative_amcos')->where('uuid', $data['amcos_uuid'])->firstOrFail();
        CooperativeAccess::requireStaff($request->user()->id, $amcos->uuid);
        $digits = preg_replace('/[^0-9]/', '', $data['phone']);
        $suffix = substr($digits, -9);
        if (strlen($suffix) < 9) {
            return response()->json(['status' => false, 'message' => 'Invalid phone number.'], 422);
        }
        $normalizedLocal = '0' . $suffix;
        $normalizedIntl = '255' . $suffix;

        return DB::transaction(function () use ($request, $data, $amcos, $suffix, $normalizedLocal, $normalizedIntl, $smsService, $notifications) {
            // 1. Resolve existing Tag & Seal user/farmer by phone WITHOUT creating duplicates or conflicts
            $identity = DB::table('phone_identities')
                ->where('phone', 'like', "%{$suffix}%")
                ->first();

            $existingFarmer = DB::table('farmers')
                ->where(function ($query) use ($suffix) {
                    $query->where('phone1', 'like', "%{$suffix}%")
                        ->orWhere('phone2', 'like', "%{$suffix}%");
                })
                ->orderByRaw('CASE WHEN phone1 LIKE ? THEN 0 ELSE 1 END', ["%{$suffix}%"])
                ->orderBy('id')
                ->first();

            if (! $existingFarmer) {
                return response()->json([
                    'status' => false,
                    'code' => 'not_found',
                    'message' => $this->localized(
                        $request,
                        'This phone is not an existing farmer phone.',
                        'Namba hii si ya mkulima aliyepo.'
                    ),
                ], 404);
            }

            $farmerUserId = $identity?->user_id;
            if (! $farmerUserId && $existingFarmer) {
                $farmerUserId = DB::table('feature_memberships')
                    ->where('farmer_id', $existingFarmer->id)
                    ->value('user_id')
                    ?? DB::table('users')
                        ->where('role', 'farmer')
                        ->where('roleId', $existingFarmer->id)
                        ->value('id')
                    ?? $existingFarmer->createdBy;
            }

            if (! $farmerUserId) {
                $userRow = DB::table('users')
                    ->where('phone', 'like', "%{$suffix}%")
                    ->first();
                $farmerUserId = $userRow?->id;
            }

            $resolvedName = trim((string) ($data['name'] ?? ''));
            if ($resolvedName === '' && $existingFarmer) {
                $resolvedName = trim("{$existingFarmer->firstName} {$existingFarmer->surname}");
            }
            if ($resolvedName === '' && $identity?->display_name) {
                $resolvedName = trim($identity->display_name);
            }
            if ($resolvedName === '') {
                $resolvedName = 'Mkulima ' . $normalizedLocal;
            }

            if (! $farmerUserId) {
                $username = 'farmer_' . $suffix;
                if (DB::table('users')->where('username', $username)->exists()) {
                    $username .= '_' . rand(10, 99);
                }
                $farmerUserId = DB::table('users')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'username' => $username,
                    'email' => "{$username}@tagandseal.local",
                    'phone' => $normalizedLocal,
                    'password' => Hash::make(Str::random(12)),
                    'role' => 'farmer',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // STRICT RULE: A Farmer can ONLY be registered to ONE AMCOS!
            $existingMembership = DB::table('cooperative_farmer_memberships as fm')
                ->leftJoin('cooperative_amcos as a', 'a.uuid', '=', 'fm.amcos_uuid')
                ->where('fm.farmer_user_id', $farmerUserId)
                ->select('fm.*', 'a.name as amcos_name')
                ->first();

            if ($existingMembership && $existingMembership->amcos_uuid !== $amcos->uuid && $existingMembership->status === 'active') {
                return response()->json([
                    'status' => false,
                    'message' => "Mkulima huyu tayari amesajiliwa kwenye AMCOS nyingine ({$existingMembership->amcos_name}). Mkulima anaweza kusajiliwa kwenye AMCOS moja pekee.",
                ], 422);
            }

            $farmerId = $existingFarmer?->id;
            if (! $farmerId) {
                $nameParts = preg_split('/\s+/', $resolvedName, 2);
                $farmerId = DB::table('farmers')->insertGetId([
                    'farmerNo' => FarmerNumberGenerator::generate(),
                    'firstName' => $nameParts[0] ?? 'Mkulima',
                    'surname' => $nameParts[1] ?? '',
                    'phone1' => $normalizedLocal,
                    'gender' => 'male',
                    'farmerType' => 'individual',
                    'countryId' => DB::table('countries')->value('id') ?? 1,
                    'regionId' => $amcos->region_id,
                    'districtId' => $amcos->district_id,
                    'createdBy' => $farmerUserId,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (! DB::table('feature_memberships')->where('user_id', $farmerUserId)->where('feature', 'milking')->exists()) {
                DB::table('feature_memberships')->insert([
                    'user_id' => $farmerUserId,
                    'feature' => 'milking',
                    'farmer_id' => $farmerId,
                    'details' => json_encode([
                        'name' => $resolvedName,
                        'phone' => $normalizedLocal,
                        'amcos_uuid' => $amcos->uuid,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 2. Generate 6-digit OTP and upsert cooperative_farmer_memberships
            $otp = (string) random_int(100000, 999999);
            $membershipUuid = $data['uuid'] ?? ($existingMembership->uuid ?? (string) Str::uuid());
            $memberNumber = $existingMembership->member_number
                ?? ($amcos->amcos_code . '-F-' . str_pad((string) (DB::table('cooperative_farmer_memberships')->count() + 1), 4, '0', STR_PAD_LEFT));

            $mergedCentres = [];
            $membershipStatus = ($existingMembership && $existingMembership->amcos_uuid === $amcos->uuid && $existingMembership->status === 'active')
                ? 'active'
                : 'pending_otp';

            DB::table('cooperative_farmer_memberships')->updateOrInsert(
                ['farmer_user_id' => $farmerUserId],
                [
                    'uuid' => $membershipUuid,
                    'mcu_uuid' => $amcos->mcu_uuid,
                    'amcos_uuid' => $amcos->uuid,
                    'farmer_id' => $farmerId,
                    'member_number' => $memberNumber,
                    'farmer_name' => $resolvedName,
                    'farmer_phone' => $normalizedLocal,
                    'enrolled_centre_uuids' => json_encode($mergedCentres),
                    'status' => $membershipStatus,
                    'otp_code' => $otp,
                    'otp_expires_at' => now()->addMinutes(15),
                    'enrolled_by_user_id' => $request->user()->id,
                    'updated_at' => now(),
                    'created_at' => $existingMembership->created_at ?? now(),
                ]
            );

            DB::table('otp_verifications')->insert([
                'phone' => $normalizedLocal,
                'otp' => $otp,
                'expires_at' => now()->addMinutes(15),
                'verified' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $smsText = "Tag & Seal: Unaingia kwenye AMCOS {$amcos->name} kwa uthibitisho. Namba yako ya OTP ni {$otp}. Nenda ukabidhi namba hii kwa mtumiaji wa AMCOS.";
            $eventKey = 'amcos_farmer_otp:'.$membershipUuid.':'.$otp;

            try {
                $notifications->send(
                    $eventKey,
                    (int) $farmerUserId,
                    $normalizedIntl,
                    'amcos_farmer_enrollment_otp',
                    $smsText,
                    $smsText,
                    ['amcos_uuid' => $amcos->uuid, 'membership_uuid' => $membershipUuid]
                );
            } catch (\Throwable $e) {
                Log::warning('⚠️ CooperativeFarmerController OTP notice warning: '.$e->getMessage());
            }

            try {
                $result = $smsService->sendSms($smsText, $normalizedIntl);
                $failed = is_string($result) || (isset($result['status']) && in_array(strtolower((string) $result['status']), ['error', 'failed'], true));
                if (! $failed) {
                    DB::table('hiring_notifications')->where('event_key', $eventKey)->update([
                        'sms_status' => 'sent',
                        'sms_sent_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('⚠️ CooperativeFarmerController OTP SMS warning: '.$e->getMessage());
            }

            return response()->json([
                'status' => true,
                'message' => 'OTP sent to farmer phone for AMCOS verification.',
                'sms_notice_sw' => "OTP imetumwa kwa simu ya mkulima ({$normalizedLocal}). Tafadhali ingiza OTP kuthibitisha usajili.",
                'data' => [
                    'membership_uuid' => $membershipUuid,
                    'member_number' => $memberNumber,
                    'farmer_user_id' => $farmerUserId,
                    'farmer_id' => $farmerId,
                    'farmer_name' => $resolvedName,
                    'farmer_phone' => $normalizedLocal,
                    'amcos_uuid' => $amcos->uuid,
                    'amcos_name' => $amcos->name,
                    'enrolled_centre_uuids' => $mergedCentres,
                    'status' => $membershipStatus,
                ],
            ]);
        });
    }
    /**
     * POST /api/v2/dairy/farmers/verify-otp
     * AMCOS_USER verifies the OTP received by the farmer to activate AMCOS & CC enrollment.
     */
    public function verifyFarmerEnrollmentOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'membership_uuid' => 'required|string|exists:cooperative_farmer_memberships,uuid',
            'otp_code' => 'required|string|max:10',
        ]);

        $membership = DB::table('cooperative_farmer_memberships')
            ->where('uuid', $data['membership_uuid'])
            ->firstOrFail();

        CooperativeAccess::requireStaff($request->user()->id, $membership->amcos_uuid);

        if ($membership->otp_expires_at && now()->greaterThan($membership->otp_expires_at)) {
            return response()->json([
                'status' => false,
                'message' => 'OTP imekwisha muda. Tafadhali tuma upya.',
            ], 422);
        }

        if (trim((string) $membership->otp_code) !== trim($data['otp_code'])) {
            return response()->json([
                'status' => false,
                'message' => 'Namba ya OTP si sahihi. Tafadhali hakiki na ujaribu tena.',
            ], 422);
        }

        DB::table('cooperative_farmer_memberships')
            ->where('uuid', $membership->uuid)
            ->update([
                'status' => 'active',
                'otp_code' => null,
                'verified_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('otp_verifications')
            ->where('phone', $membership->farmer_phone)
            ->where('otp', trim($data['otp_code']))
            ->update(['verified' => true, 'updated_at' => now()]);

        $updated = DB::table('cooperative_farmer_memberships')->where('uuid', $membership->uuid)->first();
        if ($membership->status !== 'active') {
            app(MembershipRegistrationSms::class)->amcosFarmerRegistered((int) $membership->farmer_user_id, $membership->farmer_phone, $membership->farmer_name, (string) DB::table('cooperative_amcos')->where('uuid', $membership->amcos_uuid)->value('name'), $membership->member_number, DB::table('farmers')->where('id', $membership->farmer_id)->value('farmerNo'), $membership->uuid);
        }
        if ($updated) {
            $updated->enrolled_centre_uuids = json_decode((string) ($updated->enrolled_centre_uuids ?? '[]'), true) ?: [];
        }

        return response()->json([
            'status' => true,
            'message' => 'Mkulima amethibitishwa na kusajiliwa kikamilifu kwenye AMCOS na Kituo.',
            'data' => $updated,
        ]);
    }

    /**
     * POST /api/v2/dairy/farmers/enroll
     * Offline-first enrollment. The phone is checked first: a matching farmer with the same
     * data is connected to the AMCOS, a matching farmer with different data is reported as a
     * conflict (409), and an unknown phone creates the farmer and connects it.
     */
    public function enrollFarmer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uuid' => 'required|string|max:64',
            'amcos_uuid' => 'required|string|exists:cooperative_amcos,uuid',
            'phone' => 'required|string|max:32',
            ...FarmerProfileFields::rules(true),
            'existing' => 'nullable|boolean',
            'sync_action' => 'nullable|in:CREATE,UPDATE,DELETE,create,update,delete',
        ]);

        $amcos = DB::table('cooperative_amcos')->where('uuid', $data['amcos_uuid'])->firstOrFail();
        CooperativeAccess::requireStaff($request->user()->id, $amcos->uuid);

        FarmerProfileFields::assertLocationChain(FarmerProfileFields::columns($data, true));
        $action = strtoupper((string) ($data['sync_action'] ?? 'CREATE'));
        if (in_array($action, ['UPDATE', 'DELETE'], true)) {
            return (new CooperativeFarmerMutation())->apply($request, $data, $amcos, $action);
        }

        $suffix = substr(preg_replace('/[^0-9]/', '', $data['phone']), -9);
        if (strlen($suffix) < 9) {
            return response()->json(['status' => false, 'message' => 'Invalid phone number.'], 422);
        }
        $local = '0' . $suffix;

        return DB::transaction(function () use ($request, $data, $amcos, $suffix, $local) {
            $farmer = DB::table('farmers')
                ->where(function ($query) use ($suffix) {
                    $query->where('phone1', 'like', "%{$suffix}%")
                        ->orWhere('phone2', 'like', "%{$suffix}%");
                })
                // The farmer whose main phone this is, before one listing it as a second phone.
                ->orderByRaw('CASE WHEN phone1 LIKE ? THEN 0 ELSE 1 END', ["%{$suffix}%"])
                ->orderBy('id')
                ->first();
            $identity = DB::table('phone_identities')->where('phone', 'like', "%{$suffix}%")->first();
            $farmerUserId = $identity?->user_id
                ?? ($farmer ? (DB::table('feature_memberships')->where('farmer_id', $farmer->id)->value('user_id')
                    ?? DB::table('users')->where('role', 'farmer')->where('roleId', $farmer->id)->value('id')
                    ?? $farmer->createdBy) : null);

            $existingName = $farmer
                ? trim(implode(' ', array_filter([$farmer->firstName, $farmer->middleName ?? '', $farmer->surname])))
                : '';

            if ($farmer) {
                $membership = DB::table('cooperative_farmer_memberships as fm')
                    ->leftJoin('cooperative_amcos as a', 'a.uuid', '=', 'fm.amcos_uuid')
                    ->where('fm.farmer_user_id', $farmerUserId)
                    ->select('fm.*', 'a.name as amcos_name')
                    ->first();
                if ($membership && $membership->amcos_uuid !== $amcos->uuid && $membership->status === 'active') {
                    return $this->enrollConflict($request, $local, $existingName ?: $membership->farmer_name, 'other_amcos', $membership->amcos_name);
                }
                $differences = $this->detailDifferences($farmer, $data);
                if ($differences !== []) {
                    return $this->enrollConflict($request, $local, $existingName, 'different_data', null, $farmer, $differences);
                }
            }

            if (! $farmerUserId) {
                $username = 'farmer_' . $suffix;
                if (DB::table('users')->where('username', $username)->exists()) {
                    $username .= '_' . rand(10, 99);
                }
                $farmerUserId = DB::table('users')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'username' => $username,
                    'email' => "{$username}@tagandseal.local",
                    'password' => Hash::make(Str::random(12)),
                    'role' => 'farmer',
                    'roleId' => 0,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $membership = DB::table('cooperative_farmer_memberships as fm')
                ->leftJoin('cooperative_amcos as a', 'a.uuid', '=', 'fm.amcos_uuid')
                ->where('fm.farmer_user_id', $farmerUserId)
                ->select('fm.*', 'a.name as amcos_name')
                ->first();
            if ($membership && $membership->amcos_uuid !== $amcos->uuid && $membership->status === 'active') {
                return $this->enrollConflict($request, $local, $existingName ?: $membership->farmer_name, 'other_amcos', $membership->amcos_name);
            }

            $resolvedName = $existingName !== '' ? $existingName : trim(implode(' ', array_filter([
                $data['first_name'] ?? '', $data['middle_name'] ?? '', $data['last_name'] ?? '',
            ])));
            if ($resolvedName === '') {
                $resolvedName = 'Mkulima ' . $local;
            }

            $farmerId = $farmer?->id;
            if (! $farmerId) {
                $farmerId = DB::table('farmers')->insertGetId([
                    ...FarmerProfileFields::forTable(Arr::except(FarmerProfileFields::columns($data, true), FarmerProfileFields::REQUIRED_COLUMNS)),
                    'farmerNo' => FarmerNumberGenerator::generate(),
                    'firstName' => $data['first_name'] ?? 'Mkulima',
                    'middleName' => $data['middle_name'] ?? null,
                    'surname' => $data['last_name'] ?? '',
                    'phone1' => $local,
                    'gender' => $data['gender'] ?? 'male',
                    'identityNumber' => $data['identity_number'] ?? null,
                    'farmerType' => $data['farmer_type'] ?? 'individual',
                    'farmerOrganizationMembership' => $data['organization_name'] ?? null,
                    'countryId' => $data['country_id'] ?? (DB::table('countries')->value('id') ?? 1),
                    'regionId' => $data['region_id'] ?? null,
                    'districtId' => $data['district_id'] ?? null,
                    'createdBy' => $farmerUserId,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                // A matching farmer only gains details it does not have yet.
                $blanks = array_filter(
                    FarmerProfileFields::forTable(Arr::except(FarmerProfileFields::columns($data, true), FarmerProfileFields::REQUIRED_COLUMNS)),
                    fn ($value, $column) => $value !== null && ($farmer->{$column} ?? null) === null,
                    ARRAY_FILTER_USE_BOTH
                );
                if ($blanks !== []) {
                    DB::table('farmers')->where('id', $farmerId)->update([...$blanks, 'updated_at' => now()]);
                }
            }

            if (! DB::table('feature_memberships')->where('user_id', $farmerUserId)->where('feature', 'milking')->exists()) {
                DB::table('feature_memberships')->insert([
                    'user_id' => $farmerUserId,
                    'feature' => 'milking',
                    'farmer_id' => $farmerId,
                    'details' => json_encode(['name' => $resolvedName, 'phone' => $local, 'amcos_uuid' => $amcos->uuid]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('users')->where('id', $farmerUserId)->where('roleId', 0)->update(['roleId' => $farmerId]);
            if (! $identity && ! DB::table('phone_identities')->where('user_id', $farmerUserId)->exists()) {
                DB::table('phone_identities')->insert([
                    'user_id' => $farmerUserId,
                    'phone' => '+255' . $suffix,
                    'display_name' => $resolvedName,
                    'pin_hash' => Hash::make(Str::random(12)),
                    'pin_pending' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $mergedCentres = [];
            $memberNumber = $membership->member_number
                ?? ($amcos->amcos_code . '-F-' . str_pad((string) (DB::table('cooperative_farmer_memberships')->count() + 1), 4, '0', STR_PAD_LEFT));

            DB::table('cooperative_farmer_memberships')->updateOrInsert(
                ['farmer_user_id' => $farmerUserId],
                [
                    'uuid' => $membership->uuid ?? $data['uuid'],
                    'mcu_uuid' => $amcos->mcu_uuid,
                    'amcos_uuid' => $amcos->uuid,
                    'farmer_id' => $farmerId,
                    'member_number' => $memberNumber,
                    'farmer_name' => $resolvedName,
                    'farmer_phone' => $local,
                    'enrolled_centre_uuids' => json_encode($mergedCentres),
                    'status' => 'active',
                    'otp_code' => null,
                    'verified_at' => now(),
                    'enrolled_by_user_id' => $request->user()->id,
                    'updated_at' => now(),
                    'created_at' => $membership->created_at ?? now(),
                ]
            );

            $farmerNo = DB::table('farmers')->where('id', $farmerId)->value('farmerNo');
            if (! $membership || $membership->amcos_uuid !== $amcos->uuid || $membership->status !== 'active') {
                app(MembershipRegistrationSms::class)->amcosFarmerRegistered((int) $farmerUserId, '+255'.$suffix, $resolvedName, $amcos->name, $memberNumber, $farmerNo, $membership->uuid ?? $data['uuid']);
            }

            return response()->json([
                'status' => true,
                'message' => $farmer || $membership ? 'Farmer connected to AMCOS.' : 'Farmer created and connected to AMCOS.',
                'data' => [
                    'result' => $farmer || $membership ? 'connected' : 'created',
                    'membership_uuid' => $membership->uuid ?? $data['uuid'],
                    'member_number' => $memberNumber,
                    'farmer_no' => $farmerNo,
                    'farmer_user_id' => $farmerUserId,
                    'farmer_name' => $resolvedName,
                    'farmer_phone' => $local,
                    'status' => 'active',
                ],
            ]);
        });
    }

    /**
     * Only the details that identify a person decide whether a known phone is
     * the same farmer. A detail is compared when both sides have it; a blank
     * side is filled in later instead of blocking the match. Everything else
     * (middle name, other contacts, location, education...) never blocks a match.
     *
     * @return array<int, array{en: string, sw: string}>
     */
    private function detailDifferences(object $farmer, array $data): array
    {
        $text = fn ($value) => mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $value)));
        $code = fn ($value) => strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $value));
        $checks = [
            'first_name' => [$farmer->firstName ?? '', $text, 'first name', 'jina la kwanza'],
            'last_name' => [$farmer->surname ?? '', $text, 'surname', 'jina la ukoo'],
            'gender' => [$farmer->gender ?? '', $text, 'gender', 'jinsia'],
            'identity_number' => [$farmer->identityNumber ?? '', $code, 'identity number', 'namba ya kitambulisho'],
            'email' => [$farmer->email ?? '', $text, 'email', 'barua pepe'],
        ];
        $differences = [];
        foreach ($checks as $key => [$stored, $normalize, $english, $swahili]) {
            $submitted = $normalize($data[$key] ?? '');
            $existing = $normalize($stored);
            if ($submitted !== '' && $existing !== '' && $submitted !== $existing) {
                $differences[] = ['en' => $english, 'sw' => $swahili];
            }
        }

        return $differences;
    }

    private function localized(Request $request, string $english, string $swahili): string
    {
        $language = strtolower((string) $request->header('Accept-Language', 'en'));

        return str_starts_with($language, 'sw') ? $swahili : $english;
    }

    private function enrollConflict(Request $request, string $phone, string $existingName, string $reason, ?string $amcosName = null, ?object $farmer = null, array $differences = []): JsonResponse
    {
        if ($reason === 'other_amcos') {
            $message = $this->localized(
                $request,
                "{$existingName} ({$phone}) is already linked to another AMCOS ({$amcosName}).",
                "Mkulima {$existingName} ({$phone}) tayari ameunganishwa na AMCOS nyingine ({$amcosName})."
            );
        } else {
            $labels = implode(', ', array_map(
                fn (array $difference) => $this->localized($request, $difference['en'], $difference['sw']),
                $differences
            ));
            $message = $this->localized(
                $request,
                "This phone is already registered. These details do not match and were not synced: {$labels}.",
                "Namba hii tayari ipo. Taarifa hizi hazifanani na hazijasawazishwa: {$labels}."
            );
        }

        return response()->json([
            'status' => false,
            'code' => 'conflict',
            'message' => $message,
            'data' => [
                'reason' => $reason,
                'phone' => $phone,
                'existing_name' => $existingName,
                'existing_gender' => $farmer->gender ?? null,
                'existing_farmer_type' => $farmer->farmerType ?? null,
                'amcos_name' => $amcosName,
                'differences' => array_column($differences, 'en'),
            ],
        ], 409);
    }
}
