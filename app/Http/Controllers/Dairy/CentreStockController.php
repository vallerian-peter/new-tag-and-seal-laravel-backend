<?php

namespace App\Http\Controllers\Dairy;

use App\Http\Controllers\Controller;
use App\Services\Dairy\BatchNumber;
use App\Services\Dairy\CentreStock;
use App\Services\Dairy\CooperativeAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CentreStockController extends Controller
{
    /**
     * POST /api/v2/dairy/centre-stock/movement
     * Record outgoing quantity or audited correction against Centre-Level Balance.
     */
    public function recordCentreStockMovement(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_uuid' => 'required|string|exists:cooperative_collection_centres,uuid',
            'movement_type' => 'required|in:outgoing_dispatch,spillage_loss,correction',
            'quantity' => 'required|numeric|not_in:0',
            'destination_or_reason' => 'required|string|max:255',
            'uuid' => 'nullable|string|max:64',
            'commodity_code' => ['nullable', 'string', Rule::exists('cooperative_commodities', 'code')->where('is_active', true)],
            'destination_uuid' => 'nullable|string|max:64',
            'transporter_company_uuid' => 'nullable|string|max:64',
            'transporter_name' => 'nullable|string|max:255',
            'vehicle_uuid' => 'nullable|string|max:64',
            'vehicle_plate_number' => 'nullable|string|max:50',
            'driver_user_id' => 'nullable|integer',
            'driver_name' => 'nullable|string|max:255',
            'driver_phone' => 'nullable|string|max:50',
            'temperature_celsius' => 'nullable|numeric|between:-10,60',
            'batch_number' => 'nullable|string|max:60',
            'loaded_at' => 'nullable|date',
            'security_seal_numbers' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        $centre = DB::table('cooperative_collection_centres')->where('uuid', $data['centre_uuid'])->firstOrFail();
        CooperativeAccess::requireStaff($request->user()->id, $centre->amcos_uuid, false, $centre->uuid);

        $qty = round((float) $data['quantity'], 2);
        if ($qty == 0.0) {
            return response()->json(['status' => false, 'message' => 'Quantity must not round to zero.'], 422);
        }
        $signedQty = in_array($data['movement_type'], ['outgoing_dispatch', 'spillage_loss'], true)
            ? -abs($qty)
            : $qty;

        $commodity = $data['commodity_code'] ?? 'milk';
        $unit = CentreStock::unitOf($commodity) ?? ($centre->default_unit ?? 'l');

        return DB::transaction(function () use ($request, $data, $centre, $signedQty, $commodity, $unit) {
            $centre = DB::table('cooperative_collection_centres')->where('uuid', $centre->uuid)->lockForUpdate()->firstOrFail();
            $stock = CentreStock::lock($centre->uuid, $commodity, $unit);
            if (! empty($data['uuid'])) {
                $dup = DB::table('cooperative_centre_stock_ledgers')->where('uuid', $data['uuid'])->first();
                if ($dup) {
                    abort_unless(
                        $dup->centre_uuid === $centre->uuid
                        && $dup->movement_type === $data['movement_type']
                        && $dup->commodity_code === $commodity
                        && (float) $dup->quantity === $signedQty
                        && $dup->destination_or_reason === trim($data['destination_or_reason'])
                        && (int) $dup->recorded_by_user_id === (int) $request->user()->id,
                        409,
                        'Movement UUID is already in use for different details.'
                    );

                    return response()->json([
                        'status' => true,
                        'message' => 'Movement already synced.',
                        'data' => ['movement_uuid' => $dup->uuid, 'commodity_code' => $commodity, 'current_balance' => (float) $stock->current_balance, 'total_dispatched' => (float) $stock->total_dispatched],
                    ]);
                }
            }
            if (round((float) $stock->current_balance + $signedQty, 2) < 0) {
                return response()->json(['status' => false, 'message' => 'Confirmed centre stock balance cannot become negative.'], 422);
            }
            $stock = CentreStock::apply(
                $stock,
                $signedQty,
                0,
                $data['movement_type'] === 'outgoing_dispatch' ? abs($signedQty) : 0,
            );
            $newBalance = (float) $stock->current_balance;
            $newDispatched = (float) $stock->total_dispatched;

            $movementUuid = $data['uuid'] ?? (string) Str::uuid();
            $ledgerData = [
                'uuid' => $movementUuid,
                'unit' => $stock->unit,
                'commodity_code' => $commodity,
                'mcu_uuid' => $centre->mcu_uuid,
                'amcos_uuid' => $centre->amcos_uuid,
                'centre_uuid' => $centre->uuid,
                'movement_type' => $data['movement_type'],
                'quantity' => $signedQty,
                'running_balance' => $newBalance,
                'destination_or_reason' => trim($data['destination_or_reason']),
                'recorded_by_user_id' => $request->user()->id,
                'movement_date' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('cooperative_centre_stock_ledgers', 'dispatch_uuid')) {
                $ledgerData['dispatch_uuid'] = $movementUuid;
            }
            DB::table('cooperative_centre_stock_ledgers')->insert($ledgerData);

            if ($data['movement_type'] === 'outgoing_dispatch' && Schema::hasTable('cooperative_dispatches')) {
                $dispatchCode = 'DSP-'.strtoupper(substr(str_replace('-', '', $movementUuid), 0, 8));
                DB::table('cooperative_dispatches')->updateOrInsert(
                    ['uuid' => $movementUuid],
                    [
                        'dispatch_code' => $dispatchCode,
                        'batch_number' => BatchNumber::resolve($data['batch_number'] ?? null, $movementUuid),
                        'centre_uuid' => $centre->uuid,
                        'commodity_code' => $commodity,
                        'quantity' => abs($signedQty),
                        'unit' => $stock->unit,
                        'destination_type' => 'factory',
                        'destination_uuid' => $data['destination_uuid'] ?? null,
                        'destination_name' => trim($data['destination_or_reason']),
                        'transporter_company_uuid' => $data['transporter_company_uuid'] ?? null,
                        'transporter_name' => $data['transporter_name'] ?? null,
                        'vehicle_uuid' => $data['vehicle_uuid'] ?? null,
                        'vehicle_plate_number' => $data['vehicle_plate_number'] ?? null,
                        'driver_user_id' => $data['driver_user_id'] ?? null,
                        'driver_name' => $data['driver_name'] ?? null,
                        'driver_phone' => $data['driver_phone'] ?? null,
                        'dispatch_temperature_celsius' => $data['temperature_celsius'] ?? null,
                        'loaded_at' => isset($data['loaded_at']) ? Carbon::parse($data['loaded_at']) : now(),
                        'security_seal_numbers' => isset($data['security_seal_numbers']) ? json_encode($data['security_seal_numbers']) : null,
                        'notes' => $data['notes'] ?? null,
                        'status' => 'dispatched',
                        'dispatched_at' => now(),
                        'dispatched_by_user_id' => $request->user()->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            return response()->json([
                'status' => true,
                'message' => 'Centre stock movement recorded.',
                'data' => [
                    'movement_uuid' => $movementUuid,
                    'commodity_code' => $commodity,
                    'current_balance' => $newBalance,
                    'total_dispatched' => $newDispatched,
                ],
            ], 201);
        });
    }
}
