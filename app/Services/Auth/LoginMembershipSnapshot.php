<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Dynamic\FarmAccessService;
use App\Services\Hiring\FarmOwnership;
use App\Services\Hiring\WorkerPermissions;
use Illuminate\Support\Facades\DB;

class LoginMembershipSnapshot
{
    public function forUser(User $user): array
    {
        $staffMemberships = DB::table('cooperative_user_memberships as um')
            ->leftJoin('cooperative_amcos as a', 'a.uuid', '=', 'um.amcos_uuid')
            ->leftJoin('cooperative_mcus as m', 'm.uuid', '=', 'um.mcu_uuid')
            ->where('um.user_id', $user->id)
            ->orderBy('a.name')
            ->get([
                'um.uuid',
                'um.amcos_uuid',
                'um.mcu_uuid',
                'um.role',
                'um.status',
                'um.assigned_centre_uuids',
                'a.name as amcos_name',
                'a.is_active as amcos_is_active',
                'm.name as mcu_name',
            ])
            ->map(function ($membership): array {
                $membership->assigned_centre_uuids = json_decode(
                    (string) ($membership->assigned_centre_uuids ?? '[]'),
                    true,
                ) ?: [];

                return (array) $membership;
            })
            ->all();

        $staffEntryMemberships = DB::table('cooperative_user_memberships as um')
            ->leftJoin('cooperative_amcos as a', 'a.uuid', '=', 'um.amcos_uuid')
            ->where('um.user_id', $user->id)
            ->whereIn('um.status', ['pending', 'approved', 'inactive'])
            ->orderBy('a.name')
            ->get([
                'um.amcos_uuid',
                'um.status',
                'a.name as amcos_name',
                'a.is_active as amcos_is_active',
            ]);

        $cooperativeFarmers = DB::table('cooperative_farmer_memberships as fm')
            ->leftJoin('cooperative_amcos as a', 'a.uuid', '=', 'fm.amcos_uuid')
            ->where('fm.farmer_user_id', $user->id)
            ->orderBy('a.name')
            ->get([
                'fm.uuid',
                'fm.mcu_uuid',
                'fm.amcos_uuid',
                'fm.status',
                'fm.enrolled_centre_uuids',
                'a.name as amcos_name',
                'a.is_active as amcos_is_active',
            ])
            ->map(function ($membership): array {
                $membership->enrolled_centre_uuids = json_decode(
                    (string) ($membership->enrolled_centre_uuids ?? '[]'),
                    true,
                ) ?: [];

                return (array) $membership;
            })
            ->all();

        $featureMemberships = DB::table('feature_memberships')
            ->where('user_id', $user->id)
            ->orderBy('feature')
            ->get(['feature', 'organization_id', 'details'])
            ->map(function ($membership): array {
                return [
                    'feature' => $membership->feature,
                    'organization_id' => $membership->organization_id,
                    'details' => json_decode((string) $membership->details, true) ?: [],
                ];
            })
            ->all();

        $farmAccess = app(FarmAccessService::class);
        $farmUuids = $farmAccess->accessibleFarmUuids($user)
            ?? DB::table('farms')->pluck('uuid')->all();
        $farmPermissions = [];
        foreach ($farmUuids as $farmUuid) {
            $permissions = [];
            foreach ([...WorkerPermissions::catalog(), 'events.create'] as $permission) {
                if ($farmAccess->can($user, $farmUuid, $permission)) {
                    $permissions[] = $permission;
                }
            }
            $farmPermissions[$farmUuid] = $permissions;
        }

        $phoneIdentity = DB::table('phone_identities')
            ->where('user_id', $user->id)
            ->first(['phone', 'display_name']);

        return [
            'features' => $featureMemberships,
            'cooperativeStaff' => $staffMemberships,
            'cooperativeFarmers' => $cooperativeFarmers,
            'cooperativeStaffEntry' => [
                'phone' => $phoneIdentity?->phone,
                'display_name' => $phoneIdentity?->display_name,
                'cooperatives' => DB::table('cooperative_amcos')
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['uuid', 'name'])
                    ->all(),
                'identity_card_types' => DB::table('identity_card_types')
                    ->orderBy('id')
                    ->get(['id', 'name'])
                    ->all(),
                'memberships' => $staffEntryMemberships->all(),
            ],
            'transportation' => [
                'drivers' => DB::table('transporter_drivers as d')
                    ->join('transporter_companies as c', 'c.uuid', '=', 'd.transporter_company_uuid')
                    ->where('d.user_id', $user->id)
                    ->orderBy('c.name')
                    ->get([
                        'd.uuid',
                        'd.transporter_company_uuid',
                        'd.approval_status',
                        'd.is_active',
                        'c.name as transporter_name',
                        'c.approval_status as transporter_approval_status',
                        'c.is_active as transporter_is_active',
                    ])
                    ->all(),
                'companies' => DB::table('transporter_companies as c')
                    ->leftJoin('cooperative_mcus as m', 'm.uuid', '=', 'c.mcu_uuid')
                    ->where('c.manager_user_id', $user->id)
                    ->whereNull('c.archived_at')
                    ->orderBy('c.name')
                    ->get([
                        'c.uuid',
                        'c.name',
                        'c.approval_status',
                        'c.is_active',
                        'c.mcu_uuid',
                        'm.name as mcu_name',
                        'c.approval_remarks',
                        'c.rejected_at',
                    ])
                    ->all(),
                // Unions a transporter can apply under, so registration works offline after login.
                'mcus' => TransporterMcuOptions::all(),
            ],
            'factories' => DB::table('factory_operators as fo')
                ->join('dairy_factories as f', 'f.uuid', '=', 'fo.factory_uuid')
                ->where('fo.user_id', $user->id)
                ->where('fo.is_active', true)
                ->orderBy('f.name')
                ->get([
                    'fo.uuid',
                    'fo.factory_uuid',
                    'fo.staff_id',
                    'fo.role',
                    'f.name as factory_name',
                    'f.factory_code',
                    'f.approval_status as factory_approval_status',
                    'f.is_active as factory_is_active',
                ])
                ->all(),
            'farmAccess' => [
                'data' => (object) $farmPermissions,
                'farmerIds' => app(FarmOwnership::class)->farmerIds($user),
            ],
        ];
    }
}
