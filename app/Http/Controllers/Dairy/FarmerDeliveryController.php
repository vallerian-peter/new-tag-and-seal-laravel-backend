<?php

namespace App\Http\Controllers\Dairy;

use App\Http\Controllers\Controller;
use App\Services\Dairy\CentreStock;
use App\Services\Dairy\CooperativeAccess;
use App\Services\Dairy\MilkDelivery;
use App\Services\Hiring\HiringNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Farmer-declared deliveries wait as `pending` until centre staff measure,
 * test and approve them (stock + account credit) or reject them with a reason.
 */
class FarmerDeliveryController extends Controller
{
    /** POST /api/v2/dairy/deliveries */
    public function declare(Request $request): JsonResponse
    {
        $data = $request->validate(array_merge([
            'uuid' => 'required|string|max:64',
            'centre_uuid' => 'required|string|exists:cooperative_collection_centres,uuid',
            'commodity_code' => ['nullable', 'string', Rule::exists('cooperative_commodities', 'code')->where('is_active', true)],
            'delivery_session' => 'nullable|in:morning,evening,full_day',
            'declared_quantity' => 'required|numeric|gt:0',
            'declared_at' => 'nullable|date',
            'farmer_note' => 'nullable|string|max:255',
        ], MilkDelivery::readingRules()));

        $user = $request->user();
        $centre = DB::table('cooperative_collection_centres')->where('uuid', $data['centre_uuid'])->first();
        $commodity = $data['commodity_code'] ?? 'milk';
        $unit = CentreStock::unitOf($commodity) ?? ($centre->default_unit ?? 'l');
        $quantity = round((float) $data['declared_quantity'], 2);

        $existing = DB::table('cooperative_intakes')->where('uuid', $data['uuid'])->first();
        if ($existing) {
            abort_unless(
                (int) $existing->farmer_user_id === (int) $user->id
                && $existing->centre_uuid === $centre->uuid
                && (float) $existing->declared_quantity === $quantity,
                409,
                'Delivery UUID is already in use for different details.'
            );

            return response()->json(['status' => true, 'message' => 'Delivery already synced.', 'data' => $existing]);
        }
        if (! $centre->is_active) {
            return response()->json(['status' => false, 'message' => 'This collection centre is not active.'], 422);
        }
        $membership = MilkDelivery::membership($centre, (int) $user->id);
        if (! $membership) {
            return response()->json(['status' => false, 'message' => 'Only an active farmer of this AMCOS can deliver at its collection centres.'], 422);
        }

        $readings = MilkDelivery::readingColumns($data);
        $farmerReadings = array_filter(
            array_merge(
                array_intersect_key($readings, array_flip(MilkDelivery::TEST_COLUMNS)),
                json_decode((string) ($readings['quality_metrics_json'] ?? '{}'), true) ?: [],
                ['weight_kg' => $readings['weight_kg']]
            ),
            fn ($value) => $value !== null
        );

        DB::table('cooperative_intakes')->insert([
            'uuid' => $data['uuid'],
            'receipt_number' => CooperativeIntakeController::receiptNumber(),
            'unit' => $unit,
            'commodity_code' => $commodity,
            'mcu_uuid' => $centre->mcu_uuid,
            'amcos_uuid' => $centre->amcos_uuid,
            'centre_uuid' => $centre->uuid,
            'delivery_session' => $data['delivery_session'] ?? 'morning',
            'farmer_user_id' => $user->id,
            'gross_quantity' => $quantity,
            'accepted_quantity' => 0,
            'rejected_quantity' => 0,
            'declared_quantity' => $quantity,
            'declared_at' => isset($data['declared_at']) ? Carbon::parse($data['declared_at']) : now(),
            'farmer_note' => $data['farmer_note'] ?? null,
            'measurement_method' => $readings['measurement_method'],
            'weight_kg' => $readings['weight_kg'],
            'quality_metrics_json' => $farmerReadings === [] ? null : json_encode(['farmer_readings' => $farmerReadings]),
            'payment_status' => 'unpaid',
            'approval_status' => 'pending',
            'source' => 'farmer',
            'intake_timestamp' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Delivery sent. It is waiting for the collection centre to approve it.',
            'data' => DB::table('cooperative_intakes')->where('uuid', $data['uuid'])->first(),
        ], 201);
    }

