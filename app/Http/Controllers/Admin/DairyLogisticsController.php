<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DairyFactory;
use App\Models\DairyFactoryStockMovement;
use App\Models\DairyFactoryTank;
use App\Models\DairyTrip;
use App\Models\DairyTripRoute;
use App\Models\FactoryOperator;
use App\Models\MilkDispatchOrder;
use App\Models\MilkFactoryDelivery;
use App\Models\TransporterCompany;
use App\Models\TransporterDriver;
use App\Models\TransporterVehicle;
use App\Models\User;
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
        $query = DairyFactory::with(['region', 'district'])->withCount(['tanks', 'operators']);

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
            ->with(['region', 'district', 'tanks', 'operators.user'])
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

    public function addFactoryTank(Request $request, string $factoryUuid): JsonResponse
    {
        $factory = DairyFactory::where('uuid', $factoryUuid)->firstOrFail();

        $validated = $request->validate([
            'tank_code' => 'required|string|max:50',
            'capacity_liters' => 'required|numeric|min:500|max:100000',
            'target_temperature_celsius' => 'nullable|numeric',
        ]);

        $tank = DairyFactoryTank::create(array_merge($validated, [
            'factory_uuid' => $factory->uuid,
            'current_stock_liters' => 0.00,
            'status' => 'active_chilling',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Factory tank added successfully.',
            'data' => $tank,
        ], 201);
    }

    public function listFactoryTanks(string $factoryUuid): JsonResponse
    {
        $tanks = DairyFactoryTank::where('factory_uuid', $factoryUuid)->get();

        return response()->json([
            'success' => true,
            'data' => $tanks,
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

        $tripNumber = $validated['trip_number'] ?? ('TRIP-' . strtoupper(Str::random(6)));

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
                'message' => "Tanker capacity exceeded: Truck capacity is {$maxCap} L and already holds {$currentCargo} L. Cannot add {$newLiters} L (would total " . ($currentCargo + $newLiters) . " L).",
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
    // 4. FACTORY OPERATOR INTAKE & BULK INVENTORY
    // ==========================================

    /**
     * Factory Operator receives incoming tanker delivery, checks seals and QA,
     * checks factory and silo capacities, reconciles volume discrepancies,
     * and directly allocates milk into Factory Bulk Storage Tank.
     * No corporate approval required (per PDF spec and user instruction).
     */
    public function receiveFactoryDelivery(Request $request, string $factoryUuid): JsonResponse
    {
        $factory = DairyFactory::where('uuid', $factoryUuid)->firstOrFail();

        $validated = $request->validate([
            'trip_uuid' => 'nullable|exists:dairy_trips,uuid',
            'dispatch_order_uuid' => 'nullable|exists:milk_dispatch_orders,uuid',
            'factory_tank_uuid' => 'required|exists:dairy_factory_tanks,uuid',
            'received_volume_liters' => 'required|numeric|min:1',
            'arrival_temperature_celsius' => 'required|numeric',
            'seals_intact' => 'required|boolean',
            'factory_qa_status' => 'required|in:accepted_full,accepted_with_deduction,rejected_spoiled',
            'spillage_reason' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:500',
        ]);

        $tank = DairyFactoryTank::where('uuid', $validated['factory_tank_uuid'])
            ->where('factory_uuid', $factory->uuid)
            ->firstOrFail();

        $receivedVolume = (float) $validated['received_volume_liters'];

        // 1. Factory Capacity Check
        $currentFactoryInv = (float) $factory->current_inventory_liters;
        $totalFactoryCap = (float) $factory->total_storage_capacity_liters;
        if ($totalFactoryCap > 0 && ($currentFactoryInv + $receivedVolume > $totalFactoryCap)) {
            return response()->json([
                'success' => false,
                'message' => "Factory storage capacity exceeded: Factory {$factory->name} capacity is {$totalFactoryCap} L, currently storing {$currentFactoryInv} L. Cannot accept {$receivedVolume} L.",
            ], 422);
        }

        // 2. Silo / Bulk Tank Capacity Check
        $tankStock = (float) $tank->current_stock_liters;
        $tankCap = (float) $tank->capacity_liters;
        if ($tankStock + $receivedVolume > $tankCap) {
            return response()->json([
                'success' => false,
                'message' => "Silo {$tank->tank_code} capacity exceeded: Silo capacity is {$tankCap} L with {$tankStock} L stored. Cannot fit {$receivedVolume} L.",
            ], 422);
        }

        return DB::transaction(function () use ($factory, $tank, $validated, $receivedVolume, $request) {
            $dispatchedVolume = 0.00;
            $dispatchOrderUuid = $validated['dispatch_order_uuid'] ?? null;

            if (!empty($validated['trip_uuid'])) {
                $trip = DairyTrip::where('uuid', $validated['trip_uuid'])->first();
                if ($trip) {
                    $dispatchedVolume = (float) $trip->total_liters;
                    $trip->update([
                        'status' => 'completed',
                        'phase' => 'COMPLETED',
                        'current_cargo_liters' => 0.00,
                        'completed_at' => now(),
                    ]);
                    DairyTripRoute::where('dairy_trip_uuid', $trip->uuid)->update([
                        'status' => 'delivered',
                        'phase' => 'DELIVERED',
                    ]);
                }
            }

            if (!empty($dispatchOrderUuid)) {
                $order = MilkDispatchOrder::where('uuid', $dispatchOrderUuid)->first();
                if ($order) {
                    $dispatchedVolume = (float) $order->dispatched_volume_liters;
                    $order->update(['status' => 'reconciled']);
                }
            }

            $discrepancyLiters = round($receivedVolume - $dispatchedVolume, 2);
            $deliveryNoteNumber = 'FGRN-' . strtoupper(Str::random(8));

            // Record Factory Delivery
            $delivery = MilkFactoryDelivery::create([
                'dispatch_order_uuid' => $dispatchOrderUuid ?? (string) Str::uuid(),
                'factory_qa_officer_user_id' => $request->user()?->id ?? 1,
                'received_volume_liters' => $receivedVolume,
                'arrival_temperature_celsius' => $validated['arrival_temperature_celsius'],
                'seals_intact' => $validated['seals_intact'],
                'factory_qa_status' => $validated['factory_qa_status'],
                'discrepancy_liters' => $discrepancyLiters,
                'spillage_reason' => $validated['spillage_reason'] ?? null,
                'delivery_note_number' => $deliveryNoteNumber,
                'received_at' => now(),
            ]);

            // If accepted (full or deduction), allocate directly into Factory Tank inventory
            if ($validated['factory_qa_status'] !== 'rejected_spoiled') {
                DairyFactoryStockMovement::create([
                    'factory_uuid' => $factory->uuid,
                    'factory_tank_uuid' => $tank->uuid,
                    'movement_type' => 'tanker_intake',
                    'quantity_liters' => $receivedVolume,
                    'reference_uuid' => $delivery->uuid,
                    'recorded_by_user_id' => $request->user()?->id ?? 1,
                    'notes' => 'Received from tanker. Delivery note: ' . $deliveryNoteNumber,
                    'created_at' => now(),
                ]);

                // Increment tank stock & factory total inventory
                $tank->increment('current_stock_liters', $receivedVolume);
                $factory->increment('current_inventory_liters', $receivedVolume);
            }

            return response()->json([
                'success' => true,
                'message' => 'Tanker delivery processed and stored directly into factory bulk tank inventory.',
                'data' => [
                    'delivery' => $delivery,
                    'tank' => $tank->fresh(),
                    'factory' => $factory->fresh(),
                ],
            ], 201);
        });
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

    public function getFactoryInventory(string $uuid): JsonResponse
    {
        $factory = DairyFactory::with(['tanks.stockMovements', 'operators.user'])
            ->where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $factory,
        ]);
    }
}
