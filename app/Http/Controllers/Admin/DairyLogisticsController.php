<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DairyFactory;
use App\Models\DairyTrip;
use App\Models\DairyTripRoute;
use App\Models\MilkFactoryDelivery;
use App\Models\TransporterCompany;
use App\Models\TransporterDriver;
use App\Models\TransporterVehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DairyLogisticsController extends Controller
{
    // ==========================================
    // 1. TRANSPORTER ORGANIZATIONS
    // ==========================================

    public function listTransporters(Request $request): JsonResponse
    {
        $query = TransporterCompany::withCount(['vehicles', 'drivers', 'trips']);

        if ($request->filled('status')) {
            $query->where('approval_status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('license_number', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $transporters = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $transporters->items(),
            'meta' => [
                'current_page' => $transporters->currentPage(),
                'total' => $transporters->total(),
                'per_page' => $transporters->perPage(),
                'last_page' => $transporters->lastPage(),
            ],
        ]);
    }

    public function storeTransporter(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'license_number' => 'required|string|max:100|unique:transporter_companies,license_number',
            'tin_number' => 'nullable|string|max:50',
            'contact_person' => 'required|string|max:255',
            'phone' => 'required|string|max:50',
            'email' => 'nullable|email|max:255',
            'document_urls' => 'nullable|array',
        ]);

        $transporter = TransporterCompany::create(array_merge($validated, [
            'approval_status' => 'pending',
            'is_active' => true,
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Transporter company registered successfully and is pending approval.',
            'data' => $transporter,
        ], 201);
    }

    public function getTransporter(string $uuid): JsonResponse
    {
        $transporter = TransporterCompany::where('uuid', $uuid)
            ->with(['vehicles', 'drivers.user', 'drivers.assignedVehicle'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $transporter,
        ]);
    }

    public function approveTransporter(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => 'nullable|string|max:500',
        ]);

        $transporter = TransporterCompany::where('uuid', $uuid)->firstOrFail();
        $transporter->update([
            'approval_status' => 'approved',
            'approved_by_user_id' => $request->user()?->id ?? 1,
            'approval_remarks' => $validated['remarks'] ?? 'Approved by administrator',
            'approved_at' => now(),
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Transporter company approved successfully.',
            'data' => $transporter,
        ]);
    }

    public function rejectTransporter(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => 'required|string|max:500',
        ]);

        $transporter = TransporterCompany::where('uuid', $uuid)->firstOrFail();
        $transporter->update([
            'approval_status' => 'rejected',
            'approved_by_user_id' => $request->user()?->id ?? 1,
            'approval_remarks' => $validated['remarks'],
            'approved_at' => now(),
            'is_active' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Transporter company registration rejected.',
            'data' => $transporter,
        ]);
    }

    public function addVehicle(Request $request, string $transporterUuid): JsonResponse
    {
        $transporter = TransporterCompany::where('uuid', $transporterUuid)->firstOrFail();

        $validated = $request->validate([
            'registration_number' => 'required|string|max:50|unique:transporter_vehicles,registration_number',
            'tanker_capacity_liters' => 'required|numeric|min:500|max:50000',
            'make_model' => 'nullable|string|max:100',
            'calibration_certificate_url' => 'nullable|string|max:500',
        ]);

        $vehicle = TransporterVehicle::create(array_merge($validated, [
            'transporter_company_uuid' => $transporter->uuid,
            'approval_status' => 'approved',
            'is_active' => true,
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Vehicle registered successfully.',
            'data' => $vehicle,
        ], 201);
    }

    public function addDriver(Request $request, string $transporterUuid): JsonResponse
    {
        $transporter = TransporterCompany::where('uuid', $transporterUuid)->firstOrFail();

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'driver_license_number' => 'required|string|max:50',
            'assigned_vehicle_uuid' => 'nullable|exists:transporter_vehicles,uuid',
        ]);

        $driver = TransporterDriver::create(array_merge($validated, [
            'transporter_company_uuid' => $transporter->uuid,
            'approval_status' => 'approved',
            'is_active' => true,
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Driver registered and assigned successfully.',
            'data' => $driver->load(['user', 'assignedVehicle']),
        ], 201);
    }

    // ==========================================
    // 2. DAIRY FACTORIES & BULK WAREHOUSES
    // ==========================================

    public function listFactories(Request $request): JsonResponse
    {
        $query = DairyFactory::with(['region', 'district'])->withCount(['operators']);

        if ($request->filled('status')) {
            $query->where('approval_status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('factory_code', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%");
            });
        }

        $factories = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $factories->items(),
            'meta' => [
                'current_page' => $factories->currentPage(),
                'total' => $factories->total(),
                'per_page' => $factories->perPage(),
                'last_page' => $factories->lastPage(),
            ],
        ]);
    }

    public function storeFactory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'factory_code' => 'required|string|max:50|unique:dairy_factories,factory_code',
            'registration_number' => 'required|string|max:100',
            'dairy_board_license_no' => 'nullable|string|max:100',
            'contact_name' => 'required|string|max:255',
            'contact_phone' => 'required|string|max:50',
            'contact_email' => 'nullable|email|max:255',
            'region_id' => 'required|exists:regions,id',
            'district_id' => 'required|exists:districts,id',
            'address' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'total_storage_capacity_liters' => 'nullable|numeric|min:1000',
            'document_urls' => 'nullable|array',
        ]);

        $factory = DairyFactory::create(array_merge($validated, [
            'approval_status' => 'pending',
            'is_active' => true,
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Dairy factory registered successfully and is pending approval.',
            'data' => $factory,
        ], 201);
    }

    public function getFactory(string $uuid): JsonResponse
    {
        $factory = DairyFactory::where('uuid', $uuid)
            ->with(['region', 'district', 'operators.user'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $factory,
        ]);
    }

    public function approveFactory(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => 'nullable|string|max:500',
        ]);

        $factory = DairyFactory::where('uuid', $uuid)->firstOrFail();
        $factory->update([
            'approval_status' => 'approved',
            'approved_by_user_id' => $request->user()?->id ?? 1,
            'approval_remarks' => $validated['remarks'] ?? 'Approved by administrator',
            'approved_at' => now(),
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Dairy factory approved successfully.',
            'data' => $factory,
        ]);
    }

    public function rejectFactory(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => 'required|string|max:500',
        ]);

        $factory = DairyFactory::where('uuid', $uuid)->firstOrFail();
        $factory->update([
            'approval_status' => 'rejected',
            'approved_by_user_id' => $request->user()?->id ?? 1,
            'approval_remarks' => $validated['remarks'],
            'approved_at' => now(),
            'is_active' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Dairy factory registration rejected.',
            'data' => $factory,
        ]);
    }

    // ==========================================
    // 3. DAIRY TRIPS & DYNAMIC MID-TRIP ROUTES
    // ==========================================

    public function listTrips(Request $request): JsonResponse
    {
        $query = DairyTrip::with(['transporter', 'driver', 'vehicle', 'destinationFactory', 'routes.mcc']);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('driver_user_id')) {
            $query->where('driver_user_id', $request->query('driver_user_id'));
        }

        $trips = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $trips->items(),
            'meta' => [
                'current_page' => $trips->currentPage(),
                'total' => $trips->total(),
                'per_page' => $trips->perPage(),
                'last_page' => $trips->lastPage(),
            ],
        ]);
    }

    public function storeTrip(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trip_number' => 'nullable|string|max:50',
            'transporter_company_uuid' => 'required|exists:transporter_companies,uuid',
            'driver_user_id' => 'required|exists:users,id',
            'vehicle_uuid' => 'required|exists:transporter_vehicles,uuid',
            'destination_factory_uuid' => 'required|exists:dairy_factories,uuid',
            'notes' => 'nullable|string',
            'routes' => 'required|array|min:1',
            'routes.*.mcc_uuid' => 'required|exists:milk_collection_centres,uuid',
            'routes.*.destination_factory_uuid' => 'nullable|exists:dairy_factories,uuid',
            'routes.*.dispatch_order_uuid' => 'nullable|exists:milk_dispatch_orders,uuid',
            'routes.*.liters' => 'required|numeric|min:1',
            'routes.*.temperature_celsius' => 'nullable|numeric',
            'routes.*.security_seal_numbers' => 'nullable|array',
        ]);

        $vehicle = TransporterVehicle::where('uuid', $validated['vehicle_uuid'])->firstOrFail();
        $maxTankerCap = (float) $vehicle->tanker_capacity_liters;
        $destinationFactoryUuid = $validated['destination_factory_uuid'];

        // 1. Validate route destinations & calculate initial volume
        $incomingLiters = 0.0;
        foreach ($validated['routes'] as $routeData) {
            $routeDest = $routeData['destination_factory_uuid'] ?? null;
            if ($routeDest && $routeDest !== $destinationFactoryUuid) {
                return response()->json([
                    'success' => false,
                    'message' => "Route destination mismatch: This trip is bound for destination factory {$destinationFactoryUuid}, but one of the selected routes is destined for {$routeDest}. All routes on a trip must share the same final destination.",
                ], 422);
            }
            $incomingLiters += (float) $routeData['liters'];
        }

        // 2. Validate Vehicle Capacity vs Cargo Stored
        if ($incomingLiters > $maxTankerCap) {
            return response()->json([
                'success' => false,
                'message' => "Tanker capacity exceeded: Vehicle {$vehicle->registration_number} capacity is {$maxTankerCap} L, but routes total {$incomingLiters} L.",
            ], 422);
        }

        $tripNumber = $validated['trip_number'] ?? ('TRIP-'.strtoupper(Str::random(6)));

        return DB::transaction(function () use ($validated, $vehicle, $tripNumber, $maxTankerCap, $incomingLiters, $destinationFactoryUuid) {
            $trip = DairyTrip::create([
                'trip_number' => $tripNumber,
                'transporter_company_uuid' => $validated['transporter_company_uuid'],
                'driver_user_id' => $validated['driver_user_id'],
                'vehicle_uuid' => $vehicle->uuid,
                'vehicle_plate_number' => $vehicle->registration_number,
                'destination_factory_uuid' => $destinationFactoryUuid,
                'status' => 'created',
                'phase' => 'PREPARING',
                'approval_status' => 'approved',
                'notes' => $validated['notes'] ?? null,
                'max_tanker_capacity_liters' => $maxTankerCap,
                'current_cargo_liters' => $incomingLiters,
                'total_liters' => $incomingLiters,
            ]);

            $sequence = 1;
            foreach ($validated['routes'] as $routeData) {
                DairyTripRoute::create([
                    'dairy_trip_uuid' => $trip->uuid,
                    'dispatch_order_uuid' => $routeData['dispatch_order_uuid'] ?? null,
                    'mcc_uuid' => $routeData['mcc_uuid'],
                    'destination_factory_uuid' => $destinationFactoryUuid,
                    'sequence_order' => $sequence++,
                    'stop_type' => 'pickup',
                    'status' => 'assigned',
                    'phase' => 'ASSIGNED',
                    'liters' => $routeData['liters'],
                    'temperature_celsius' => $routeData['temperature_celsius'] ?? 4.0,
                    'security_seal_numbers' => $routeData['security_seal_numbers'] ?? [],
                    'added_mid_trip' => false,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Trip created successfully with initial route legs.',
                'data' => $trip->load(['routes.mcc', 'destinationFactory']),
            ], 201);
        });
    }

    public function getTrip(string $uuid): JsonResponse
    {
        $trip = DairyTrip::where('uuid', $uuid)
            ->with(['transporter', 'driver', 'vehicle', 'destinationFactory', 'routes.mcc', 'routes.dispatchOrder'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $trip,
        ]);
    }

    /**
     * DYNAMIC MID-TRIP ROUTE ADDITION (AddRoutes mid-trip pattern)
     * Adds an extra collection leg to a trip that is already created or on the road.
     */
    public function addRoutesToTrip(Request $request, string $tripUuid): JsonResponse
    {
        $trip = DairyTrip::where('uuid', $tripUuid)->firstOrFail();

        $validated = $request->validate([
            'routes' => 'required|array|min:1',
            'routes.*.mcc_uuid' => 'required|exists:milk_collection_centres,uuid',
            'routes.*.destination_factory_uuid' => 'nullable|exists:dairy_factories,uuid',
            'routes.*.dispatch_order_uuid' => 'nullable|exists:milk_dispatch_orders,uuid',
            'routes.*.liters' => 'required|numeric|min:1',
            'routes.*.temperature_celsius' => 'nullable|numeric',
            'routes.*.security_seal_numbers' => 'nullable|array',
        ]);

        // 1. Destination Check: All added routes MUST match the trip's destination factory
        foreach ($validated['routes'] as $routeData) {
            $routeDest = $routeData['destination_factory_uuid'] ?? null;
            if ($routeDest && $routeDest !== $trip->destination_factory_uuid) {
                return response()->json([
                    'success' => false,
                    'message' => "Route destination mismatch: Trip {$trip->trip_number} is bound for destination factory {$trip->destination_factory_uuid}, but route specifies {$routeDest}. All routes on a trip must share the same final destination.",
                ], 422);
            }
        }

        // 2. Capacity Check: Current Cargo + Added Liters <= Max Tanker Capacity
        $newLiters = 0.0;
        foreach ($validated['routes'] as $r) {
            $newLiters += (float) $r['liters'];
        }
        $currentCargo = (float) $trip->current_cargo_liters;
        $maxCap = (float) $trip->max_tanker_capacity_liters;
        if ($maxCap > 0 && ($currentCargo + $newLiters > $maxCap)) {
            return response()->json([
                'success' => false,
                'message' => "Tanker capacity exceeded: Truck capacity is {$maxCap} L and already holds {$currentCargo} L. Cannot add {$newLiters} L (would total ".($currentCargo + $newLiters).' L).',
            ], 422);
        }

        return DB::transaction(function () use ($trip, $validated, $newLiters) {
            $currentMaxSequence = DairyTripRoute::where('dairy_trip_uuid', $trip->uuid)->max('sequence_order') ?? 0;

            foreach ($validated['routes'] as $routeData) {
                $currentMaxSequence++;
                DairyTripRoute::create([
                    'dairy_trip_uuid' => $trip->uuid,
                    'dispatch_order_uuid' => $routeData['dispatch_order_uuid'] ?? null,
                    'mcc_uuid' => $routeData['mcc_uuid'],
                    'destination_factory_uuid' => $trip->destination_factory_uuid,
                    'sequence_order' => $currentMaxSequence,
                    'stop_type' => 'pickup',
                    'status' => $trip->phase === 'IN_TRANSIT' ? 'in_transit' : 'assigned',
                    'phase' => $trip->phase === 'IN_TRANSIT' ? 'IN_TRANSIT' : 'ASSIGNED',
                    'liters' => $routeData['liters'],
                    'temperature_celsius' => $routeData['temperature_celsius'] ?? 4.0,
                    'security_seal_numbers' => $routeData['security_seal_numbers'] ?? [],
                    'added_mid_trip' => true,
                    'added_at' => now(),
                ]);
            }

            $trip->increment('total_liters', $newLiters);
            $trip->increment('current_cargo_liters', $newLiters);

            return response()->json([
                'success' => true,
                'message' => 'Additional route(s) joined to the ongoing trip successfully.',
                'data' => $trip->fresh(['routes.mcc', 'destinationFactory']),
            ]);
        });
    }

    public function startTrip(string $tripUuid): JsonResponse
    {
        $trip = DairyTrip::where('uuid', $tripUuid)->firstOrFail();

        $trip->update([
            'status' => 'in_transit',
            'phase' => 'IN_TRANSIT',
            'started_at' => now(),
        ]);

        DairyTripRoute::where('dairy_trip_uuid', $trip->uuid)
            ->whereIn('status', ['assigned', 'created'])
            ->update([
                'status' => 'in_transit',
                'phase' => 'IN_TRANSIT',
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Trip started. Tanker is in transit.',
            'data' => $trip->fresh(['routes.mcc']),
        ]);
    }

    public function arriveTrip(string $tripUuid): JsonResponse
    {
        $trip = DairyTrip::where('uuid', $tripUuid)->firstOrFail();

        $trip->update([
            'status' => 'arrived',
            'phase' => 'ARRIVED',
            'arrived_at' => now(),
        ]);

        DairyTripRoute::where('dairy_trip_uuid', $trip->uuid)
            ->update([
                'status' => 'arrived',
                'phase' => 'ARRIVED',
            ]);
        DB::table('cooperative_dispatches')->where('trip_uuid', $trip->uuid)->where('status', 'on_trip')
            ->update(['status' => 'delivered', 'updated_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Trip arrived at destination factory weighbridge.',
            'data' => $trip->fresh(),
        ]);
    }

    public function completeTrip(string $tripUuid): JsonResponse
    {
        $trip = DairyTrip::where('uuid', $tripUuid)->firstOrFail();

        $trip->update([
            'status' => 'completed',
            'phase' => 'COMPLETED',
            'current_cargo_liters' => 0.00,
            'completed_at' => now(),
        ]);

        DairyTripRoute::where('dairy_trip_uuid', $trip->uuid)
            ->update([
                'status' => 'delivered',
                'phase' => 'DELIVERED',
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Trip marked as completed.',
            'data' => $trip->fresh(),
        ]);
    }

    // ==========================================
    // 4. FACTORY RECEIPT & RECONCILIATION
    // ==========================================

    /**
     * The factory acknowledges a trip: received and accepted litres, arrival
     * temperature and seals, compared with what the centres dispatched.
     */
    public function receiveFactoryDelivery(Request $request, string $factoryUuid): JsonResponse
    {
        $factory = DairyFactory::where('uuid', $factoryUuid)->firstOrFail();

        $validated = $request->validate([
            'trip_uuid' => 'required|string|exists:dairy_trips,uuid',
            'received_volume_liters' => 'required|numeric|min:0',
            'accepted_volume_liters' => 'nullable|numeric|min:0|lte:received_volume_liters',
            'arrival_temperature_celsius' => 'required|numeric',
            'seals_intact' => 'required|boolean',
            'factory_qa_status' => 'required|in:accepted_full,accepted_with_deduction,rejected_spoiled',
            'spillage_reason' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($factory, $validated, $request) {
            $trip = DairyTrip::where('uuid', $validated['trip_uuid'])->lockForUpdate()->firstOrFail();
            if ($trip->destination_factory_uuid !== $factory->uuid) {
                return $this->refuse('This trip is going to another factory.');
            }
            if (MilkFactoryDelivery::where('trip_uuid', $trip->uuid)->exists()) {
                return response()->json(['success' => false, 'message' => 'This trip was already received.'], 409);
            }
            if (! in_array($trip->status, ['in_transit', 'arrived'], true)) {
                return $this->refuse('Only a trip on the road or arrived can be received.');
            }

            $dispatched = (float) $trip->total_liters;
            $received = round((float) $validated['received_volume_liters'], 2);
            $discrepancy = round($received - $dispatched, 2);
            if ($discrepancy < 0 && empty($validated['spillage_reason'])) {
                return $this->refuse('Give the reason for the shortage.');
            }
            $accepted = $validated['factory_qa_status'] === 'rejected_spoiled'
                ? 0.0
                : (float) ($validated['accepted_volume_liters'] ?? $received);

            $delivery = MilkFactoryDelivery::create([
                'trip_uuid' => $trip->uuid,
                'factory_uuid' => $factory->uuid,
                'factory_qa_officer_user_id' => $request->user()->id,
                'dispatched_volume_liters' => $dispatched,
                'received_volume_liters' => $received,
                'accepted_volume_liters' => $accepted,
                'arrival_temperature_celsius' => $validated['arrival_temperature_celsius'],
                'seals_intact' => $validated['seals_intact'],
                'factory_qa_status' => $validated['factory_qa_status'],
                'discrepancy_liters' => $discrepancy,
                'spillage_reason' => $validated['spillage_reason'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'delivery_note_number' => 'FGRN-'.strtoupper(Str::random(8)),
                'received_at' => now(),
            ]);

            $trip->update([
                'status' => 'completed',
                'phase' => 'COMPLETED',
                'current_cargo_liters' => 0.00,
                'arrived_at' => $trip->arrived_at ?? now(),
                'completed_at' => now(),
            ]);
            DairyTripRoute::where('dairy_trip_uuid', $trip->uuid)->update(['status' => 'delivered', 'phase' => 'DELIVERED']);
            DB::table('cooperative_dispatches')->where('trip_uuid', $trip->uuid)
                ->update(['status' => 'reconciled', 'updated_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => 'Factory receipt recorded.',
                'data' => ['delivery' => $delivery],
            ], 201);
        });
    }

    private function refuse(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 422);
    }

    // ==========================================
    // 5. TRANSPORTER (BOSS) & FACTORY MANAGER (BOSS)
    // ==========================================

    /**
     * Transporter Boss overview: List all trips and routes for their fleet and drivers.
     */
    public function listTransporterTrips(Request $request, string $transporterUuid): JsonResponse
    {
        $transporter = TransporterCompany::where('uuid', $transporterUuid)->firstOrFail();

        $trips = DairyTrip::where('transporter_company_uuid', $transporter->uuid)
            ->with(['driver', 'vehicle', 'destinationFactory', 'routes.mcc'])
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => ['transporter' => $transporter, 'trips' => $trips->items()],
            'meta' => [
                'current_page' => $trips->currentPage(),
                'total' => $trips->total(),
                'per_page' => $trips->perPage(),
                'last_page' => $trips->lastPage(),
            ],
        ]);
    }

    public function getFactoryReceipts(string $uuid): JsonResponse
    {
        $factory = DairyFactory::where('uuid', $uuid)->firstOrFail();
        $receipts = MilkFactoryDelivery::with('trip')->where('factory_uuid', $factory->uuid)
            ->latest('received_at')->limit(200)->get();

        return response()->json([
            'success' => true,
            'data' => ['factory' => $factory, 'receipts' => $receipts],
        ]);
    }
}
