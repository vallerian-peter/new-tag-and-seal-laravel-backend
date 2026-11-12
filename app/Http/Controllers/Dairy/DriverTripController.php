<?php

namespace App\Http\Controllers\Dairy;

use App\Http\Controllers\Controller;
use App\Models\DairyTrip;
use App\Models\DairyTripRoute;
use App\Models\TransporterDriver;
use App\Models\TransporterVehicle;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A centre dispatch names a driver; the driver sees it as a route, groups routes
 * going to one factory into a trip, and drives the trip once the transporter
 * approves it.
 */
class DriverTripController extends Controller
{
    /** GET /api/v2/dairy/driver/routes */
    public function routes(Request $request): JsonResponse
    {
        $routes = $this->myDispatches($request)
            ->leftJoin('cooperative_collection_centres as c', 'c.uuid', '=', 'd.centre_uuid')
            ->whereIn('d.status', ['dispatched', 'on_trip'])
            ->select(
                'd.uuid as route_uuid',
                'd.dispatch_code as route_code',
                'd.batch_number',
                'c.name as from_center_name',
                'd.centre_uuid',
                'd.destination_name as to_center_name',
                'd.destination_uuid',
                'd.commodity_code',
                'd.quantity',
                'd.unit',
                'd.vehicle_uuid',
                'd.vehicle_plate_number as vehicle_plate',
                'd.transporter_name',
                'd.driver_name',
                'd.dispatch_temperature_celsius as temperature_celsius',
                'd.security_seal_numbers',
                'd.status',
                'd.trip_uuid',
                'd.loaded_at',
                'd.dispatched_at',
                'd.created_at'
            )
            ->orderByDesc('d.dispatched_at')
            ->get()
            ->map(function ($row) {
                $row->security_seal_numbers = json_decode((string) ($row->security_seal_numbers ?? '[]'), true) ?: [];
                $row->is_on_trip = $row->trip_uuid !== null;

                return $row;
            });

        return response()->json(['status' => true, 'data' => $routes]);
    }

    /** GET /api/v2/dairy/driver/trips */
    public function trips(Request $request): JsonResponse
    {
        return response()->json(['status' => true, 'data' => $this->tripsFor($request->user()->id)]);
    }

