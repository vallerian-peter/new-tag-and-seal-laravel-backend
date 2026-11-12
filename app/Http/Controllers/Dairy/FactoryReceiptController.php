<?php

namespace App\Http\Controllers\Dairy;

use App\Http\Controllers\Controller;
use App\Models\MilkFactoryDelivery;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Factory side of a trip. Each dispatch on the trip (one centre's batch) is
 * received or rejected by a factory operator; a factory manager then approves
 * or rejects the trip's arrival. Once every dispatch has a decision the trip
 * is completed and reconciled.
 *
 * Phase: PREPARING -> IN_TRANSIT -> ARRIVED -> COMPLETED.
 */
class FactoryReceiptController extends Controller
{
    /** Portal list: scope every trip and receipt to the factory being viewed. */
    public function portalIndex(Request $request, string $factory): JsonResponse
    {
        abort_unless(DB::table('dairy_factories')->where('uuid', $factory)->exists(), 404);
        $data = $request->validate([
            'search' => 'nullable|string|max:120', 'status' => 'nullable|in:waiting_approval,approved,rejected,all',
            'sort' => 'nullable|in:asc,desc', 'per_page' => 'nullable|integer|min:10|max:100', 'page' => 'nullable|integer|min:1',
        ]);
        $query = DB::table('dairy_trips as t')->where('t.destination_factory_uuid', $factory)
            ->where('t.approval_status', '!=', 'rejected')->where('t.status', '!=', 'cancelled')
            ->whereExists(function ($q) use ($data) {
                $q->selectRaw('1')->from('factory_dispatch_receipts as r')->whereColumn('r.trip_uuid', 't.uuid');
                if (($data['status'] ?? 'waiting_approval') !== 'all') {
                    $q->where('r.status', $data['status'] ?? 'waiting_approval');
                }
            });
        if ($search = trim($data['search'] ?? '')) {
            $query->where(fn ($q) => $q->where('t.trip_number', 'like', '%'.$search.'%')->orWhere('t.vehicle_plate_number', 'like', '%'.$search.'%'));
        }
        $page = $query->orderBy('t.created_at', $data['sort'] ?? 'desc')->orderBy('t.id')->paginate($data['per_page'] ?? 10);
        $memberships = collect([(object) ['factory_uuid' => $factory]]);
        $trips = $this->tripsFor($memberships, null, collect($page->items())->pluck('uuid'));
        $order = array_flip(collect($page->items())->pluck('uuid')->all());
        $canApprove = $this->portalCanApprove($request, $factory);

        return response()->json(['status' => true, 'data' => $trips->sortBy(fn ($trip) => $order[$trip->uuid])->values(), 'meta' => [
            'canApprove' => $canApprove, 'total' => $page->total(), 'currentPage' => $page->currentPage(),
            'lastPage' => $page->lastPage(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
        ]]);
    }

    /** Same decision as the app, with a snapshot check for a stale portal review. */
    public function portalDecide(Request $request, string $factory, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'notes' => ['required_if:decision,rejected', 'nullable', 'string', 'max:1000'],
            'receipt_uuids' => 'required|array|min:1', 'receipt_uuids.*' => 'required|string|distinct',
        ]);

        return DB::transaction(function () use ($request, $data, $factory, $uuid) {
            $trip = DB::table('dairy_trips')->where('uuid', $uuid)->where('destination_factory_uuid', $factory)->lockForUpdate()->first();
            abort_unless($trip, 404);
            abort_unless($this->portalCanApprove($request, $factory), 403, 'Only a super admin, the factory’s MCU admin or the factory manager can approve handovers.');
            $waiting = DB::table('factory_dispatch_receipts')->where('trip_uuid', $uuid)->where('status', 'waiting_approval')->pluck('uuid')->sort()->values()->all();
            $reviewed = collect($request->input('receipt_uuids'))->sort()->values()->all();
            abort_unless($waiting && $waiting === $reviewed, 409, 'These handovers have changed. Reload the trip before deciding.');
            $this->applyDecision($request, $trip, $data);

            return response()->json(['status' => true, 'data' => $this->tripsFor(collect([(object) ['factory_uuid' => $factory]]), $uuid)->first()]);
        });
    }

    /** Super admins, the MCU admin over this factory and its active managers decide handovers in the portal. */
    private function portalCanApprove(Request $request, string $factory): bool
    {
        $user = $request->user();
        if ($user->platform_role === 'superAdmin') {
            return true;
        }
        if (DB::table('factory_operators')->where('factory_uuid', $factory)->where('user_id', $user->id)
            ->where('role', 'manager')->where('is_active', true)->exists()) {
            return true;
        }
        $mcu = DB::table('dairy_factories')->where('uuid', $factory)->value('mcu_uuid');

        return $mcu !== null && DB::table('cooperative_user_memberships')->where('user_id', $user->id)
            ->where('status', 'approved')->where('role', 'mcu_admin')->where('mcu_uuid', $mcu)->exists();
    }

    /** GET /api/v2/dairy/factory/trips */
    public function trips(Request $request): JsonResponse
    {
        $memberships = $this->memberships($request);

        return response()->json(['status' => true, 'data' => [
            'role' => $memberships->contains('role', 'manager') ? 'manager' : 'operator',
            'factories' => $memberships->values(),
            'trips' => $this->tripsFor($memberships),
        ]]);
    }

    /**
     * POST /api/v2/dairy/factory/receipts
     * Body: uuid, dispatch_uuid, action (receive|reject), received_volume_liters,
     * accepted_volume_liters?, arrival_temperature_celsius?, seals_intact,
     * qa_status?, quality? (test readings), reason?, notes?
     */
    public function receive(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            'dispatch_uuid' => ['required', 'string', 'max:64'],
            'action' => ['required', 'in:receive,reject'],
            'received_volume_liters' => ['required_if:action,receive', 'nullable', 'numeric', 'min:0'],
            'accepted_volume_liters' => ['nullable', 'numeric', 'min:0'],
            'arrival_temperature_celsius' => ['nullable', 'numeric', 'between:-10,60'],
            'seals_intact' => ['sometimes', 'boolean'],
            'qa_status' => ['nullable', 'in:accepted_full,accepted_with_deduction,rejected_spoiled'],
            'quality' => ['nullable', 'array'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($existing = DB::table('factory_dispatch_receipts')->where('uuid', $data['uuid'])->first()) {
            return response()->json(['status' => true, 'data' => $existing]);
        }

        $memberships = $this->memberships($request);
        $dispatch = DB::table('cooperative_dispatches')->where('uuid', $data['dispatch_uuid'])->first();
        $trip = $dispatch?->trip_uuid ? DB::table('dairy_trips')->where('uuid', $dispatch->trip_uuid)->first() : null;
        abort_unless($trip && $memberships->contains('factory_uuid', $trip->destination_factory_uuid), 404,
            $this->say($request, 'This dispatch is not coming to your factory.', 'Mzigo huu haujaja kiwandani kwako.'));
        abort_if(DB::table('factory_dispatch_receipts')->where('dispatch_uuid', $dispatch->uuid)->exists(), 409,
            $this->say($request, 'This dispatch was already received.', 'Mzigo huu tayari umepokelewa.'));
        $this->refuse($request, $trip->status !== 'arrived',
            'Confirm the truck has arrived before receiving.', 'Thibitisha gari limefika kabla ya kupokea.');

        $dispatched = round((float) $dispatch->quantity, 2);
        $rejected = $data['action'] === 'reject' || ($data['qa_status'] ?? null) === 'rejected_spoiled';
        $received = round((float) ($data['received_volume_liters'] ?? 0), 2);
        $accepted = $rejected ? 0.0 : round((float) ($data['accepted_volume_liters'] ?? $received), 2);
        $this->refuse($request, $accepted > $received,
            'Accepted litres cannot be more than received litres.', 'Lita zilizokubaliwa haziwezi kuzidi lita zilizopokelewa.');
        $reason = trim((string) ($data['reason'] ?? ''));
        $this->refuse($request, $reason === '' && ($rejected || $received < $dispatched || $accepted < $received),
            'Give the reason for the rejection or shortage.', 'Toa sababu ya kukataa au upungufu.');

        DB::transaction(function () use ($request, $data, $dispatch, $trip, $dispatched, $received, $accepted, $rejected, $reason): void {
            DB::table('dairy_trips')->where('uuid', $trip->uuid)->lockForUpdate()->first();
            DB::table('factory_dispatch_receipts')->insert([
                'uuid' => $data['uuid'],
                'factory_uuid' => $trip->destination_factory_uuid,
                'trip_uuid' => $trip->uuid,
                'dispatch_uuid' => $dispatch->uuid,
                'action' => $rejected ? 'reject' : 'receive',
                'status' => 'waiting_approval',
                'dispatched_volume_liters' => $dispatched,
                'received_volume_liters' => $received,
                'accepted_volume_liters' => $accepted,
                'discrepancy_liters' => round($received - $dispatched, 2),
                'arrival_temperature_celsius' => $data['arrival_temperature_celsius'] ?? null,
                'seals_intact' => $data['seals_intact'] ?? true,
                'qa_status' => $rejected ? 'rejected_spoiled' : ($data['qa_status'] ?? ($accepted < $dispatched ? 'accepted_with_deduction' : 'accepted_full')),
                'quality_readings' => empty($data['quality']) ? null : json_encode($data['quality']),
                'reason' => $reason === '' ? null : $reason,
                'notes' => $data['notes'] ?? null,
                'received_by_user_id' => $request->user()->id,
                'received_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'status' => true,
            'data' => DB::table('factory_dispatch_receipts')->where('uuid', $data['uuid'])->first(),
        ], 201);
    }

    /**
     * POST /api/v2/dairy/factory/trips/{uuid}/decision
     * Body: decision (approved|rejected), notes (required to reject).
     */
    public function decide(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'notes' => ['required_if:decision,rejected', 'nullable', 'string', 'max:1000'],
        ]);
        $trip = DB::table('dairy_trips')->where('uuid', $uuid)->first();
        $memberships = $this->memberships($request);
        abort_unless($trip && $memberships->contains('factory_uuid', $trip->destination_factory_uuid), 404,
            $this->say($request, 'Trip not found.', 'Safari haikupatikana.'));
        abort_unless($memberships->contains(fn ($m) => $m->factory_uuid === $trip->destination_factory_uuid && $m->role === 'manager'), 403,
            $this->say($request, 'Only the factory manager can approve arrivals.', 'Meneja wa kiwanda pekee ndiye anaweza kuidhinisha.'));

        $this->applyDecision($request, $trip, $data);

        return response()->json(['status' => true, 'data' => $this->tripsFor($memberships, $trip->uuid)->first()]);
    }

    private function applyDecision(Request $request, object $trip, array $data): void
    {
        DB::transaction(function () use ($request, $data, $trip): void {
            DB::table('dairy_trips')->where('uuid', $trip->uuid)->lockForUpdate()->first();
            DB::table('factory_dispatch_receipts')->where('trip_uuid', $trip->uuid)->where('status', 'waiting_approval')
                ->update([
                    'status' => $data['decision'],
                    'decided_by_user_id' => $request->user()->id,
                    'decided_at' => now(),
                    'decision_notes' => $data['notes'] ?? null,
                    'updated_at' => now(),
                ]);
            $this->completeIfDecided($trip, $request->user()->id);
        });
    }

    /** Completes and reconciles the trip once every dispatch has a decision. */
    private function completeIfDecided(object $trip, int $userId): void
    {
        $dispatches = DB::table('cooperative_dispatches')->where('trip_uuid', $trip->uuid)->pluck('uuid');
        $receipts = DB::table('factory_dispatch_receipts')->where('trip_uuid', $trip->uuid)->get();
        $decided = $receipts->whereIn('status', ['approved', 'rejected']);
        if ($dispatches->isEmpty() || $decided->count() < $dispatches->count()) {
            return;
        }

        DB::table('dairy_trips')->where('uuid', $trip->uuid)->update([
            'status' => 'completed', 'phase' => 'COMPLETED', 'current_cargo_liters' => 0,
            'arrived_at' => $trip->arrived_at ?? now(), 'completed_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('dairy_trip_routes')->where('dairy_trip_uuid', $trip->uuid)
            ->update(['status' => 'delivered', 'phase' => 'DELIVERED', 'updated_at' => now()]);
        foreach ($decided as $receipt) {
            DB::table('cooperative_dispatches')->where('uuid', $receipt->dispatch_uuid)->update([
                'status' => $receipt->status === 'approved' && $receipt->action === 'receive' ? 'reconciled' : 'rejected',
                'updated_at' => now(),
            ]);
        }

        if (MilkFactoryDelivery::where('trip_uuid', $trip->uuid)->exists()) {
            return;
        }
        $accepted = $decided->sum(fn ($r) => $r->status === 'approved' ? (float) $r->accepted_volume_liters : 0.0);
        $received = (float) $decided->sum('received_volume_liters');
        $dispatched = (float) $decided->sum('dispatched_volume_liters');
        $temperatures = $decided->pluck('arrival_temperature_celsius')->filter(fn ($t) => $t !== null);
        MilkFactoryDelivery::create([
            'trip_uuid' => $trip->uuid,
            'factory_uuid' => $trip->destination_factory_uuid,
            'factory_qa_officer_user_id' => $userId,
            'dispatched_volume_liters' => $dispatched,
            'received_volume_liters' => $received,
            'accepted_volume_liters' => $accepted,
            'arrival_temperature_celsius' => $temperatures->isEmpty() ? 0 : round($temperatures->avg(), 1),
            'seals_intact' => $decided->every(fn ($r) => (bool) $r->seals_intact),
            'factory_qa_status' => $accepted <= 0 ? 'rejected_spoiled' : ($accepted < $dispatched ? 'accepted_with_deduction' : 'accepted_full'),
            'discrepancy_liters' => round($received - $dispatched, 2),
            'spillage_reason' => $decided->pluck('reason')->filter()->unique()->implode('; ') ?: null,
            'notes' => $decided->pluck('decision_notes')->filter()->unique()->implode('; ') ?: null,
            'delivery_note_number' => 'FGRN-'.strtoupper(Str::random(8)),
            'received_at' => now(),
        ]);
    }

    /** Active factory memberships of the signed-in user. */
    private function memberships(Request $request): Collection
    {
        $rows = DB::table('factory_operators as fo')
            ->join('dairy_factories as f', 'f.uuid', '=', 'fo.factory_uuid')
            ->where('fo.user_id', $request->user()->id)
            ->where('fo.is_active', true)
            ->get(['fo.factory_uuid', 'fo.role', 'f.name as factory_name', 'f.factory_code']);
        abort_if($rows->isEmpty(), 403, $this->say($request,
            'You are not an active factory operator.', 'Wewe si mhudumu wa kiwanda aliye hai.'));

        return $rows->map(function ($row) {
            $row->role = $row->role === 'manager' ? 'manager' : 'operator';

            return $row;
        });
    }

    private function tripsFor(Collection $memberships, ?string $uuid = null, ?Collection $tripUuids = null): Collection
    {
        $trips = DB::table('dairy_trips as t')
            ->leftJoin('dairy_factories as f', 'f.uuid', '=', 't.destination_factory_uuid')
            ->whereIn('t.destination_factory_uuid', $memberships->pluck('factory_uuid'))
            ->where('t.approval_status', '!=', 'rejected')
            ->where('t.status', '!=', 'cancelled')
            ->when($uuid, fn ($q) => $q->where('t.uuid', $uuid))
            ->select('t.uuid', 't.trip_number', 't.status', 't.phase', 't.approval_status', 't.vehicle_plate_number',
                't.destination_factory_uuid', 'f.name as destination_factory_name', 't.total_liters', 't.notes', 't.created_at', 't.approved_at',
                't.started_at', 't.arrived_at', 't.completed_at')
            ->selectSub($this->person('t.driver_user_id'), 'driver_name')
            ->selectSub($this->person('t.driver_user_id', 'phone'), 'driver_phone')
            ->orderByDesc('t.created_at')
            ->when($tripUuids !== null, fn ($q) => $q->whereIn('t.uuid', $tripUuids))
            ->when($tripUuids === null, fn ($q) => $q->limit(200))
            ->get();

        $dispatches = DB::table('cooperative_dispatches as d')
            ->leftJoin('cooperative_collection_centres as c', 'c.uuid', '=', 'd.centre_uuid')
            ->leftJoin('factory_dispatch_receipts as r', 'r.dispatch_uuid', '=', 'd.uuid')
            ->whereIn('d.trip_uuid', $trips->pluck('uuid'))
            ->orderBy('d.dispatched_at')
            ->selectSub($this->person('r.received_by_user_id'), 'received_by_name')
            ->selectSub($this->person('r.decided_by_user_id'), 'decided_by_name')
            ->addSelect([
                'd.trip_uuid', 'd.uuid', 'd.dispatch_code', 'd.batch_number', 'd.centre_uuid', 'c.name as centre_name',
                'd.quantity', 'd.unit', 'd.dispatch_temperature_celsius', 'd.security_seal_numbers', 'd.status',
                'd.loaded_at', 'd.dispatched_at',
                'r.uuid as receipt_uuid', 'r.action as receipt_action', 'r.status as receipt_status',
                'r.received_volume_liters', 'r.accepted_volume_liters', 'r.discrepancy_liters',
                'r.arrival_temperature_celsius', 'r.seals_intact', 'r.qa_status', 'r.quality_readings', 'r.reason', 'r.notes as receipt_notes',
                'r.received_at', 'r.decided_at', 'r.decision_notes',
            ])
            ->get()
            ->groupBy('trip_uuid');

        return $trips->map(function ($trip) use ($dispatches) {
            $rows = $dispatches->get($trip->uuid, collect())->values()->map(function ($row) {
                $row->security_seal_numbers = json_decode((string) ($row->security_seal_numbers ?? '[]'), true) ?: [];
                $row->receipt = $row->receipt_uuid === null ? null : [
                    'uuid' => $row->receipt_uuid,
                    'action' => $row->receipt_action,
                    'status' => $row->receipt_status,
                    'received_volume_liters' => $row->received_volume_liters,
                    'accepted_volume_liters' => $row->accepted_volume_liters,
                    'discrepancy_liters' => $row->discrepancy_liters,
                    'arrival_temperature_celsius' => $row->arrival_temperature_celsius,
                    'seals_intact' => (bool) $row->seals_intact,
                    'qa_status' => $row->qa_status,
                    'quality' => json_decode((string) ($row->quality_readings ?? ''), true) ?: null,
                    'reason' => $row->reason,
                    'notes' => $row->receipt_notes,
                    'received_by_name' => $row->received_by_name,
                    'received_at' => $row->received_at,
                    'decided_by_name' => $row->decided_by_name,
                    'decided_at' => $row->decided_at,
                    'decision_notes' => $row->decision_notes,
                ];
                foreach (['receipt_uuid', 'receipt_action', 'receipt_status', 'received_volume_liters', 'accepted_volume_liters',
                    'discrepancy_liters', 'arrival_temperature_celsius', 'seals_intact', 'qa_status', 'quality_readings', 'reason', 'receipt_notes',
                    'received_by_name', 'received_at', 'decided_by_name', 'decided_at', 'decision_notes'] as $field) {
                    unset($row->{$field});
                }

                return $row;
            });
            $trip->phase = $this->phase($trip);
            $trip->dispatches = $rows;
            $trip->dispatch_count = $rows->count();
            $trip->received_count = $rows->whereNotNull('receipt')->count();
            $trip->awaiting_approval = $rows->filter(fn ($r) => ($r->receipt['status'] ?? null) === 'waiting_approval')->count();
            $trip->can_complete = $rows->isNotEmpty() && $trip->received_count === $rows->count() && $trip->awaiting_approval > 0;

            return $trip;
        });
    }

    /** A user's display name (or phone) from their first phone identity. */
    private function person(string $userColumn, string $field = 'display_name'): Builder
    {
        return DB::table('phone_identities')->select($field)
            ->whereColumn('phone_identities.user_id', $userColumn)
            ->orderBy('phone_identities.id')
            ->limit(1);
    }

    private function phase(object $trip): string
    {
        return match ($trip->status) {
            'in_transit' => 'IN_TRANSIT',
            'arrived' => 'ARRIVED',
            'completed' => 'COMPLETED',
            default => 'PREPARING',
        };
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