    /** POST /api/v2/dairy/intakes/{uuid}/review */
    public function review(Request $request, string $uuid, HiringNotifications $notifications): JsonResponse
    {
        $action = $request->input('action');
        $rules = [
            'review_uuid' => 'required|string|max:64',
            'action' => 'required|in:approve,reject',
        ];
        $rules += $action === 'reject'
            ? [
                'rejection_category' => ['required', Rule::in(MilkDelivery::REJECTION_CATEGORIES)],
                'rejection_reason' => 'required_if:rejection_category,other|nullable|string|max:255',
            ]
            : array_merge([
                'ledger_uuid' => 'nullable|string|max:64',
                'gross_quantity' => 'required|numeric|gt:0',
                'accepted_quantity' => 'required|numeric|min:0',
                'rejected_quantity' => 'required|numeric|min:0',
                'rejection_reason' => 'nullable|string|max:255',
            ], MilkDelivery::readingRules());
        $data = $request->validate($rules);

        $intake = DB::table('cooperative_intakes')->where('uuid', $uuid)->first();
        abort_unless($intake, 404, 'Delivery not found.');
        $actorId = (int) $request->user()->id;
        CooperativeAccess::requireStaff($actorId, $intake->amcos_uuid, false, $intake->centre_uuid);
        if ($intake->review_uuid === $data['review_uuid']) {
            return response()->json(['status' => true, 'message' => 'Review already synced.', 'data' => $intake]);
        }
        if ($intake->approval_status !== 'pending') {
            return response()->json(['status' => false, 'message' => 'This delivery has already been reviewed.'], 409);
        }

        $centre = DB::table('cooperative_collection_centres')->where('uuid', $intake->centre_uuid)->first();
        $membership = DB::table('cooperative_farmer_memberships')
            ->where('amcos_uuid', $intake->amcos_uuid)
            ->where('farmer_user_id', $intake->farmer_user_id)
            ->first();

        if ($data['action'] === 'reject') {
            return DB::transaction(function () use ($data, $uuid, $actorId, $centre, $membership, $notifications) {
                $locked = DB::table('cooperative_intakes')->where('uuid', $uuid)->lockForUpdate()->first();
                if ($locked->approval_status !== 'pending') {
                    return response()->json(['status' => false, 'message' => 'This delivery has already been reviewed.'], 409);
                }
                DB::table('cooperative_intakes')->where('uuid', $uuid)->update([
                    'approval_status' => 'rejected',
                    'rejection_category' => $data['rejection_category'],
                    'rejection_reason' => trim((string) ($data['rejection_reason'] ?? '')) ?: null,
                    'accepted_quantity' => 0,
                    'rejected_quantity' => $locked->gross_quantity,
                    'payment_status' => 'paid',
                    'clerk_user_id' => $actorId,
                    'reviewed_by_user_id' => $actorId,
                    'reviewed_at' => now(),
                    'review_uuid' => $data['review_uuid'],
                    'updated_at' => now(),
                ]);
                $intake = DB::table('cooperative_intakes')->where('uuid', $uuid)->first();
                if ($membership) {
                    MilkDelivery::notifyRejected($notifications, $intake, $centre, $membership);
                }

                return response()->json(['status' => true, 'message' => 'Delivery rejected and the farmer notified.', 'data' => $intake]);
            });
        }

        $gross = round((float) $data['gross_quantity'], 2);
        $accepted = round((float) $data['accepted_quantity'], 2);
        $rejected = round((float) $data['rejected_quantity'], 2);
        if ($error = MilkDelivery::quantityError($gross, $accepted, $rejected, $data['rejection_reason'] ?? null)) {
            return response()->json(['status' => false, 'message' => $error], 422);
        }
        if ($data['is_tested'] && ! MilkDelivery::hasTestResult($data)) {
            return response()->json(['status' => false, 'message' => 'A tested delivery must include at least one test result.'], 422);
        }
        if (! $membership || $membership->status !== 'active') {
            return response()->json(['status' => false, 'message' => 'Only an active farmer of this AMCOS can deliver at its collection centres.'], 422);
        }
        $season = MilkDelivery::season($intake->amcos_uuid, $intake->mcu_uuid, $intake->unit);
        if (! $season) {
            return response()->json(['status' => false, 'message' => 'No applicable active price is configured for this centre.'], 422);
        }
        $price = round((float) $season->price_per_unit, 2);
        $payable = round($accepted * $price, 2);

        return DB::transaction(function () use ($data, $uuid, $actorId, $centre, $membership, $season, $gross, $accepted, $rejected, $price, $payable, $notifications) {
            $centre = DB::table('cooperative_collection_centres')->where('uuid', $centre->uuid)->lockForUpdate()->first();
            $locked = DB::table('cooperative_intakes')->where('uuid', $uuid)->lockForUpdate()->first();
            if ($locked->approval_status !== 'pending') {
                return response()->json(['status' => false, 'message' => 'This delivery has already been reviewed.'], 409);
            }
            $previous = json_decode((string) ($locked->quality_metrics_json ?? '{}'), true) ?: [];
            $keep = isset($previous['farmer_readings']) ? ['farmer_readings' => $previous['farmer_readings']] : [];

            DB::table('cooperative_intakes')->where('uuid', $uuid)->update(array_merge(MilkDelivery::readingColumns($data, $keep), [
                'gross_quantity' => $gross,
                'accepted_quantity' => $accepted,
                'rejected_quantity' => $rejected,
                'rejection_reason' => $rejected > 0 ? trim((string) $data['rejection_reason']) : null,
                'season_uuid' => $season->uuid ?? null,
                'price_per_unit' => $price,
                'payable_amount' => $payable,
                'payment_status' => $payable > 0 ? 'unpaid' : 'paid',
                'approval_status' => 'approved',
                'clerk_user_id' => $actorId,
                'reviewed_by_user_id' => $actorId,
                'reviewed_at' => now(),
                'review_uuid' => $data['review_uuid'],
                'intake_timestamp' => now(),
                'updated_at' => now(),
            ]));
            $intake = DB::table('cooperative_intakes')->where('uuid', $uuid)->first();
            $balance = MilkDelivery::settle($intake, $centre, $membership, $actorId, MilkDelivery::freeLedgerUuid($data['ledger_uuid'] ?? null));
            MilkDelivery::notifyApproved($notifications, $intake, $centre, $membership, $balance);

            return response()->json(['status' => true, 'message' => 'Delivery approved and farmer account credited.', 'data' => $intake]);
        });
    }
}