    /**
     * POST /api/v2/dairy/driver/trips
     * Body: uuid, dispatch_uuids[], vehicle_uuid?, notes?
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            'dispatch_uuids' => ['required', 'array', 'min:1'],
            'dispatch_uuids.*' => ['required', 'string', 'max:64', 'distinct'],
            'vehicle_uuid' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $user = $request->user();

        $existing = DB::table('dairy_trips')->where('uuid', $data['uuid'])->first();
        if ($existing) {
            abort_unless((int) $existing->driver_user_id === (int) $user->id, 409,
                $this->say($request, 'This trip id is already in use.', 'Namba hii ya safari tayari inatumika.'));

            return response()->json(['status' => true, 'data' => $this->tripsFor($user->id, $existing->uuid)->first()]);
        }

        $driver = TransporterDriver::query()->where('user_id', $user->id)->where('is_active', true)->latest('id')->first();
        abort_unless($driver, 403, $this->say($request,
            'You are not registered as an active driver.', 'Hujasajiliwa kama dereva aliye hai.'));

        return DB::transaction(function () use ($request, $data, $user, $driver): JsonResponse {
            $dispatches = $this->myDispatches($request)
                ->whereIn('d.uuid', $data['dispatch_uuids'])
                ->lockForUpdate()
                ->get(['d.*']);
            $this->refuse($request, $dispatches->count() !== count($data['dispatch_uuids']),
                'Some routes are not assigned to you.', 'Baadhi ya njia hazijapewa wewe.');
            $this->refuse($request, $dispatches->contains(fn ($d) => $d->status !== 'dispatched' || $d->trip_uuid !== null),
                'Some routes are already on a trip.', 'Baadhi ya njia tayari ziko kwenye safari.');
            $destinations = $dispatches->pluck('destination_uuid')->unique();
            $this->refuse($request, $destinations->count() !== 1 || $destinations->first() === null,
                'All routes on a trip must go to the same factory.', 'Njia zote za safari moja lazima ziende kiwanda kimoja.');

            $vehicleUuid = $data['vehicle_uuid'] ?? $driver->assigned_vehicle_uuid ?? $dispatches->pluck('vehicle_uuid')->filter()->first();
            $vehicle = $vehicleUuid ? TransporterVehicle::query()->where('uuid', $vehicleUuid)
                ->where('transporter_company_uuid', $driver->transporter_company_uuid)
                ->where('is_active', true)->first() : null;
            $this->refuse($request, ! $vehicle, 'Choose an active vehicle of your transporter.', 'Chagua gari hai la msafirishaji wako.');

            $total = round($dispatches->sum(fn ($d) => (float) $d->quantity), 2);
            $capacity = (float) $vehicle->tanker_capacity_liters;
            $this->refuse($request, $capacity > 0 && $total > $capacity,
                "Vehicle capacity is {$capacity} L but the routes total {$total} L.",
                "Uwezo wa gari ni lita {$capacity} lakini njia zina jumla ya lita {$total}.");

            $trip = DairyTrip::create([
                'uuid' => $data['uuid'],
                'trip_number' => 'TRIP-'.strtoupper(substr(str_replace('-', '', $data['uuid']), 0, 8)),
                'commodity_code' => $dispatches->first()->commodity_code ?? 'milk',
                'transporter_company_uuid' => $driver->transporter_company_uuid,
                'driver_user_id' => $user->id,
                'created_by_user_id' => $user->id,
                'vehicle_uuid' => $vehicle->uuid,
                'vehicle_plate_number' => $vehicle->registration_number,
                'destination_factory_uuid' => $destinations->first(),
                'status' => 'pending_approval',
                'approval_status' => 'pending',
                'phase' => 'PREPARING',
                'notes' => $data['notes'] ?? null,
                'max_tanker_capacity_liters' => $capacity,
                'current_cargo_liters' => $total,
                'total_liters' => $total,
            ]);

            $this->attachRoutes((object) $trip->getAttributes(), $dispatches);

            return response()->json(['status' => true, 'data' => $this->tripsFor($user->id, $trip->uuid)->first()], 201);
        });
    }

    /**
     * POST /api/v2/dairy/driver/trips/{uuid}/routes
     * Body: uuid (this change), dispatch_uuids[]. Adds routes to the same
     * factory to a trip that has not arrived yet.
     */
    public function addRoutes(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'uuid' => ['nullable', 'string', 'max:64'],
            'dispatch_uuids' => ['required', 'array', 'min:1'],
            'dispatch_uuids.*' => ['required', 'string', 'max:64', 'distinct'],
        ]);
        $user = $request->user();
        $trip = $this->myTrip($request, $uuid);

        return DB::transaction(function () use ($request, $data, $user, $trip): JsonResponse {
            $dispatches = $this->myDispatches($request)
                ->whereIn('d.uuid', $data['dispatch_uuids'])
                ->lockForUpdate()
                ->get(['d.*']);
            $this->refuse($request, $dispatches->count() !== count($data['dispatch_uuids']),
                'Some routes are not assigned to you.', 'Baadhi ya njia hazijapewa wewe.');
            $new = $dispatches->filter(fn ($d) => $d->trip_uuid !== $trip->uuid);
            if ($new->isEmpty()) {
                return response()->json(['status' => true, 'data' => $this->tripsFor($user->id, $trip->uuid)->first()]);
            }
            $this->refuse($request, in_array($trip->status, ['arrived', 'completed', 'cancelled'], true)
                || $trip->approval_status === 'rejected',
                'This trip has already ended.', 'Safari hii tayari imeisha.');
            $this->refuse($request, $new->contains(fn ($d) => $d->status !== 'dispatched' || $d->trip_uuid !== null),
                'Some routes are already on a trip.', 'Baadhi ya njia tayari ziko kwenye safari.');
            $this->refuse($request, $new->contains(fn ($d) => $d->destination_uuid !== $trip->destination_factory_uuid),
                'All routes on a trip must go to the same factory.', 'Njia zote za safari moja lazima ziende kiwanda kimoja.');

            $total = round((float) $trip->total_liters + $new->sum(fn ($d) => (float) $d->quantity), 2);
            $capacity = (float) $trip->max_tanker_capacity_liters;
            $this->refuse($request, $capacity > 0 && $total > $capacity,
                "Vehicle capacity is {$capacity} L but the routes total {$total} L.",
                "Uwezo wa gari ni lita {$capacity} lakini njia zina jumla ya lita {$total}.");

            $this->attachRoutes($trip, $new);
            DB::table('dairy_trips')->where('uuid', $trip->uuid)->update([
                'total_liters' => $total, 'current_cargo_liters' => $total, 'updated_at' => now(),
            ]);

            return response()->json(['status' => true, 'data' => $this->tripsFor($user->id, $trip->uuid)->first()]);
        });
    }

    private function attachRoutes(object $trip, Collection $dispatches): void
    {
        $moving = $trip->status === 'in_transit';
        $sequence = (int) DB::table('dairy_trip_routes')->where('dairy_trip_uuid', $trip->uuid)->max('sequence_order') + 1;
        foreach ($dispatches->sortBy('dispatched_at') as $dispatch) {
            DairyTripRoute::create([
                'dairy_trip_uuid' => $trip->uuid,
                'dispatch_uuid' => $dispatch->uuid,
                'centre_uuid' => $dispatch->centre_uuid,
                'commodity_code' => $dispatch->commodity_code,
                'quantity' => $dispatch->quantity,
                'unit' => $dispatch->unit,
                'liters' => $dispatch->quantity,
                'temperature_celsius' => $dispatch->dispatch_temperature_celsius ?? 4.0,
                'security_seal_numbers' => json_decode((string) ($dispatch->security_seal_numbers ?? '[]'), true) ?: [],
                'destination_factory_uuid' => $trip->destination_factory_uuid,
                'sequence_order' => $sequence++,
                'stop_type' => 'pickup',
                'status' => $moving ? 'in_transit' : 'loaded',
                'phase' => $moving ? 'IN_TRANSIT' : 'LOADED',
                'added_mid_trip' => $moving,
                'added_at' => $moving ? now() : null,
            ]);
        }

        DB::table('cooperative_dispatches')->whereIn('uuid', $dispatches->pluck('uuid'))
            ->update(['status' => 'on_trip', 'trip_uuid' => $trip->uuid, 'updated_at' => now()]);
    }

    /** POST /api/v2/dairy/driver/trips/{uuid}/start */
    public function start(Request $request, string $uuid): JsonResponse
    {
        $trip = $this->myTrip($request, $uuid);
        if ($trip->status === 'in_transit') {
            return response()->json(['status' => true, 'message' => 'Trip started.']);
        }
        $this->refuse($request, $trip->approval_status !== 'approved',
            'The transporter has not approved this trip yet.', 'Msafirishaji bado hajaidhinisha safari hii.');
        abort_if(in_array($trip->status, ['arrived', 'completed', 'cancelled'], true), 409,
            $this->say($request, 'This trip has already ended.', 'Safari hii tayari imeisha.'));

        DB::transaction(function () use ($trip): void {
            DB::table('dairy_trips')->where('uuid', $trip->uuid)->update([
                'status' => 'in_transit', 'phase' => 'IN_TRANSIT', 'started_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('dairy_trip_routes')->where('dairy_trip_uuid', $trip->uuid)
                ->update(['status' => 'in_transit', 'phase' => 'IN_TRANSIT', 'updated_at' => now()]);
        });

        return response()->json(['status' => true, 'message' => 'Trip started.']);
    }

    /**
     * POST /api/v2/dairy/driver/trips/{uuid}/arrive
     * Body: welcoming_operator_uuid (the factory member taking the handover), notes?
     */
    public function arrive(Request $request, string $uuid): JsonResponse
    {
        $trip = $this->myTrip($request, $uuid);
        if (in_array($trip->status, ['arrived', 'completed'], true)) {
            return response()->json(['status' => true, 'message' => 'Trip arrived at destination.']);
        }
        $this->refuse($request, $trip->status !== 'in_transit',
            'Start the trip before marking it arrived.', 'Anza safari kabla ya kuweka imefika.');
        $data = $request->validate([
            'welcoming_operator_uuid' => ['required', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->refuse($request, ! DB::table('factory_operators')
            ->where('uuid', $data['welcoming_operator_uuid'])
            ->where('factory_uuid', $trip->destination_factory_uuid)
            ->where('is_active', true)->exists(),
            'Choose an active member of the destination factory.', 'Chagua mhudumu hai wa kiwanda unachofikisha.');

        DB::transaction(function () use ($trip, $data): void {
            DB::table('dairy_trips')->where('uuid', $trip->uuid)->update([
                'status' => 'arrived', 'phase' => 'ARRIVED', 'arrived_at' => now(),
                'welcoming_operator_uuid' => $data['welcoming_operator_uuid'],
                'arrival_notes' => $data['notes'] ?? null, 'updated_at' => now(),
            ]);
            DB::table('dairy_trip_routes')->where('dairy_trip_uuid', $trip->uuid)
                ->update(['status' => 'arrived', 'phase' => 'ARRIVED', 'updated_at' => now()]);
            DB::table('cooperative_dispatches')->where('trip_uuid', $trip->uuid)->where('status', 'on_trip')
                ->update(['status' => 'delivered', 'updated_at' => now()]);
        });

        return response()->json(['status' => true, 'message' => 'Trip arrived at destination.']);
    }

    private function myDispatches(Request $request): Builder
    {
        $user = $request->user();
        $phones = DB::table('phone_identities')->where('user_id', $user->id)->pluck('phone')
            ->merge(DB::table('transporter_drivers')->where('user_id', $user->id)->pluck('phone'))
            ->push($user->phone ?? null)
            ->map(fn ($phone) => substr(preg_replace('/\D/', '', (string) $phone), -9))
            ->filter(fn ($key) => strlen($key) === 9)
            ->unique();

        return DB::table('cooperative_dispatches as d')->where(function ($q) use ($user, $phones) {
            $q->where('d.driver_user_id', $user->id);
            foreach ($phones as $key) {
                $q->orWhere(fn ($p) => $p->whereNull('d.driver_user_id')
                    ->whereRaw("REPLACE(REPLACE(d.driver_phone, ' ', ''), '-', '') LIKE ?", ["%{$key}"]));
            }
        });
    }

    private function myTrip(Request $request, string $uuid): object
    {
        $trip = DB::table('dairy_trips')->where('uuid', $uuid)->where('driver_user_id', $request->user()->id)->first();
        abort_unless($trip, 404, $this->say($request, 'Trip not found.', 'Safari haikupatikana.'));

        return $trip;
    }

    private function tripsFor(int $userId, ?string $uuid = null): Collection
    {
        $trips = DB::table('dairy_trips as t')
            ->leftJoin('dairy_factories as f', 'f.uuid', '=', 't.destination_factory_uuid')
            ->where('t.driver_user_id', $userId)
            ->when($uuid, fn ($q) => $q->where('t.uuid', $uuid))
            ->select('t.*', 'f.name as destination_factory_name')
            ->orderByDesc('t.created_at')
            ->limit(200)
            ->get();
        $routes = DB::table('dairy_trip_routes as r')
            ->leftJoin('cooperative_collection_centres as c', 'c.uuid', '=', 'r.centre_uuid')
            ->leftJoin('cooperative_dispatches as d', 'd.uuid', '=', 'r.dispatch_uuid')
            ->whereIn('r.dairy_trip_uuid', $trips->pluck('uuid'))
            ->orderBy('r.sequence_order')
            ->get(['r.dairy_trip_uuid', 'r.uuid', 'r.dispatch_uuid', 'r.sequence_order', 'r.status', 'r.quantity',
                'r.liters', 'r.unit', 'r.added_mid_trip', 'c.name as centre_name', 'd.dispatch_code', 'd.batch_number'])
            ->groupBy('dairy_trip_uuid');
        $members = $this->factoryMembers($trips->pluck('destination_factory_uuid')->filter()->unique());

        return $trips->map(function ($trip) use ($routes, $members) {
            $trip->routes = $routes->get($trip->uuid, collect())->values();
            $trip->factory_members = $members->get($trip->destination_factory_uuid, collect())->values();
            $trip->welcoming_operator_name = $trip->welcoming_operator_uuid
                ? $trip->factory_members->firstWhere('uuid', $trip->welcoming_operator_uuid)?->name
                : null;

            return $trip;
        });
    }

    /** Active members of each factory, so the driver can hand over offline. */
    private function factoryMembers(Collection $factoryUuids): Collection
    {
        return DB::table('factory_operators as fo')
            ->whereIn('fo.factory_uuid', $factoryUuids)
            ->where('fo.is_active', true)
            ->selectSub(DB::table('phone_identities')->select('display_name')
                ->whereColumn('phone_identities.user_id', 'fo.user_id')->orderBy('id')->limit(1), 'display_name')
            ->selectSub(DB::table('phone_identities')->select('phone')
                ->whereColumn('phone_identities.user_id', 'fo.user_id')->orderBy('id')->limit(1), 'phone')
            ->addSelect(['fo.uuid', 'fo.factory_uuid', 'fo.role', 'fo.first_name', 'fo.last_name'])
            ->get()
            ->map(fn ($m) => (object) [
                'uuid' => $m->uuid,
                'factory_uuid' => $m->factory_uuid,
                'name' => trim("{$m->first_name} {$m->last_name}") ?: ($m->display_name ?: $m->phone),
                'phone' => $m->phone,
                'role' => $m->role === 'manager' ? 'manager' : 'operator',
            ])
            ->sortBy('name')
            ->groupBy('factory_uuid');
    }

    private function refuse(Request $request, bool $condition, string $english, string $swahili): void
    {
        abort_if($condition, 422, $this->say($request, $english, $swahili));
    }

    private function say(Request $request, string $english, string $swahili): string
    {
        return str_starts_with(strtolower((string) $request->header('Accept-Language', 'en')), 'sw') ? $swahili : $english;
    }
}
