<?php

namespace App\Services\Dairy;

use App\Services\FarmerNumberGenerator;
use App\Services\FarmerProfileFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Offline sync for an AMCOS farmer.
 * UPDATE writes the sent details. DELETE inserts the farmer when missing,
 * assigns this AMCOS, then archives the membership. Rows are never removed.
 */
class CooperativeFarmerMutation
{
    public function apply(Request $request, array $data, object $amcos, string $action): JsonResponse
    {
        $suffix = substr(preg_replace('/[^0-9]/', '', (string) $data['phone']), -9);
        if (strlen($suffix) < 9) {
            return response()->json(['status' => false, 'message' => 'Invalid phone number.'], 422);
        }
        $local = '0'.$suffix;

        $profile = FarmerProfileFields::columns($data, true);

        return DB::transaction(function () use ($request, $data, $amcos, $action, $suffix, $local, $profile) {
            $membership = DB::table('cooperative_farmer_memberships')->where('uuid', $data['uuid'])->first();
            $farmer = ($membership && $membership->farmer_id)
                ? DB::table('farmers')->where('id', $membership->farmer_id)->first()
                : null;
            $phoneOwner = DB::table('farmers')
                ->where(function ($query) use ($suffix) {
                    $query->where('phone1', 'like', "%{$suffix}%")
                        ->orWhere('phone2', 'like', "%{$suffix}%");
                })
                ->orderByRaw('CASE WHEN phone1 LIKE ? THEN 0 ELSE 1 END', ["%{$suffix}%"])
                ->orderBy('id')
                ->first();

            if ($farmer && $phoneOwner && (int) $farmer->id !== (int) $phoneOwner->id) {
                return $this->conflict($this->text(
                    $request,
                    'This phone is already registered to another farmer.',
                    'Namba hii tayari imesajiliwa kwa mkulima mwingine.'
                ));
            }
            $farmer ??= $phoneOwner;

            $farmerUserId = $membership?->farmer_user_id;
            if (! $farmerUserId && $farmer) {
                $identity = DB::table('phone_identities')->where('phone', 'like', "%{$suffix}%")->first();
                $farmerUserId = $identity?->user_id
                    ?? (DB::table('feature_memberships')->where('farmer_id', $farmer->id)->value('user_id')
                        ?? DB::table('users')->where('role', 'farmer')->where('roleId', $farmer->id)->value('id')
                        ?? $farmer->createdBy);
            }

            if ($farmerUserId) {
                $held = DB::table('cooperative_farmer_memberships as fm')
                    ->leftJoin('cooperative_amcos as a', 'a.uuid', '=', 'fm.amcos_uuid')
                    ->where('fm.farmer_user_id', $farmerUserId)
                    ->select('fm.*', 'a.name as amcos_name')
                    ->first();
                if ($held && $held->amcos_uuid !== $amcos->uuid) {
                    $name = trim(implode(' ', array_filter([$farmer->firstName ?? '', $farmer->middleName ?? '', $farmer->surname ?? ''])));

                    return $this->conflict($this->text(
                        $request,
                        ($name !== '' ? $name : $local)." ({$local}) is already linked to another AMCOS ({$held->amcos_name}).",
                        'Mkulima '.($name !== '' ? $name : $local)." ({$local}) tayari ameunganishwa na AMCOS nyingine ({$held->amcos_name})."
                    ));
                }
                $membership = $membership ?? $held;
            }

            if ($action === 'UPDATE' && $membership && $membership->amcos_uuid === $amcos->uuid && $membership->status === 'archived') {
                return $this->conflict($this->text(
                    $request,
                    'This farmer was removed from this AMCOS. The changes were not synced.',
                    'Mkulima huyu ameondolewa kwenye AMCOS hii. Mabadiliko hayajasawazishwa.'
                ));
            }

            if ($membership && $membership->amcos_uuid !== $amcos->uuid) {
                return $this->conflict($this->text(
                    $request,
                    "{$membership->farmer_name} ({$local}) is already linked to another AMCOS.",
                    "Mkulima {$membership->farmer_name} ({$local}) tayari ameunganishwa na AMCOS nyingine."
                ));
            }

            if (! $farmerUserId) {
                $username = 'farmer_'.$suffix;
                if (DB::table('users')->where('username', $username)->exists()) {
                    $username .= '_'.rand(10, 99);
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

            $resolvedName = trim(implode(' ', array_filter([
                $data['first_name'] ?? '', $data['middle_name'] ?? '', $data['last_name'] ?? '',
            ])));
            if ($resolvedName === '' && $farmer) {
                $resolvedName = trim(implode(' ', array_filter([$farmer->firstName, $farmer->middleName ?? '', $farmer->surname])));
            }
            if ($resolvedName === '') {
                $resolvedName = 'Mkulima '.$local;
            }

            $farmerId = $farmer?->id;
            if (! $farmerId) {
                $farmerId = DB::table('farmers')->insertGetId([
                    ...FarmerProfileFields::forTable(Arr::except($profile, FarmerProfileFields::REQUIRED_COLUMNS)),
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
            } elseif ($action === 'UPDATE') {
                // Optional columns are cleared when sent empty; required ones are kept.
                $edits = ['phone1' => $local, 'updated_at' => now()];
                foreach (FarmerProfileFields::forTable($profile) as $column => $value) {
                    if ($value === null && in_array($column, FarmerProfileFields::REQUIRED_COLUMNS, true)) {
                        continue;
                    }
                    $edits[$column] = $value;
                }
                DB::table('farmers')->where('id', $farmerId)->update($edits);
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

            $status = $action === 'DELETE' ? 'archived' : 'active';
            $memberNumber = $membership?->member_number
                ?? ($amcos->amcos_code.'-F-'.str_pad((string) (DB::table('cooperative_farmer_memberships')->count() + 1), 4, '0', STR_PAD_LEFT));

            DB::table('cooperative_farmer_memberships')->updateOrInsert(
                ['farmer_user_id' => $farmerUserId],
                [
                    'uuid' => $membership?->uuid ?? $data['uuid'],
                    'mcu_uuid' => $amcos->mcu_uuid ?? null,
                    'amcos_uuid' => $amcos->uuid,
                    'farmer_id' => $farmerId,
                    'member_number' => $memberNumber,
                    'farmer_name' => $resolvedName,
                    'farmer_phone' => $local,
                    'enrolled_centre_uuids' => $membership?->enrolled_centre_uuids ?? json_encode([]),
                    'status' => $status,
                    'otp_code' => null,
                    'verified_at' => now(),
                    'enrolled_by_user_id' => $request->user()->id,
                    'updated_at' => now(),
                    'created_at' => $membership?->created_at ?? now(),
                ]
            );

            return response()->json([
                'status' => true,
                'message' => $this->text(
                    $request,
                    $action === 'DELETE' ? 'Farmer archived for this AMCOS.' : 'Farmer details updated.',
                    $action === 'DELETE' ? 'Mkulima amewekwa kwenye kumbukumbu za AMCOS hii.' : 'Taarifa za mkulima zimesasishwa.'
                ),
                'data' => [
                    'result' => $action === 'DELETE' ? 'archived' : 'updated',
                    'membership_uuid' => $membership?->uuid ?? $data['uuid'],
                    'member_number' => $memberNumber,
                    'farmer_no' => DB::table('farmers')->where('id', $farmerId)->value('farmerNo'),
                    'farmer_user_id' => $farmerUserId,
                    'farmer_name' => $resolvedName,
                    'farmer_phone' => $local,
                    'status' => $status,
                    'amcos_uuid' => $amcos->uuid,
                ],
            ]);
        });
    }

    private function text(Request $request, string $english, string $swahili): string
    {
        $language = strtolower((string) $request->header('Accept-Language', 'en'));

        return str_starts_with($language, 'sw') ? $swahili : $english;
    }

    private function conflict(string $message): JsonResponse
    {
        return response()->json([
            'status' => false,
            'code' => 'conflict',
            'message' => $message,
        ], 409);
    }
}
