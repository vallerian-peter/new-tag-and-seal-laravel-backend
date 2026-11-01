<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DairyFactory;
use App\Models\DairyFactoryTank;
use App\Models\DairyTrip;
use App\Models\MilkBatch;
use App\Models\MilkCollectionCentre;
use App\Models\MilkDispatchOrder;
use App\Models\MilkFactoryDelivery;
use App\Models\MilkIntake;
use App\Models\MilkTank;
use App\Models\TransporterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DairyResourceController extends Controller
{
    public const RESOURCES = [
        'centres',
        'tanks',
        'batches',
        'intakes',
        'dispatches',
        'deliveries',
        'transporters',
        'factories',
        'trips',
        'factory-tanks',
    ];

    public function index(Request $request, string $resource): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);

        return match ($resource) {
            'centres' => $this->indexCentres($request),
            'tanks' => $this->indexTanks($request),
            'batches' => $this->indexBatches($request),
            'intakes' => $this->indexIntakes($request),
            'dispatches' => $this->indexDispatches($request),
            'deliveries' => $this->indexDeliveries($request),
            'transporters' => $this->indexTransporters($request),
            'factories' => $this->indexFactories($request),
            'trips' => $this->indexTrips($request),
            'factory-tanks' => $this->indexFactoryTanks($request),
        };
    }

    public function options(string $resource): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);

        return response()->json([
            'status' => true,
            'message' => 'Dairy options retrieved successfully.',
            'data' => [
                'tank_statuses' => ['active_chilling', 'full', 'cleaning_cip', 'maintenance'],
                'delivery_sessions' => ['morning', 'evening'],
                'dispatch_statuses' => ['prepared', 'in_transit', 'delivered', 'reconciled', 'disputed'],
                'factory_qa_statuses' => ['accepted_full', 'accepted_with_deduction', 'rejected_spoiled'],
            ],
        ]);
    }

    public function show(string $resource, string $record): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);

        $data = match ($resource) {
            'centres' => MilkCollectionCentre::with(['region', 'district', 'tanks'])->where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
            'tanks' => MilkTank::with(['mcc', 'movements'])->where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
            'batches' => MilkBatch::with(['mcc', 'intakes'])->where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
            'intakes' => MilkIntake::with(['mcc', 'batch', 'farmer', 'clerk', 'payoutAccrual'])->where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
            'dispatches' => MilkDispatchOrder::with(['mcc', 'transporter', 'factoryDelivery'])->where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
            'deliveries' => MilkFactoryDelivery::with(['dispatchOrder', 'factoryQaOfficer'])->where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
            'transporters' => TransporterCompany::with(['vehicles', 'drivers.user', 'trips'])->where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
            'factories' => DairyFactory::with(['region', 'district', 'tanks', 'operators.user'])->where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
            'trips' => DairyTrip::with(['transporter', 'driver', 'vehicle', 'destinationFactory', 'routes.mcc'])->where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
            'factory-tanks' => DairyFactoryTank::with(['factory', 'stockMovements'])->where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
        };

        return response()->json([
            'status' => true,
            'message' => 'Record retrieved successfully.',
            'data' => $data,
        ]);
    }

    private function indexCentres(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);
        $paginated = MilkCollectionCentre::with(['region', 'district', 'tanks'])->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Milk collection centres retrieved successfully.',
            'data' => [
                'data' => $paginated->items(),
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
                'meta' => [
                    'total_centres' => MilkCollectionCentre::count(),
                    'active_centres' => MilkCollectionCentre::where('is_active', true)->count(),
                ],
            ],
        ]);
    }

    private function indexTanks(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);
        $paginated = MilkTank::with(['mcc'])->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Milk tanks retrieved successfully.',
            'data' => [
                'data' => $paginated->items(),
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
                'meta' => [
                    'total_tanks' => MilkTank::count(),
                    'chilling_tanks' => MilkTank::where('status', 'active_chilling')->count(),
                ],
            ],
        ]);
    }

    private function indexBatches(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);
        $paginated = MilkBatch::with(['mcc'])->latest()->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Milk batches retrieved successfully.',
            'data' => [
                'data' => $paginated->items(),
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    private function indexIntakes(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);
        $paginated = MilkIntake::with(['mcc', 'batch', 'farmer', 'clerk', 'payoutAccrual'])->latest('intake_timestamp')->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Milk intakes retrieved successfully.',
            'data' => [
                'data' => $paginated->items(),
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
                'meta' => [
                    'total_intakes' => MilkIntake::count(),
                    'total_accepted_liters' => (float) MilkIntake::sum('accepted_liters'),
                ],
            ],
        ]);
    }

    private function indexDispatches(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);
        $paginated = MilkDispatchOrder::with(['mcc', 'transporter', 'factoryDelivery'])->latest()->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Milk dispatches retrieved successfully.',
            'data' => [
                'data' => $paginated->items(),
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    private function indexDeliveries(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);
        $paginated = MilkFactoryDelivery::with(['dispatchOrder', 'factoryQaOfficer'])->latest()->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Milk deliveries retrieved successfully.',
            'data' => [
                'data' => $paginated->items(),
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    private function indexTransporters(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);
        $paginated = TransporterCompany::withCount(['vehicles', 'drivers', 'trips'])->latest()->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Transporters retrieved successfully.',
            'data' => [
                'data' => $paginated->items(),
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    private function indexFactories(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);
        $paginated = DairyFactory::with(['region', 'district'])->withCount(['tanks', 'operators'])->latest()->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Dairy factories retrieved successfully.',
            'data' => [
                'data' => $paginated->items(),
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    private function indexTrips(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);
        $paginated = DairyTrip::with(['transporter', 'driver', 'vehicle', 'destinationFactory', 'routes.mcc'])->latest()->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Dairy trips retrieved successfully.',
            'data' => [
                'data' => $paginated->items(),
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    private function indexFactoryTanks(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);
        $paginated = DairyFactoryTank::with(['factory'])->latest()->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Dairy factory tanks retrieved successfully.',
            'data' => [
                'data' => $paginated->items(),
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }
}

