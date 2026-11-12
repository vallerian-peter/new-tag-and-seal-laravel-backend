<?php

namespace App\Http\Controllers\Dairy;

use App\Http\Controllers\Controller;
use App\Services\Dairy\CooperativeAccess;
use App\Services\Dairy\MilkDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CooperativeOrganizationController extends Controller
{
    /**
     * GET /api/v2/dairy/hierarchy
     * Returns Measurement Units, MCU -> AMCOS -> Collection Centre (CC) hierarchy, active seasons, and user memberships.
     */
    public function hierarchy(Request $request): JsonResponse
    {
        $user = $request->user();
        $staff = DB::table('cooperative_user_memberships as um')
            ->join('cooperative_amcos as a', 'a.uuid', '=', 'um.amcos_uuid')
            ->where('um.user_id', $user->id)->where('um.status', 'approved')->where('a.is_active', true)
            ->get(['um.*']);
        $farmerMemberships = DB::table('cooperative_farmer_memberships')->where('farmer_user_id', $user->id)
            ->where('status', 'active')->get();
        $amcosUuids = $staff->pluck('amcos_uuid')->merge($farmerMemberships->pluck('amcos_uuid'))->unique()->values()->all();
        $mcuUuids = $staff->where('role', 'mcu_admin')->pluck('mcu_uuid')->filter()->unique()->values()->all();
        $amcosUuids = array_values(array_unique(array_merge($amcosUuids,
            DB::table('cooperative_amcos')->whereIn('mcu_uuid', $mcuUuids)->pluck('uuid')->all())));
        $staffAmcos = $staff->pluck('amcos_uuid')
            ->merge($farmerMemberships->filter(fn ($m) => (json_decode((string) ($m->enrolled_centre_uuids ?? '[]'), true) ?: []) === [])->pluck('amcos_uuid'))
            ->unique()->values()->all();
        $adminAmcos = $staff->whereIn('role', CooperativeAccess::ADMIN_ROLES)->pluck('amcos_uuid')->all();
        $adminAmcos = array_values(array_unique(array_merge($adminAmcos,
            DB::table('cooperative_amcos')->whereIn('mcu_uuid', $mcuUuids)->pluck('uuid')->all())));
        $centreUuids = [];
        foreach ($staff as $membership) {
            $centreUuids = array_merge($centreUuids, json_decode((string) ($membership->assigned_centre_uuids ?? '[]'), true) ?: []);
        }
        foreach ($farmerMemberships as $membership) {
            $centreUuids = array_merge($centreUuids, json_decode((string) ($membership->enrolled_centre_uuids ?? '[]'), true) ?: []);
        }
        $visibleMcuUuids = DB::table('cooperative_amcos')->whereIn('uuid', $amcosUuids)
            ->whereNotNull('mcu_uuid')->pluck('mcu_uuid')->unique()->all();

        $units = DB::table('measurement_units')
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $mcus = DB::table('cooperative_mcus')
            ->whereIn('uuid', $visibleMcuUuids)
            ->orderBy('name')
            ->get()
            ->map(function ($mcu) use ($amcosUuids, $adminAmcos, $staffAmcos, $centreUuids) {
                $mcu->amcos_count = DB::table('cooperative_amcos')->where('mcu_uuid', $mcu->uuid)->whereIn('uuid', $amcosUuids)->count();
                $mcu->centre_count = DB::table('cooperative_collection_centres')->where('mcu_uuid', $mcu->uuid)->whereIn('amcos_uuid', $amcosUuids)
                    ->where(fn ($q) => $q->whereIn('amcos_uuid', $adminAmcos)->orWhereIn('amcos_uuid', $staffAmcos)->orWhereIn('uuid', $centreUuids))->count();

                return $mcu;
            });

        $amcosList = DB::table('cooperative_amcos as a')
            ->whereIn('a.uuid', $amcosUuids)
            ->leftJoin('cooperative_mcus as m', 'm.uuid', '=', 'a.mcu_uuid')
            ->select('a.*', 'm.name as mcu_name', 'm.mcu_code')
            ->orderBy('a.name')
            ->get()
            ->map(function ($amcos) use ($adminAmcos, $staffAmcos, $centreUuids) {
                $amcos->centre_count = DB::table('cooperative_collection_centres')->where('amcos_uuid', $amcos->uuid)
                    ->where(fn ($q) => $q->whereIn('amcos_uuid', $adminAmcos)->orWhereIn('amcos_uuid', $staffAmcos)->orWhereIn('uuid', $centreUuids))->count();
                $amcos->farmer_count = DB::table('cooperative_farmer_memberships')
                    ->where('amcos_uuid', $amcos->uuid)
                    ->where('status', 'active')
                    ->count();
                $amcos->prices = MilkDelivery::currentPrices($amcos->uuid, $amcos->mcu_uuid);

                return $amcos;
            });

        $centres = DB::table('cooperative_collection_centres as c')
            ->whereIn('c.amcos_uuid', $amcosUuids)
            ->where(function ($q) use ($adminAmcos, $staffAmcos, $centreUuids) {
                $q->whereIn('c.amcos_uuid', $adminAmcos)
                    ->orWhereIn('c.amcos_uuid', $staffAmcos)
                    ->orWhereIn('c.uuid', $centreUuids);
            })
            ->leftJoin('cooperative_amcos as a', 'a.uuid', '=', 'c.amcos_uuid')
            ->leftJoin('cooperative_mcus as m', 'm.uuid', '=', 'c.mcu_uuid')
            ->leftJoin('regions as r', 'r.id', '=', 'c.region_id')
            ->leftJoin('districts as d', 'd.id', '=', 'c.district_id')
            ->leftJoin('wards as w', 'w.id', '=', 'c.ward_id')
            ->leftJoin('villages as v', 'v.id', '=', 'c.village_id')
            ->select(
                'c.*',
                'a.name as amcos_name',
                'a.amcos_code',
                'm.name as mcu_name',
                'm.mcu_code',
                'r.name as region_name',
                'd.name as district_name',
                'w.name as ward_name',
                'v.name as village_name'
            )
            ->orderBy('c.name')
            ->get();
        $stocks = DB::table('cooperative_centre_stocks')
            ->whereIn('centre_uuid', $centres->pluck('uuid'))
            ->orderBy('id')
            ->get(['centre_uuid', 'commodity_code', 'unit', 'current_balance', 'total_collected', 'total_dispatched'])
            ->groupBy('centre_uuid');
        $centres = $centres->map(function ($centre) use ($stocks) {
            $centre->stocks = ($stocks[$centre->uuid] ?? collect())->map(fn ($stock) => collect($stock)->except('centre_uuid'))->values();

            return $centre;
        });

        $commodities = DB::table('cooperative_commodities')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['code', 'name', 'name_sw', 'default_unit', 'icon']);

        $seasons = DB::table('cooperative_seasons as s')
            ->where(function ($q) use ($amcosUuids, $visibleMcuUuids) {
                $q->whereIn('s.amcos_uuid', $amcosUuids)
                    ->orWhere(function ($sub) use ($visibleMcuUuids) {
                        $sub->whereNull('s.amcos_uuid')->whereIn('s.mcu_uuid', $visibleMcuUuids);
                    });
            })
            ->leftJoin('cooperative_mcus as m', 'm.uuid', '=', 's.mcu_uuid')
            ->leftJoin('cooperative_amcos as a', 'a.uuid', '=', 's.amcos_uuid')
            ->leftJoin('measurement_units as u', 'u.code', '=', 's.unit')
            ->select('s.*', 'm.name as mcu_name', 'a.name as amcos_name', 'u.symbol as unit_symbol', 'u.name as unit_name')
            ->orderByDesc('s.is_active')
            ->orderByDesc('s.start_date')
            ->get();

        $myStaffMemberships = DB::table('cooperative_user_memberships as um')
            ->leftJoin('cooperative_amcos as a', 'a.uuid', '=', 'um.amcos_uuid')
            ->leftJoin('cooperative_mcus as m', 'm.uuid', '=', 'um.mcu_uuid')
            ->where('um.user_id', $user->id)
            ->where('a.is_active', true)
            ->select('um.*', 'a.name as amcos_name', 'm.name as mcu_name')
            ->get()
            ->map(function ($row) {
                $row->assigned_centre_uuids = json_decode((string) ($row->assigned_centre_uuids ?? '[]'), true) ?: [];

                return $row;
            });

        $myFarmerMemberships = DB::table('cooperative_farmer_memberships as fm')
            ->leftJoin('cooperative_amcos as a', 'a.uuid', '=', 'fm.amcos_uuid')
            ->leftJoin('cooperative_mcus as m', 'm.uuid', '=', 'fm.mcu_uuid')
            ->where('fm.farmer_user_id', $user->id)
            ->select('fm.*', 'a.name as amcos_name', 'm.name as mcu_name')
            ->get()
            ->map(function ($row) {
                $row->enrolled_centre_uuids = json_decode((string) ($row->enrolled_centre_uuids ?? '[]'), true) ?: [];

                return $row;
            });

        $factories = DB::table('dairy_factories as f')
            ->where(function ($q) use ($amcosUuids, $visibleMcuUuids) {
                $q->whereIn('f.mcu_uuid', $visibleMcuUuids)
                    ->orWhereIn('f.amcos_uuid', $amcosUuids)
                    ->orWhere(function ($sub) {
                        $sub->whereNull('f.mcu_uuid')->whereNull('f.amcos_uuid');
                    });
            })
            ->where('f.is_active', true)
            ->leftJoin('cooperative_mcus as m', 'm.uuid', '=', 'f.mcu_uuid')
            ->leftJoin('cooperative_amcos as a', 'a.uuid', '=', 'f.amcos_uuid')
            ->leftJoin('regions as r', 'r.id', '=', 'f.region_id')
            ->leftJoin('districts as d', 'd.id', '=', 'f.district_id')
            ->select(
                'f.*',
                'm.name as mcu_name',
                'a.name as amcos_name',
                'r.name as region_name',
                'd.name as district_name'
            )
            ->orderBy('f.name')
            ->get();

        $transporters = Schema::hasTable('transporter_companies')
            ? DB::table('transporter_companies as tc')
                ->where('tc.approval_status', 'approved')
                ->where('tc.is_active', true)
                ->where(function ($q) use ($amcosUuids, $visibleMcuUuids) {
                    if (Schema::hasColumn('transporter_companies', 'mcu_uuid')) {
                        $q->whereIn('tc.mcu_uuid', $visibleMcuUuids)
                            ->orWhereIn('tc.amcos_uuid', $amcosUuids)
                            ->orWhereNull('tc.mcu_uuid');
                    }
                })
                ->select('tc.uuid', 'tc.name', 'tc.license_number', 'tc.contact_person', 'tc.phone', 'tc.email', 'tc.approval_status', 'tc.is_active')
                ->orderBy('tc.name')
                ->get()
            : collect();

        $transporterUuids = $transporters->pluck('uuid')->all();

        $vehicles = Schema::hasTable('transporter_vehicles')
            ? DB::table('transporter_vehicles as tv')
                ->whereIn('tv.transporter_company_uuid', $transporterUuids)
                ->where('tv.approval_status', 'approved')
                ->where('tv.is_active', true)
                ->select(
                    'tv.uuid',
                    'tv.transporter_company_uuid',
                    'tv.registration_number',
                    DB::raw('tv.tanker_capacity_liters as capacity'),
                    'tv.make_model',
                    'tv.approval_status',
                    'tv.is_active'
                )
                ->orderBy('tv.registration_number')
                ->get()
            : collect();

        $companiesByUuid = $transporters->keyBy('uuid');
        $vehiclesByUuid = $vehicles->keyBy('uuid');

        // Each driver carries their company and assigned truck so the dispatch
        // screen can show who to call and what tank they bring.
        $drivers = Schema::hasTable('transporter_drivers')
            ? DB::table('transporter_drivers as td')
                ->join('users as u', 'u.id', '=', 'td.user_id')
                ->leftJoin('phone_identities as pi', 'pi.user_id', '=', 'u.id')
                ->whereIn('td.transporter_company_uuid', $transporterUuids)
                ->where('td.approval_status', 'approved')
                ->where('td.is_active', true)
                ->select(
                    'td.uuid',
                    'td.transporter_company_uuid',
                    'td.user_id',
                    'td.driver_license_number',
                    'td.assigned_vehicle_uuid',
                    'td.approval_status',
                    'td.is_active',
                    DB::raw('COALESCE(pi.display_name, u.username) as name'),
                    'pi.phone'
                )
                ->orderBy('name')
                ->get()
                ->map(function ($driver) use ($companiesByUuid, $vehiclesByUuid) {
                    $company = $companiesByUuid->get($driver->transporter_company_uuid);
                    $vehicle = $driver->assigned_vehicle_uuid ? $vehiclesByUuid->get($driver->assigned_vehicle_uuid) : null;
                    $driver->is_active = (bool) $driver->is_active;
                    $driver->assigned_vehicle_plate = $vehicle?->registration_number;
                    $driver->transporter = $company ? [
                        'uuid' => $company->uuid,
                        'name' => $company->name,
                        'phone' => $company->phone,
                    ] : null;
                    $driver->vehicle = $vehicle ? [
                        'uuid' => $vehicle->uuid,
                        'registration_number' => $vehicle->registration_number,
                        'capacity' => (float) $vehicle->capacity,
                        'make_model' => $vehicle->make_model,
                    ] : null;

                    return $driver;
                })
                ->values()
            : collect();

        return response()->json([
            'status' => true,
            'data' => [
                'units' => $units,
                'commodities' => $commodities,
                'mcus' => $mcus,
                'amcos' => $amcosList,
                'centres' => $centres,
                'factories' => $factories,
                'transporters' => $transporters,
                'vehicles' => $vehicles,
                'drivers' => $drivers,
                'seasons' => $seasons,
                'my_staff_memberships' => $myStaffMemberships,
                'my_farmer_memberships' => $myFarmerMemberships,
            ],
        ]);
    }

    /**
     * POST /api/v2/dairy/organizations
     * Register or update an MCU or AMCOS in the MCU -> AMCOS hierarchy.
     */
    public function saveOrganization(Request $request): JsonResponse
    {
        $data = $request->validate([
            'org_type' => 'required|in:mcu,amcos',
            'uuid' => 'nullable|string|max:64',
            'mcu_uuid' => 'nullable|string|max:64',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'default_unit' => 'nullable|string|exists:measurement_units,code',
            'registration_number' => 'nullable|string|max:100',
            'contact_name' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'region_id' => 'nullable|integer',
            'district_id' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        // MCU / AMCOS registration is a portal (system) responsibility.
        if (($request->user()->role ?? null) !== 'systemUser') {
            CooperativeAccess::deny('Only a portal administrator can register organisations.');
        }

        $regionId = $data['region_id'] ?? DB::table('regions')->value('id') ?? 1;
        $districtId = $data['district_id']
            ?? DB::table('districts')->where('regionId', $regionId)->value('id')
            ?? DB::table('districts')->value('id')
            ?? 1;

        if ($data['org_type'] === 'mcu') {
            $uuid = $data['uuid'] ?? (string) Str::uuid();
            $code = $data['code'] ?? ('MCU-'.strtoupper(Str::random(5)));
            DB::table('cooperative_mcus')->updateOrInsert(
                ['uuid' => $uuid],
                [
                    'mcu_code' => $code,
                    'name' => trim($data['name']),
                    'registration_number' => $data['registration_number'] ?? null,
                    'country_id' => DB::table('regions')->where('id', $regionId)->value('countryId'),
                    'region_id' => $regionId,
                    'district_id' => $districtId,
                    'contact_name' => $data['contact_name'] ?? null,
                    'contact_phone' => $data['contact_phone'] ?? null,
                    'is_active' => $data['is_active'] ?? true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            return response()->json([
                'status' => true,
                'message' => 'MCU saved successfully.',
                'data' => DB::table('cooperative_mcus')->where('uuid', $uuid)->first(),
            ], 201);
        }

        $uuid = $data['uuid'] ?? (string) Str::uuid();
        $code = $data['code'] ?? ('AMCOS-'.strtoupper(Str::random(5)));
        $mcuUuid = array_key_exists('mcu_uuid', $data)
            ? $data['mcu_uuid']
            : DB::table('cooperative_amcos')->where('uuid', $uuid)->value('mcu_uuid');

        DB::table('cooperative_amcos')->updateOrInsert(
            ['uuid' => $uuid],
            [
                'amcos_code' => $code,
                'mcu_uuid' => $mcuUuid,
                'name' => trim($data['name']),
                'default_unit' => $data['default_unit'] ?? 'l',
                'registration_number' => $data['registration_number'] ?? null,
                'region_id' => $regionId,
                'district_id' => $districtId,
                'contact_name' => $data['contact_name'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'status' => true,
            'message' => 'AMCOS saved successfully.',
            'data' => DB::table('cooperative_amcos')->where('uuid', $uuid)->first(),
        ], 201);
    }
}
