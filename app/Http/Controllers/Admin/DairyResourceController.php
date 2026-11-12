<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DairyFactory;
use App\Models\DairyTrip;
use App\Models\MilkCollectionCentre;
use App\Models\MilkFactoryDelivery;
use App\Models\TransporterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DairyResourceController extends Controller
{
    public const RESOURCES = [
        'centres',
        'intakes',
        'dispatches',
        'deliveries',
        'transporters',
        'factories',
        'trips',
    ];

    public function index(Request $request, string $resource): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);

        return match ($resource) {
            'centres' => $this->indexCentres($request),
            'intakes' => $this->indexIntakes($request),
            'dispatches' => $this->indexDispatches($request),
            'deliveries' => $this->indexDeliveries($request),
            'transporters' => $this->indexTransporters($request),
            'factories' => $this->indexFactories($request),
            'trips' => $this->indexTrips($request),
        };
    }

    public function options(string $resource): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);

        return response()->json([
            'status' => true,
            'message' => 'Dairy options retrieved successfully.',
            'data' => [
                'delivery_sessions' => ['morning', 'evening'],
                'dispatch_statuses' => ['dispatched', 'on_trip', 'delivered', 'reconciled'],
                'factory_qa_statuses' => ['accepted_full', 'accepted_with_deduction', 'rejected_spoiled'],
            ],
        ]);
    }

    public function show(string $resource, string $record): JsonResponse
    {
        abort_unless(in_array($resource, self::RESOURCES, true), 404);

        $data = match ($resource) {
            'centres' => MilkCollectionCentre::with(['region', 'district'])->where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
            'intakes' => $this->intakeQuery()->where(fn ($q) => $q->where('i.uuid', $record)->orWhere('i.receipt_number', $record))->first() ?? abort(404),
            'dispatches' => $this->dispatchQuery()->where('d.uuid', $record)->first() ?? abort(404),
            'deliveries' => $this->receiptQuery()->where('r.uuid', $record)->first() ?? abort(404),
            'transporters' => TransporterCompany::with(['vehicles', 'drivers.user', 'trips'])->where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
            'factories' => DairyFactory::with(['region', 'district', 'operators.user'])->where('id', $record)->orWhere('uuid', $record)->firstOrFail(),
            'trips' => tap(DairyTrip::with(['transporter', 'driver', 'vehicle', 'destinationFactory'])->where('id', $record)->orWhere('uuid', $record)->firstOrFail(), fn ($trip) => $this->withDispatches(collect([$trip]))),
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
        $paginated = MilkCollectionCentre::with(['region', 'district'])->paginate($perPage);

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

    /** Milk the collection centres record in the app (cooperative_intakes). */
    private function indexIntakes(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);
        $query = $this->filtered($this->intakeQuery(), $request,
            ['i.receipt_number', 'i.uuid', 'c.name', 'fm.farmer_name', 'fm.farmer_phone', 'fm.member_number', 'fu.username'], 'i.approval_status', 'i.intake_timestamp', 'i.id', ['accepted', 'rejected']);
        if ($request->query('status') === 'accepted') {
            $query->where('i.rejected_quantity', 0);
        }
        if ($request->query('status') === 'rejected') {
            $query->where('i.rejected_quantity', '>', 0);
        }
        $milk = DB::table('cooperative_intakes')->where('commodity_code', 'milk');

        return $this->page($query->paginate($perPage), 'Milk intakes retrieved successfully.', [
            'total_intakes' => (clone $milk)->count(),
            'total_accepted_liters' => (float) (clone $milk)->sum('accepted_quantity'),
            'total_rejected_liters' => (float) (clone $milk)->sum('rejected_quantity'),
        ]);
    }

    private function intakeQuery()
    {
        return DB::table('cooperative_intakes as i')
            ->where('i.commodity_code', 'milk')
            ->leftJoin('cooperative_collection_centres as c', 'c.uuid', '=', 'i.centre_uuid')
            ->leftJoin('cooperative_amcos as a', 'a.uuid', '=', 'i.amcos_uuid')
            ->leftJoin('users as fu', 'fu.id', '=', 'i.farmer_user_id')
            ->leftJoin('cooperative_farmer_memberships as fm', fn ($join) => $join
                ->on('fm.farmer_user_id', '=', 'i.farmer_user_id')->on('fm.amcos_uuid', '=', 'i.amcos_uuid'))
            ->select('i.*', 'c.name as centre_name', 'a.name as amcos_name', 'fm.member_number', 'fm.farmer_phone',
                DB::raw('COALESCE(fm.farmer_name, fu.username) as farmer_name'));
    }

    private function indexDispatches(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);
        $paginated = $this->filtered($this->dispatchQuery(), $request, ['d.dispatch_code', 'd.batch_number', 'c.name', 'd.destination_name', 'd.driver_name', 'd.vehicle_plate_number', 't.trip_number'], 'd.status', 'd.created_at', 'd.id')->paginate($perPage);

        return $this->page($paginated, 'Milk dispatches retrieved successfully.', [
            'total_dispatched_liters' => (float) DB::table('cooperative_dispatches')->sum('quantity'),
            'reconciled' => DB::table('cooperative_dispatches')->where('status', 'reconciled')->count(),
        ]);
    }

    private function indexDeliveries(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 5), 100);
        $paginated = $this->filtered($this->receiptQuery(), $request, ['r.delivery_note_number', 't.trip_number', 'f.name'], 'r.factory_qa_status', 'r.created_at', 'r.id')->paginate($perPage);

        return $this->page($paginated, 'Factory receipts retrieved successfully.', [
            'total_received_liters' => (float) MilkFactoryDelivery::sum('received_volume_liters'),
            'total_variance_liters' => (float) MilkFactoryDelivery::sum('discrepancy_liters'),
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
        $paginated = DairyFactory::with(['region', 'district'])->withCount(['operators'])->latest()->paginate($perPage);

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
        $paginated = $this->filtered(DairyTrip::with(['transporter', 'driver', 'vehicle', 'destinationFactory']), $request, ['trip_number', 'vehicle_plate_number'], 'phase', 'created_at', 'id')->paginate($perPage);
        $this->withDispatches($paginated->getCollection());

        return $this->page($paginated, 'Dairy trips retrieved successfully.', [
            'total_liters' => (float) DairyTrip::sum('total_liters'),
            'in_transit' => DairyTrip::where('phase', 'IN_TRANSIT')->count(),
        ]);
    }

    private function filtered($query, Request $request, array $columns, string $status, string $date, string $id, array $customStatuses = [])
    {
        if ($term = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($columns, $term) {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'like', '%'.$term.'%');
                }
            });
        }
        $value = $request->query('status');
        if ($value && ! in_array($value, $customStatuses, true)) {
            $query->where($status, $value);
        }

        return $query->orderBy($date, $request->query('sort') === 'asc' ? 'asc' : 'desc')->orderBy($id);
    }

    private function dispatchQuery()
    {
        return DB::table('cooperative_dispatches as d')
            ->leftJoin('cooperative_collection_centres as c', 'c.uuid', '=', 'd.centre_uuid')
            ->leftJoin('dairy_trips as t', 't.uuid', '=', 'd.trip_uuid')
            ->select('d.*', 'c.name as centre_name', 't.trip_number');
    }

    private function receiptQuery()
    {
        return DB::table('milk_factory_deliveries as r')
            ->leftJoin('dairy_trips as t', 't.uuid', '=', 'r.trip_uuid')
            ->leftJoin('dairy_factories as f', 'f.uuid', '=', 'r.factory_uuid')
            ->select('r.*', 't.trip_number', 't.vehicle_plate_number', 'f.name as factory_name');
    }

    private function withDispatches(Collection $trips): void
    {
        $dispatches = $this->dispatchQuery()->whereIn('d.trip_uuid', $trips->pluck('uuid'))->get()->groupBy('trip_uuid');
        $trips->each(fn ($trip) => $trip->setAttribute('dispatches', $dispatches->get($trip->uuid, collect())->values()));
    }

    private function page(LengthAwarePaginator $paginated, string $message, array $meta = []): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => [
                'data' => $paginated->items(),
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
                ...($meta ? ['meta' => $meta] : []),
            ],
        ]);
    }
}
