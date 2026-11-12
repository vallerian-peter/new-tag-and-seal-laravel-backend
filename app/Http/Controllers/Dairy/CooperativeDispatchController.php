<?php

namespace App\Http\Controllers\Dairy;

use App\Http\Controllers\Controller;
use App\Services\Dairy\CooperativeAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CooperativeDispatchController extends Controller
{
    /**
     * GET /api/v2/dairy/dispatches
     * Dispatches out of the caller's AMCOS centres (or the trips they drive),
     * with the trip phase and the factory's decision on each batch.
     */
    public function listDispatches(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! Schema::hasTable('cooperative_dispatches')) {
            return response()->json(['status' => true, 'data' => []]);
        }

        $amcosUuids = CooperativeAccess::staffAmcosUuids($user->id);
        if ($request->filled('amcos_uuid')) {
            $amcosUuids = array_values(array_intersect($amcosUuids, [$request->query('amcos_uuid')]));
        }

        $query = DB::table('cooperative_dispatches as d')
            ->leftJoin('cooperative_collection_centres as c', 'c.uuid', '=', 'd.centre_uuid')
            ->where(function ($q) use ($amcosUuids, $user) {
                $q->whereIn('c.amcos_uuid', $amcosUuids)->orWhere('d.driver_user_id', $user->id);
            })
            ->select('d.*', 'c.name as centre_name', 'c.centre_code', 'c.amcos_uuid');

        if (Schema::hasTable('dairy_trips')) {
            $query->leftJoin('dairy_trips as t', 't.uuid', '=', 'd.trip_uuid')->addSelect(
                't.trip_number', 't.status as trip_status', 't.phase as trip_phase',
                't.started_at as trip_started_at', 't.arrived_at as trip_arrived_at', 't.completed_at as trip_completed_at'
            );
        }
        if (Schema::hasTable('factory_dispatch_receipts')) {
            $query->leftJoin('factory_dispatch_receipts as r', 'r.dispatch_uuid', '=', 'd.uuid')->addSelect(
                'r.action as receipt_action', 'r.status as receipt_status', 'r.received_volume_liters',
                'r.accepted_volume_liters', 'r.received_at', 'r.decided_at', 'r.reason as receipt_reason'
            );
        }

        foreach (['centre_uuid', 'commodity_code', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where("d.{$filter}", $request->query($filter));
            }
        }

        $dispatches = $query->orderByDesc('d.dispatched_at')->paginate(min($request->integer('per_page', 50), 100));

        return response()->json([
            'status' => true,
            'data' => $dispatches->items(),
            'meta' => [
                'current_page' => $dispatches->currentPage(),
                'total' => $dispatches->total(),
                'per_page' => $dispatches->perPage(),
                'last_page' => $dispatches->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v2/dairy/transporter/fleet
     * Get fleet vehicles and drivers for the authenticated transporter manager.
     */
    public function transporterFleet(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! Schema::hasTable('transporter_companies')) {
            return response()->json(['status' => true, 'data' => ['vehicles' => [], 'drivers' => []]]);
        }

        $company = DB::table('transporter_companies')->where('manager_user_id', $user->id)->first();
        if (! $company) {
            return response()->json(['status' => true, 'data' => ['vehicles' => [], 'drivers' => []]]);
        }

        $vehicles = DB::table('transporter_vehicles')
            ->where('transporter_company_uuid', $company->uuid)
            ->orderBy('registration_number')
            ->get();

        $drivers = DB::table('transporter_drivers as d')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('phone_identities as pi', 'pi.user_id', '=', 'u.id')
            ->leftJoin('transporter_vehicles as v', 'v.uuid', '=', 'd.assigned_vehicle_uuid')
            ->where('d.transporter_company_uuid', $company->uuid)
            ->select(
                'd.*',
                DB::raw('COALESCE(pi.display_name, u.username) as name'),
                'pi.phone',
                'v.registration_number as assigned_vehicle_plate'
            )
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => true,
            'data' => [
                'company' => $company,
                'vehicles' => $vehicles,
                'drivers' => $drivers,
            ],
        ]);
    }

    /**
     * GET /api/v2/dairy/transporter/trips/pending-approval
     */
    public function transporterTripsPending(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! Schema::hasTable('dairy_trips') || ! Schema::hasTable('transporter_companies')) {
            return response()->json(['status' => true, 'data' => []]);
        }

        $company = DB::table('transporter_companies')->where('manager_user_id', $user->id)->first();
        if (! $company) {
            return response()->json(['status' => true, 'data' => []]);
        }

        $trips = DB::table('dairy_trips as t')
            ->leftJoin('phone_identities as pi', 'pi.user_id', '=', 't.driver_user_id')
            ->leftJoin('dairy_factories as f', 'f.uuid', '=', 't.destination_factory_uuid')
            ->where('t.transporter_company_uuid', $company->uuid)
            ->where('t.approval_status', 'pending')
            ->select(
                't.*',
                'pi.display_name as driver_name',
                'pi.phone as driver_phone',
                'f.name as destination_factory_name'
            )
            ->orderByDesc('t.created_at')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $trips,
        ]);
    }
}
