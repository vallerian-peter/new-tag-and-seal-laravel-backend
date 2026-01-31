<?php

namespace App\Http\Controllers\Logs\Transfer;

use App\Http\Controllers\Controller;
use App\Models\Transfer;
use App\Traits\ConvertsDateFormat;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TransferController extends Controller
{
    use ConvertsDateFormat;
    /**
     * Display a listing of transfer logs.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $transfers = Transfer::with(['fromFarm', 'toFarm', 'livestock'])
                ->orderByDesc('created_at')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Transfers retrieved successfully',
                'data' => $transfers,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching transfers: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve transfers',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch transfers filtered by farm UUIDs only.
     *
     * Returns transfers where the farm (either from farm or to farm) matches the passed farm UUIDs.
     * Note: Livestock filtering is removed because transferred livestock may no longer be in the source farm.
     *
     * @param array $farmUuids Farm UUIDs to filter by
     * @param array $livestockUuids Ignored (kept for backward compatibility)
     * @return array
     */
    public function fetchTransfersWithUuid(array $farmUuids, array $livestockUuids = []): array
    {
        if (empty($farmUuids)) {
            return [];
        }

        return Transfer::with([
                'fromFarm:uuid,name',
                'toFarm:uuid,name',
                'livestock:uuid,name',
            ])
            ->where(function ($query) use ($farmUuids) {
                // Check if from farm (farmUuid) OR to farm (toFarmUuid) matches any passed farm UUID
                $query->whereIn('farmUuid', $farmUuids)
                      ->orWhereIn('toFarmUuid', $farmUuids);
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Transfer $transfer) {
                return [
                    'id' => $transfer->id,
                    'uuid' => $transfer->uuid,
                    'farmUuid' => $transfer->farmUuid,
                    'livestockUuid' => $transfer->livestockUuid,
                    'toFarmUuid' => $transfer->toFarmUuid,
                    'transporterId' => $transfer->transporterId,
                    'reason' => $transfer->reason,
                    'price' => $transfer->price,
                    'transferDate' => $transfer->transferDate,
                    'remarks' => $transfer->remarks,
                    'status' => $transfer->status,
                    'farmName' => optional($transfer->fromFarm)->name,
                    'toFarmName' => optional($transfer->toFarm)->name,
                    'livestockName' => optional($transfer->livestock)->name,
                    'eventDate' => $transfer->eventDate ? Carbon::parse($transfer->eventDate)->toIso8601String() : $transfer->created_at?->toIso8601String(),
                    'createdAt' => $transfer->created_at?->toIso8601String(),
                    'updatedAt' => $transfer->updated_at?->toIso8601String(),
                ];
            })
            ->toArray();
    }

    /**
     * Process transfer records from the mobile app.
     */
    public function processTransfers(array $transfers, string $livestockUuid): array
    {
        $syncedTransfers = [];

        Log::info('========== PROCESSING TRANSFERS START ==========');
        Log::info('Total transfers to process: ' . count($transfers));
        Log::info("Livestock UUID: {$livestockUuid}");

        foreach ($transfers as $transferData) {
            $syncAction = $transferData['syncAction'] ?? 'create';
            $uuid = $transferData['uuid'] ?? null;

            try {
                if (!$uuid) {
                    Log::warning('⚠️ Transfer without UUID skipped', ['transfer' => $transferData]);
                    continue;
                }

                $transferData['livestockUuid'] = $livestockUuid;

                $transferDate = isset($transferData['transferDate'])
                    ? $this->convertDateTimeFormat($transferData['transferDate'])
                    : now()->format('Y-m-d H:i:s');

                $createdAt = isset($transferData['createdAt'])
                    ? Carbon::parse($transferData['createdAt'])->format('Y-m-d H:i:s')
                    : now();

                $updatedAt = isset($transferData['updatedAt'])
                    ? Carbon::parse($transferData['updatedAt'])->format('Y-m-d H:i:s')
                    : now();

                // Handle eventDate - if not provided, default to createdAt for backward compatibility
                $eventDate = isset($transferData['eventDate'])
                    ? Carbon::parse($transferData['eventDate'])->format('Y-m-d H:i:s')
                    : $createdAt;

                $price = isset($transferData['price'])
                    ? (string)$transferData['price']
                    : null;

                switch ($syncAction) {
                    case 'create':
                        $existing = Transfer::where('uuid', $uuid)->first();

                        if ($existing) {
                            $local = Carbon::parse($updatedAt);
                            $server = Carbon::parse($existing->updated_at);

                            if ($local->greaterThan($server)) {
                                $existing->update([
                                    'farmUuid' => $transferData['farmUuid'] ?? $existing->farmUuid,
                                    'livestockUuid' => $livestockUuid,
                                    'toFarmUuid' => $transferData['toFarmUuid'] ?? $existing->toFarmUuid,
                                    'transporterId' => $transferData['transporterId'] ?? $existing->transporterId,
                                    'reason' => $transferData['reason'] ?? $existing->reason,
                                    'price' => $price,
                                    'transferDate' => $transferDate,
                                    'remarks' => $transferData['remarks'] ?? $existing->remarks,
                                    'status' => $transferData['status'] ?? $existing->status,
                                    'eventDate' => $eventDate,
                                    'updated_at' => $updatedAt,
                                ]);
                                Log::info("✅ Transfer updated (local newer): UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Transfer skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Transfer::create([
                                'uuid' => $uuid,
                                'eventDate' => $eventDate,
                                'farmUuid' => $transferData['farmUuid'] ?? null,
                                'livestockUuid' => $livestockUuid,
                                'toFarmUuid' => $transferData['toFarmUuid'] ?? null,
                                'transporterId' => $transferData['transporterId'] ?? null,
                                'reason' => $transferData['reason'] ?? null,
                                'price' => $price,
                                'transferDate' => $transferDate,
                                'remarks' => $transferData['remarks'] ?? null,
                                'status' => $transferData['status'] ?? null,
                                'created_at' => $createdAt,
                                'updated_at' => $updatedAt,
                            ]);
                            Log::info("✅ Transfer created: UUID {$uuid}");
                        }

                        $syncedTransfers[] = ['uuid' => $uuid];
                        break;

                    case 'update':
                        $transfer = Transfer::where('uuid', $uuid)->first();

                        if ($transfer) {
                            $local = Carbon::parse($updatedAt);
                            $server = Carbon::parse($transfer->updated_at);

                            if ($local->greaterThan($server)) {
                                $transfer->update([
                                    'farmUuid' => $transferData['farmUuid'] ?? $transfer->farmUuid,
                                    'toFarmUuid' => $transferData['toFarmUuid'] ?? $transfer->toFarmUuid,
                                    'transporterId' => $transferData['transporterId'] ?? $transfer->transporterId,
                                    'reason' => $transferData['reason'] ?? $transfer->reason,
                                    'price' => $price ?? $transfer->price,
                                    'transferDate' => $transferDate,
                                    'remarks' => $transferData['remarks'] ?? $transfer->remarks,
                                    'status' => $transferData['status'] ?? $transfer->status,
                                    'eventDate' => $eventDate,
                                    'updated_at' => $updatedAt,
                                ]);
                                Log::info("✅ Transfer updated: UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Transfer update skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Log::warning("⚠️ Transfer not found for update: UUID {$uuid}");
                        }

                        $syncedTransfers[] = ['uuid' => $uuid];
                        break;

                    case 'deleted':
                        $transfer = Transfer::where('uuid', $uuid)->first();
                        if ($transfer) {
                            $transfer->delete();
                            Log::info("✅ Transfer deleted: UUID {$uuid}");
                        } else {
                            Log::info("⏭️ Transfer already deleted on server: UUID {$uuid}");
                        }
                        $syncedTransfers[] = ['uuid' => $uuid];
                        break;

                    default:
                        Log::warning("⚠️ Unknown sync action for transfer: {$syncAction}", ['uuid' => $uuid]);
                        break;
                }
            } catch (\Exception $e) {
                Log::error('❌ ERROR PROCESSING TRANSFER', [
                    'uuid' => $uuid ?? 'unknown',
                    'syncAction' => $syncAction ?? 'unknown',
                    'error' => $e->getMessage(),
                    'transferData' => $transferData,
                ]);
                continue;
            }
        }

        Log::info('========== PROCESSING TRANSFERS END ==========');
        Log::info('Total transfers synced: ' . count($syncedTransfers));

        return $syncedTransfers;
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $transfers = Transfer::with(['livestock', 'fromFarm', 'toFarm'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Transfers retrieved successfully',
            'data' => $transfers,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'required|string|unique:transfers,uuid',
            'farmUuid' => 'required|string|exists:farms,uuid',
            'livestockUuid' => 'required|string|exists:livestocks,uuid',
            'toFarmUuid' => 'required|string|exists:farms,uuid',
            'transporterId' => 'nullable|string|max:255',
            'reason' => 'nullable|string',
            'price' => 'nullable|numeric',
            'transferDate' => 'nullable|date',
            'remarks' => 'nullable|string',
            'status' => 'nullable|string|in:active,inactive',
            'eventDate' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->all();
        if ($request->has('transferDate')) {
            $data['transferDate'] = $this->convertDateFormat($request->transferDate);
        }
        if ($request->has('eventDate')) {
            $data['eventDate'] = Carbon::parse($request->eventDate)->format('Y-m-d H:i:s');
        } else {
            // Default to now if not provided
            $data['eventDate'] = now()->format('Y-m-d H:i:s');
        }

        $transfer = Transfer::create($data);

        $transfer->load(['livestock', 'fromFarm', 'toFarm']);

        return response()->json([
            'status' => true,
            'message' => 'Transfer created successfully',
            'data' => $transfer,
        ], 201);
    }

    public function adminShow(Transfer $transfer): JsonResponse
    {
        $transfer->load(['livestock', 'fromFarm', 'toFarm']);

        return response()->json([
            'status' => true,
            'message' => 'Transfer retrieved successfully',
            'data' => $transfer,
        ], 200);
    }

    public function adminUpdate(Request $request, Transfer $transfer): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'sometimes|required|string|unique:transfers,uuid,' . $transfer->id,
            'farmUuid' => 'sometimes|required|string|exists:farms,uuid',
            'livestockUuid' => 'sometimes|required|string|exists:livestocks,uuid',
            'toFarmUuid' => 'sometimes|required|string|exists:farms,uuid',
            'transporterId' => 'sometimes|nullable|string|max:255',
            'reason' => 'sometimes|nullable|string',
            'price' => 'sometimes|nullable|numeric',
            'transferDate' => 'sometimes|nullable|date',
            'remarks' => 'sometimes|nullable|string',
            'status' => 'sometimes|nullable|string|in:active,inactive',
            'eventDate' => 'sometimes|nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->except(['transferDate', 'eventDate']);

        if ($request->has('transferDate')) {
            $data['transferDate'] = $this->convertDateFormat($request->transferDate);
        }
        if ($request->has('eventDate')) {
            $data['eventDate'] = Carbon::parse($request->eventDate)->format('Y-m-d H:i:s');
        }

        $transfer->fill($data);
        $transfer->save();

        $transfer->load(['livestock', 'fromFarm', 'toFarm']);

        return response()->json([
            'status' => true,
            'message' => 'Transfer updated successfully',
            'data' => $transfer,
        ], 200);
    }

    public function adminDestroy(Transfer $transfer): JsonResponse
    {
        $transfer->delete();

        return response()->json([
            'status' => true,
            'message' => 'Transfer deleted successfully',
        ], 200);
    }
}

