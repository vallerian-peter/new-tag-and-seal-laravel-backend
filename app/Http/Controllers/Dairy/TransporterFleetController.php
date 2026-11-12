<?php

namespace App\Http\Controllers\Dairy;

use App\Http\Controllers\Controller;
use App\Models\TransporterCompany;
use App\Models\TransporterDriver;
use App\Models\TransporterVehicle;
use App\Services\Dairy\TransporterFleetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The approved transporter's fleet. Every write carries the device's UUID so
 * an upload sent twice from the outbox is answered with the same record.
 */
class TransporterFleetController extends Controller
{
    public function __construct(private readonly TransporterFleetService $fleet) {}

    public function fleet(Request $request): JsonResponse
    {
        $company = $this->company($request);

        return response()->json(['status' => true, 'data' => [
            'company' => $company,
            'vehicles' => $this->vehicles($company->uuid),
            'drivers' => $this->drivers($company->uuid),
        ]]);
    }

    public function trips(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $trips = DB::table('dairy_trips as t')
            ->leftJoin('phone_identities as pi', 'pi.user_id', '=', 't.driver_user_id')
            ->leftJoin('dairy_factories as f', 'f.uuid', '=', 't.destination_factory_uuid')
            ->where('t.transporter_company_uuid', $company->uuid)
            ->select('t.*', 'pi.display_name as driver_name', 'pi.phone as driver_phone', 'f.name as destination_factory_name')
            ->orderByDesc('t.created_at')
            ->limit(200)
            ->get();
        $routes = DB::table('dairy_trip_routes as r')
            ->leftJoin('cooperative_collection_centres as c', 'c.uuid', '=', 'r.centre_uuid')
            ->whereIn('r.dairy_trip_uuid', $trips->pluck('uuid'))
            ->orderBy('r.sequence_order')
            ->get(['r.dairy_trip_uuid', 'r.uuid', 'r.sequence_order', 'r.status', 'r.quantity', 'r.liters', 'r.unit', 'c.name as centre_name'])
            ->groupBy('dairy_trip_uuid');

        return response()->json(['status' => true, 'data' => $trips->map(function ($trip) use ($routes) {
            $trip->routes = $routes->get($trip->uuid, collect())->values();

            return $trip;
        })]);
    }

