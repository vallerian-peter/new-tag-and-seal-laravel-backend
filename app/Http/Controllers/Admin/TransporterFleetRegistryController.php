<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopesTransporters;
use App\Http\Controllers\Controller;
use App\Models\TransporterCompany;
use App\Models\TransporterDriver;
use App\Models\TransporterVehicle;
use App\Services\Dairy\TransporterFleetService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Portal registry of transporter vehicles and drivers, with the same writes
 * the transporter app uses. Delete archives (is_active = false) and can be restored.
 */
class TransporterFleetRegistryController extends Controller
{
    use ScopesTransporters;

    public function __construct(private readonly TransporterFleetService $fleet) {}

    public function vehicles(Request $request): JsonResponse
    {
        $uuids = $this->scopedUuids($request);
        $base = DB::table('transporter_vehicles as v')->whereIn('v.transporter_company_uuid', $uuids);
        $query = DB::table('transporter_vehicles as v')
            ->join('transporter_companies as c', 'c.uuid', '=', 'v.transporter_company_uuid')
            ->leftJoin('transporter_drivers as d', 'd.assigned_vehicle_uuid', '=', 'v.uuid')
            ->whereIn('v.transporter_company_uuid', $uuids)
            ->select('v.*', 'c.name as company_name', 'd.uuid as assigned_driver_uuid', 'd.first_name as driver_first_name', 'd.middle_name as driver_middle_name', 'd.last_name as driver_last_name');

        return $this->page($request, $query, 'v', 'v.registration_number', ['v.registration_number', 'v.vehicle_type', 'v.color', 'c.name'], [
            'total' => (clone $base)->count(),
            'active' => (clone $base)->where('v.is_active', true)->count(),
            'assigned' => (clone $base)->where('v.is_active', true)
                ->whereExists(fn ($q) => $q->from('transporter_drivers as d')->whereColumn('d.assigned_vehicle_uuid', 'v.uuid'))->count(),
            'capacity' => (float) (clone $base)->where('v.is_active', true)->sum('v.tanker_capacity_liters'),
        ]);
    }

    public function drivers(Request $request): JsonResponse
    {
        $uuids = $this->scopedUuids($request);
        $base = DB::table('transporter_drivers as d')->whereIn('d.transporter_company_uuid', $uuids);
        $query = DB::table('transporter_drivers as d')
            ->join('transporter_companies as c', 'c.uuid', '=', 'd.transporter_company_uuid')
            ->leftJoin('phone_identities as pi', 'pi.user_id', '=', 'd.user_id')
            ->leftJoin('transporter_vehicles as v', 'v.uuid', '=', 'd.assigned_vehicle_uuid')
            ->whereIn('d.transporter_company_uuid', $uuids)
            ->select('d.*', 'c.name as company_name', 'pi.display_name', 'pi.pin_pending', 'v.registration_number as assigned_vehicle_plate');

        return $this->page($request, $query, 'd', 'd.first_name', ['d.first_name', 'd.middle_name', 'd.last_name', 'd.phone', 'd.driver_license_number', 'c.name'], [
            'total' => (clone $base)->count(),
            'active' => (clone $base)->where('d.is_active', true)->count(),
            'assigned' => (clone $base)->where('d.is_active', true)->whereNotNull('d.assigned_vehicle_uuid')->count(),
            'awaiting_login' => (clone $base)->where('d.is_active', true)
                ->join('phone_identities as pi', 'pi.user_id', '=', 'd.user_id')->where('pi.pin_pending', true)->count(),
        ]);
    }

