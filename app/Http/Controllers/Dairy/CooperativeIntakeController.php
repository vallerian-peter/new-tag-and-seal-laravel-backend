<?php

namespace App\Http\Controllers\Dairy;

use App\Http\Controllers\Controller;
use App\Services\Dairy\CentreStock;
use App\Services\Dairy\CooperativeAccess;
use App\Services\Dairy\MilkDelivery;
use App\Services\Dairy\SyncCursor;
use App\Services\Hiring\HiringNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CooperativeIntakeController extends Controller
{
    /**
     * GET /api/v2/dairy/intakes
     * Fetch delivery receipts for a Centre, AMCOS, MCU, or Farmer.
     */
    public function listIntakes(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $request->query('role', 'centre');
        $amcosUuid = $request->query('amcos_uuid');
        $centreUuid = $request->query('centre_uuid');
        $period = $request->query('period', 'all');

        $restrictCentres = null;
        if ($role !== 'farmer') {
            if (! $amcosUuid) {
                CooperativeAccess::deny('Select a cooperative first.');
            }
            $staff = CooperativeAccess::requireStaff($user->id, $amcosUuid, false, $centreUuid);
            $assigned = json_decode((string) ($staff->assigned_centre_uuids ?? '[]'), true) ?: [];
            if (! empty($assigned) && ! in_array($staff->role, CooperativeAccess::ADMIN_ROLES, true)) {
                $restrictCentres = $assigned;
            }
        }

        $query = DB::table('cooperative_intakes as i')
            ->leftJoin('cooperative_collection_centres as c', 'c.uuid', '=', 'i.centre_uuid')
            ->leftJoin('cooperative_amcos as a', 'a.uuid', '=', 'i.amcos_uuid')
            ->leftJoin('cooperative_mcus as m', 'm.uuid', '=', 'i.mcu_uuid')
            ->leftJoin('measurement_units as u', 'u.code', '=', 'i.unit')
            ->leftJoin('users as fu', 'fu.id', '=', 'i.farmer_user_id')
            ->leftJoin('cooperative_farmer_memberships as fm', function ($join) {
                $join->on('fm.farmer_user_id', '=', 'i.farmer_user_id')
                    ->on('fm.amcos_uuid', '=', 'i.amcos_uuid');
            })
            ->select(
                'i.*',
                'c.name as centre_name',
                'c.centre_code',
                'a.name as amcos_name',
                'm.name as mcu_name',
                'u.symbol as unit_symbol',
                'fm.farmer_name as enrolled_farmer_name',
                'fm.farmer_phone as enrolled_farmer_phone',
                'fm.member_number',
                'fu.username as farmer_username'
            )
            ->orderByDesc('i.intake_timestamp')
            ->orderByDesc('i.id');

        if ($role === 'farmer') {
            $query->where('i.farmer_user_id', $user->id);
        } else {
            if ($restrictCentres !== null) {
                $query->whereIn('i.centre_uuid', $restrictCentres);
            }
            if ($centreUuid) {
                $query->where('i.centre_uuid', $centreUuid);
            }
            if ($amcosUuid) {
                $query->where('i.amcos_uuid', $amcosUuid);
            }
        }

        if (in_array($request->query('approval_status'), ['pending', 'approved', 'rejected'], true)) {
            $query->where('i.approval_status', $request->query('approval_status'));
        }

        $present = function ($r) {
            $r->farmer_display_name = $r->enrolled_farmer_name ?: ($r->farmer_username ?: 'Mkulima');
            $r->farmer_display_phone = $r->enrolled_farmer_phone ?: '';
            return $r;
        };

        if ($request->boolean('sync')) {
            [$rows, $meta] = SyncCursor::page($query, 'i', $request->query('cursor'));

            return response()->json(['status' => true, 'data' => $rows->map($present), 'meta' => $meta]);
        }

        if ($period === 'daily') {
            $query->whereDate('i.intake_timestamp', now()->toDateString());
        } elseif ($period === 'weekly') {
            $query->where('i.intake_timestamp', '>=', now()->startOfWeek());
        } elseif ($period === 'monthly') {
            $query->where('i.intake_timestamp', '>=', now()->startOfMonth());
        }

        $rows = $query->limit(150)->get()->map($present);

        return response()->json([
            'status' => true,
            'data' => $rows,
        ]);
    }
    /**
     * POST /api/v2/dairy/intakes
     * Staff record milk received at a collection centre ("Record receive"). The
     * record is approved at once: stock goes up, the farmer's account is
     * credited at the active season price and the farmer gets an SMS.
     */
    public function recordIntake(Request $request, HiringNotifications $notifications): JsonResponse
    {
        $data = $request->validate(array_merge([
            'uuid' => 'nullable|string|max:64',
            'ledger_uuid' => 'nullable|string|max:64',
            'centre_uuid' => 'required|string|exists:cooperative_collection_centres,uuid',
            'farmer_user_id' => 'required|integer|exists:users,id',
            'unit' => 'nullable|string|exists:measurement_units,code',
            'commodity_code' => ['nullable', 'string', Rule::exists('cooperative_commodities', 'code')->where('is_active', true)],
            'delivery_session' => 'nullable|in:morning,evening,full_day',
            'gross_quantity' => 'required|numeric|gt:0',
            'accepted_quantity' => 'required|numeric|min:0',
            'rejected_quantity' => 'required|numeric|min:0',
            'rejection_reason' => 'nullable|string|max:255',
        ], MilkDelivery::readingRules()));

        $gross = round((float) $data['gross_quantity'], 2);
        $accepted = round((float) $data['accepted_quantity'], 2);
        $rejected = round((float) $data['rejected_quantity'], 2);
        if ($error = MilkDelivery::quantityError($gross, $accepted, $rejected, $data['rejection_reason'] ?? null)) {
            return response()->json(['status' => false, 'message' => $error], 422);
        }
        if ($data['is_tested'] && ! MilkDelivery::hasTestResult($data)) {
            return response()->json(['status' => false, 'message' => 'A tested delivery must include at least one test result.'], 422);
        }

        $centre = DB::table('cooperative_collection_centres')->where('uuid', $data['centre_uuid'])->firstOrFail();
        CooperativeAccess::requireStaff($request->user()->id, $centre->amcos_uuid, false, $centre->uuid);
        $commodity = $data['commodity_code'] ?? 'milk';
        $goodsUnit = CentreStock::unitOf($commodity) ?? ($centre->default_unit ?? 'l');
        $unit = $data['unit'] ?? $goodsUnit;
        $intakeUuid = $data['uuid'] ?? (string) Str::uuid();
        $existingIntake = DB::table('cooperative_intakes')->where('uuid', $intakeUuid)->first();
        if ($existingIntake) {
            abort_unless(
                $existingIntake->centre_uuid === $centre->uuid
                && (int) $existingIntake->farmer_user_id === (int) $data['farmer_user_id']
                && (int) $existingIntake->clerk_user_id === (int) $request->user()->id
                && $existingIntake->unit === $unit
                && $existingIntake->commodity_code === $commodity
                && (float) $existingIntake->gross_quantity === $gross
                && (float) $existingIntake->accepted_quantity === $accepted
                && (float) $existingIntake->rejected_quantity === $rejected
                && $existingIntake->rejection_reason === ($rejected > 0 ? $data['rejection_reason'] : null)
                && (bool) $existingIntake->is_tested === (bool) $data['is_tested']
                && (! $data['is_tested'] || collect(MilkDelivery::TEST_COLUMNS)->every(
                    fn ($field) => ($existingIntake->{$field} === null && ($data[$field] ?? null) === null)
                        || ($existingIntake->{$field} !== null && ($data[$field] ?? null) !== null && (float) $existingIntake->{$field} === (float) $data[$field])
                ))
                && $existingIntake->scale_device_id === ($data['scale_device_id'] ?? null)
                && $existingIntake->delivery_session === ($data['delivery_session'] ?? 'morning'),
                409,
                'Receipt UUID is already in use for different details.'
            );
            return response()->json(['status' => true, 'message' => 'Receipt already synced.', 'data' => $existingIntake]);
        }
        if (! $centre->is_active || $unit !== $goodsUnit) {
            return response()->json(['status' => false, 'message' => 'The centre must be active and the delivery unit must match the goods type.'], 422);
        }

        $membership = MilkDelivery::membership($centre, (int) $data['farmer_user_id']);
        if (! $membership) {
            return response()->json([
                'status' => false,
                'message' => 'Only an active farmer of this AMCOS can deliver at its collection centres.',
            ], 422);
        }

        $season = MilkDelivery::season($centre->amcos_uuid, $centre->mcu_uuid, $unit);
        if (! $season) {
            return response()->json(['status' => false, 'message' => 'No applicable active price is configured for this centre.'], 422);
        }
        $pricePerUnit = round((float) $season->price_per_unit, 2);
        $payableAmount = round($accepted * $pricePerUnit, 2);
        $actorId = (int) $request->user()->id;

        return DB::transaction(function () use ($data, $centre, $unit, $commodity, $membership, $season, $gross, $accepted, $rejected, $pricePerUnit, $payableAmount, $intakeUuid, $actorId, $notifications) {
            $centre = DB::table('cooperative_collection_centres')->where('uuid', $centre->uuid)->lockForUpdate()->firstOrFail();
            $racedIntake = DB::table('cooperative_intakes')->where('uuid', $intakeUuid)->first();
            if ($racedIntake) {
                return response()->json(['status' => true, 'message' => 'Receipt already synced.', 'data' => $racedIntake]);
            }

            DB::table('cooperative_intakes')->insert(array_merge([
                'uuid' => $intakeUuid,
                'receipt_number' => self::receiptNumber(),
                'unit' => $unit,
                'commodity_code' => $commodity,
                'mcu_uuid' => $centre->mcu_uuid,
                'amcos_uuid' => $centre->amcos_uuid,
                'centre_uuid' => $centre->uuid,
                'season_uuid' => $season->uuid ?? null,
                'delivery_session' => $data['delivery_session'] ?? 'morning',
                'farmer_user_id' => $data['farmer_user_id'],
                'clerk_user_id' => $actorId,
                'gross_quantity' => $gross,
                'accepted_quantity' => $accepted,
                'rejected_quantity' => $rejected,
                'rejection_reason' => $rejected > 0 ? $data['rejection_reason'] : null,
                'price_per_unit' => $pricePerUnit,
                'payable_amount' => $payableAmount,
                'paid_amount' => 0.00,
                'payment_status' => $payableAmount > 0 ? 'unpaid' : 'paid',
                'approval_status' => 'approved',
                'source' => 'staff',
                'reviewed_by_user_id' => $actorId,
                'reviewed_at' => now(),
                'intake_timestamp' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ], MilkDelivery::readingColumns($data)));

            $intake = DB::table('cooperative_intakes')->where('uuid', $intakeUuid)->first();
            $balance = MilkDelivery::settle($intake, $centre, $membership, $actorId, MilkDelivery::freeLedgerUuid($data['ledger_uuid'] ?? null));
            $smsBody = MilkDelivery::notifyApproved($notifications, $intake, $centre, $membership, $balance, 'cooperative_delivery_receipt');

            return response()->json([
                'status' => true,
                'message' => 'Delivery recorded and farmer account credited.',
                'sms_notice_sw' => $smsBody,
                'data' => $intake,
            ], 201);
        });
    }

    public static function receiptNumber(): string
    {
        return 'RCP-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
    }
}