    public function storeVehicle(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            ...TransporterFleetService::vehicleRules(),
            'driver_uuid' => ['nullable', 'string', 'max:64'],
        ]);

        return DB::transaction(function () use ($request, $company, $data): JsonResponse {
            [$vehicle, $created] = $this->fleet->saveVehicle($request, $company, $data['uuid'], $data);
            if (! empty($data['driver_uuid'])) {
                $this->fleet->assign($company->uuid, $data['driver_uuid'], $vehicle->uuid);
            }

            return response()->json(['status' => true, 'data' => $vehicle->fresh()], $created ? 201 : 200);
        });
    }

    public function storeDriver(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            ...TransporterFleetService::driverRules(),
            'phone' => ['required', 'string', 'max:32'],
            'vehicle_uuid' => ['nullable', 'string', 'max:64'],
        ]);

        return DB::transaction(function () use ($request, $company, $data): JsonResponse {
            $driver = $this->fleet->createDriver($request, $company, $data['uuid'], $data);
            if (! $driver->wasRecentlyCreated) {
                return response()->json(['status' => true, 'data' => $this->driverRow($driver->uuid)]);
            }
            if (! empty($data['vehicle_uuid'])) {
                $this->fleet->assign($company->uuid, $driver->uuid, $data['vehicle_uuid']);
            }

            return response()->json(['status' => true, 'data' => $this->driverRow($driver->uuid)], 201);
        });
    }

    public function assignDriver(Request $request, string $uuid): JsonResponse
    {
        $company = $this->company($request);
        $data = $request->validate(['vehicle_uuid' => ['nullable', 'string', 'max:64']]);
        if (! empty($data['vehicle_uuid'])) {
            $inactive = TransporterDriver::query()->where('uuid', $uuid)->where('is_active', false)->exists()
                || TransporterVehicle::query()->where('uuid', $data['vehicle_uuid'])->where('is_active', false)->exists();
            abort_if($inactive, 422, $this->say($request,
                'Activate the driver and the vehicle before assigning them.', 'Washa dereva na gari kabla ya kuwapangia.'));
        }

        DB::transaction(fn () => $this->fleet->assign($company->uuid, $uuid, $data['vehicle_uuid'] ?? null));

        return response()->json(['status' => true, 'data' => $this->driverRow($uuid)]);
    }

    public function editVehicle(Request $request, string $uuid): JsonResponse
    {
        $company = $this->company($request);
        $this->ownVehicle($request, $company, $uuid);
        $data = $request->validate(TransporterFleetService::vehicleRules(partial: true));
        [$vehicle] = DB::transaction(fn () => $this->fleet->saveVehicle($request, $company, $uuid, $data));

        return response()->json(['status' => true, 'data' => $vehicle]);
    }

    /** Details only; the phone stays because it is the driver's login. */
    public function editDriver(Request $request, string $uuid): JsonResponse
    {
        $company = $this->company($request);
        $driver = $this->ownDriver($request, $company, $uuid);
        $data = $request->validate(TransporterFleetService::driverRules(partial: true));
        DB::transaction(fn () => $this->fleet->updateDriver($driver, $data));

        return response()->json(['status' => true, 'data' => $this->driverRow($uuid)]);
    }

    /** Deactivating takes the driver off the vehicle, as archiving does. */
    public function vehicleStatus(Request $request, string $uuid): JsonResponse
    {
        $company = $this->company($request);
        $vehicle = $this->ownVehicle($request, $company, $uuid);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        DB::transaction(fn () => $this->fleet->setVehicleActive($vehicle, (bool) $data['is_active']));

        return response()->json(['status' => true, 'data' => $vehicle->fresh()]);
    }

    public function driverStatus(Request $request, string $uuid): JsonResponse
    {
        $company = $this->company($request);
        $driver = $this->ownDriver($request, $company, $uuid);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        DB::transaction(fn () => $this->fleet->setDriverActive($driver, (bool) $data['is_active']));

        return response()->json(['status' => true, 'data' => $this->driverRow($uuid)]);
    }

    private function ownVehicle(Request $request, TransporterCompany $company, string $uuid): TransporterVehicle
    {
        $vehicle = TransporterVehicle::query()->where('uuid', $uuid)
            ->where('transporter_company_uuid', $company->uuid)->first();
        abort_unless($vehicle, 404, $this->say($request, 'Vehicle not found.', 'Gari halikupatikana.'));

        return $vehicle;
    }

    private function ownDriver(Request $request, TransporterCompany $company, string $uuid): TransporterDriver
    {
        $driver = TransporterDriver::query()->where('uuid', $uuid)
            ->where('transporter_company_uuid', $company->uuid)->first();
        abort_unless($driver, 404, $this->say($request, 'Driver not found.', 'Dereva hakupatikana.'));

        return $driver;
    }

    public function reviewTrip(Request $request, string $uuid): JsonResponse
    {
        $company = $this->company($request);
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'remarks' => ['nullable', 'string', 'max:500', 'required_if:decision,rejected'],
        ]);
        $trip = DB::table('dairy_trips')->where('uuid', $uuid)
            ->where('transporter_company_uuid', $company->uuid)->first();
        abort_unless($trip, 404, $this->say($request, 'Trip not found.', 'Safari haikupatikana.'));
        if ($trip->approval_status !== 'pending') {
            abort_unless($trip->approval_status === $data['decision'], 409, $this->say($request,
                'This trip was already decided.', 'Safari hii tayari imeamuliwa.'));

            return response()->json(['status' => true, 'message' => "Trip {$data['decision']}."]);
        }
        DB::table('dairy_trips')->where('uuid', $uuid)->update([
            'approval_status' => $data['decision'],
            'status' => $data['decision'] === 'approved' ? 'approved' : 'cancelled',
            'approved_by_user_id' => $request->user()->id,
            'approval_remarks' => $data['remarks'] ?? null,
            'approved_at' => now(),
            'updated_at' => now(),
        ]);
        if ($data['decision'] === 'rejected') {
            DB::table('cooperative_dispatches')->where('trip_uuid', $uuid)->where('status', 'on_trip')
                ->update(['status' => 'dispatched', 'trip_uuid' => null, 'updated_at' => now()]);
        }

        return response()->json(['status' => true, 'message' => "Trip {$data['decision']}."]);
    }

    private function company(Request $request): TransporterCompany
    {
        $company = TransporterCompany::query()
            ->where('manager_user_id', $request->user()->id)
            ->whereNull('archived_at')
            ->first();
        abort_unless($company && $company->approval_status === 'approved' && $company->is_active, 403,
            $this->say($request, 'Your transporter company is not approved yet.', 'Kampuni yako ya usafirishaji bado haijaidhinishwa.'));

        return $company;
    }

    private function vehicles(string $companyUuid)
    {
        return DB::table('transporter_vehicles as v')
            ->leftJoin('transporter_drivers as d', 'd.assigned_vehicle_uuid', '=', 'v.uuid')
            ->leftJoin('phone_identities as pi', 'pi.user_id', '=', 'd.user_id')
            ->where('v.transporter_company_uuid', $companyUuid)
            ->orderByDesc('v.is_active')
            ->orderBy('v.registration_number')
            ->get(['v.*', 'd.uuid as assigned_driver_uuid', 'd.first_name', 'd.middle_name', 'd.last_name', 'pi.display_name'])
            ->map(function ($vehicle) {
                $name = TransporterDriver::fullName((array) $vehicle);
                $vehicle->assigned_driver_name = $vehicle->assigned_driver_uuid ? ($name ?: $vehicle->display_name) : null;
                unset($vehicle->first_name, $vehicle->middle_name, $vehicle->last_name, $vehicle->display_name);

                return $vehicle;
            });
    }

    private function drivers(string $companyUuid, ?string $uuid = null)
    {
        return DB::table('transporter_drivers as d')
            ->leftJoin('phone_identities as pi', 'pi.user_id', '=', 'd.user_id')
            ->leftJoin('transporter_vehicles as v', 'v.uuid', '=', 'd.assigned_vehicle_uuid')
            ->where('d.transporter_company_uuid', $companyUuid)
            ->when($uuid, fn ($q) => $q->where('d.uuid', $uuid))
            ->orderByDesc('d.is_active')
            ->orderBy('d.first_name')
            ->get(['d.*', 'pi.display_name as name', DB::raw('COALESCE(d.phone, pi.phone) as phone'), 'v.registration_number as assigned_vehicle_plate']);
    }

    private function driverRow(string $uuid): ?object
    {
        $companyUuid = TransporterDriver::query()->where('uuid', $uuid)->value('transporter_company_uuid');

        return $companyUuid ? $this->drivers($companyUuid, $uuid)->first() : null;
    }

    private function say(Request $request, string $english, string $swahili): string
    {
        return str_starts_with(strtolower((string) $request->header('Accept-Language', 'en')), 'sw')
            ? $swahili
            : $english;
    }
}