    public function storeVehicle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_uuid' => ['required', 'string'],
            ...TransporterFleetService::vehicleRules(),
        ]);
        $company = $this->approvedCompany($request, $data['company_uuid']);
        [$vehicle] = DB::transaction(fn () => $this->fleet->saveVehicle($request, $company, (string) Str::uuid(), $data));

        return response()->json(['status' => true, 'data' => $vehicle], 201);
    }

    public function updateVehicle(Request $request, string $uuid): JsonResponse
    {
        $vehicle = $this->vehicle($request, $uuid);
        $data = $request->validate(TransporterFleetService::vehicleRules(partial: true));
        [$vehicle] = DB::transaction(fn () => $this->fleet->saveVehicle($request, $vehicle->transporter, $vehicle->uuid, $data));

        return response()->json(['status' => true, 'data' => $vehicle]);
    }

    public function archiveVehicle(Request $request, string $uuid): JsonResponse
    {
        DB::transaction(fn () => $this->fleet->setVehicleActive($this->vehicle($request, $uuid), false));

        return response()->json(['status' => true]);
    }

    public function restoreVehicle(Request $request, string $uuid): JsonResponse
    {
        $this->fleet->setVehicleActive($this->vehicle($request, $uuid), true);

        return response()->json(['status' => true]);
    }

    public function storeDriver(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_uuid' => ['required', 'string'],
            'phone' => ['required', 'string', 'max:32'],
            ...TransporterFleetService::driverRules(),
        ]);
        $company = $this->approvedCompany($request, $data['company_uuid']);
        $driver = DB::transaction(fn () => $this->fleet->createDriver($request, $company, (string) Str::uuid(), $data));

        return response()->json(['status' => true, 'data' => $driver], 201);
    }

    public function updateDriver(Request $request, string $uuid): JsonResponse
    {
        $driver = $this->driver($request, $uuid);
        $data = $request->validate(TransporterFleetService::driverRules(partial: true));

        return response()->json(['status' => true, 'data' => DB::transaction(fn () => $this->fleet->updateDriver($driver, $data))]);
    }

    public function archiveDriver(Request $request, string $uuid): JsonResponse
    {
        $this->fleet->setDriverActive($this->driver($request, $uuid), false);

        return response()->json(['status' => true]);
    }

    public function restoreDriver(Request $request, string $uuid): JsonResponse
    {
        $this->fleet->setDriverActive($this->driver($request, $uuid), true);

        return response()->json(['status' => true]);
    }

    private function page(Request $request, Builder $query, string $alias, string $nameColumn, array $searchable, array $stats): JsonResponse
    {
        $data = $request->validate([
            'search' => 'nullable|string|max:120',
            'company' => 'nullable|string|max:64',
            'tab' => ['nullable', Rule::in(['all', 'archived'])],
            'sort' => ['nullable', Rule::in(['desc', 'asc', 'alpha-asc', 'alpha-desc'])],
            'per_page' => 'nullable|integer|min:10|max:100',
            'page' => 'nullable|integer|min:1',
        ]);
        $query->where("{$alias}.is_active", ($data['tab'] ?? 'all') !== 'archived');
        if (! empty($data['company'])) {
            $query->where('c.uuid', $data['company']);
        }
        if (! empty($data['search'])) {
            $term = '%'.trim($data['search']).'%';
            $query->where(function ($q) use ($searchable, $term) {
                foreach ($searchable as $column) {
                    $q->orWhere($column, 'like', $term);
                }
            });
        }
        [$column, $direction] = match ($data['sort'] ?? 'desc') {
            'asc' => ["{$alias}.created_at", 'asc'],
            'alpha-asc' => [$nameColumn, 'asc'],
            'alpha-desc' => [$nameColumn, 'desc'],
            default => ["{$alias}.created_at", 'desc'],
        };
        $page = $query->orderBy($column, $direction)->orderBy("{$alias}.id", $direction)->paginate($data['per_page'] ?? 10);

        return response()->json([
            'status' => true,
            'data' => $page->items(),
            'meta' => [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'stats' => $stats,
            ],
        ]);
    }

    private function approvedCompany(Request $request, string $uuid): TransporterCompany
    {
        $company = $this->find($request, $uuid);
        abort_unless($company->approval_status === 'approved' && $company->is_active && ! $company->archived_at, 422,
            'Vehicles and drivers can only be added to an approved transporter.');

        return $company;
    }

    private function vehicle(Request $request, string $uuid): TransporterVehicle
    {
        $vehicle = TransporterVehicle::query()->where('uuid', $uuid)->firstOrFail();
        $this->find($request, $vehicle->transporter_company_uuid);

        return $vehicle;
    }

    private function driver(Request $request, string $uuid): TransporterDriver
    {
        $driver = TransporterDriver::query()->where('uuid', $uuid)->firstOrFail();
        $this->find($request, $driver->transporter_company_uuid);

        return $driver;
    }
}
