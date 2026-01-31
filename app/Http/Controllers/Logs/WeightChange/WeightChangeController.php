<?php

namespace App\Http\Controllers\Logs\WeightChange;

use Carbon\Carbon;
use App\Models\WeightChange;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;

class WeightChangeController extends Controller
{
    /**
     * GET all weight changes (optional pagination in future if needed)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $weightLogs = WeightChange::with(['livestock', 'farm'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Weight change logs retrieved successfully',
                'data' => $weightLogs
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching weight changes: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve weight logs',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Fetch weight logs by UUIDs (mobile sync fetch)
     */
    public function fetchWeightChangesWithUuid($farmUuids, $livestockUuids)
    {
        return WeightChange::whereIn('farmUuid', $farmUuids)
            ->whereIn('livestockUuid', $livestockUuids)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'uuid' => $log->uuid,
                    'farmUuid' => $log->farmUuid,
                    'livestockUuid' => $log->livestockUuid,
                    'oldWeight' => $log->oldWeight,
                    'newWeight' => $log->newWeight,
                    'remarks' => $log->remarks,
                    'eventDate' => $log->eventDate ? Carbon::parse($log->eventDate)->toIso8601String() : $log->created_at?->toIso8601String(),
                    'createdAt' => $log->created_at?->toIso8601String(),
                    'updatedAt' => $log->updated_at?->toIso8601String(),
                ];
            })
            ->toArray();
    }


    /**
     * Sync Weight Change logs from mobile app
     */
    public function processWeightChanges(array $weightLogs, string $livestockUuid): array
    {
        $syncedWeights = [];

        Log::info("========== PROCESSING WEIGHT CHANGES START ==========");
        Log::info("Total logs to process: " . count($weightLogs));
        Log::info("Livestock UUID: {$livestockUuid}");

        foreach ($weightLogs as $logData) {
            try {
                $syncAction = $logData['syncAction'] ?? 'create';
                $uuid = $logData['uuid'] ?? null;

                Log::info("Processing weight change: UUID={$uuid}, Action={$syncAction}");

                if (!$uuid) {
                    Log::warning("⚠️ Skipped log without UUID");
                    continue;
                }

                // force correct livestock uuid
                $logData['livestockUuid'] = $livestockUuid;
                $farmUuid = $logData['farmUuid'] ?? null;

                $createdAt = isset($logData['createdAt'])
                    ? Carbon::parse($logData['createdAt'])->format('Y-m-d H:i:s')
                    : now();

                $updatedAt = isset($logData['updatedAt'])
                    ? Carbon::parse($logData['updatedAt'])->format('Y-m-d H:i:s')
                    : now();

                // Handle eventDate - if not provided, default to createdAt for backward compatibility
                $eventDate = isset($logData['eventDate'])
                    ? Carbon::parse($logData['eventDate'])->format('Y-m-d H:i:s')
                    : $createdAt;

                $oldWeight = isset($logData['oldWeight'])
                    ? trim((string) $logData['oldWeight'])
                    : null;
                $oldWeight = $oldWeight === '' ? null : $oldWeight;
                $newWeight = trim((string) ($logData['newWeight'] ?? ''));

                switch ($syncAction) {

                    case 'create':
                        $existing = WeightChange::where('uuid', $uuid)->first();

                        if ($existing) {
                            // if mobile entry newer than server, update
                            if (Carbon::parse($updatedAt)->greaterThan(Carbon::parse($existing->updated_at))) {
                                $existing->update([
                                    'farmUuid' => $farmUuid,
                                    'livestockUuid' => $livestockUuid,
                                    'oldWeight' => $oldWeight,
                                    'newWeight' => $newWeight,
                                    'remarks' => $logData['remarks'] ?? null,
                                    'eventDate' => $eventDate,
                                    'updated_at' => $updatedAt,
                                ]);
                                Log::info("✅ Weight log updated (local newer): UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Skip update, server newer");
                            }
                        } else {
                            WeightChange::create([
                                'uuid' => $uuid,
                                'eventDate' => $eventDate,
                                'farmUuid' => $farmUuid,
                                'livestockUuid' => $livestockUuid,
                                'oldWeight' => $oldWeight,
                                'newWeight' => $newWeight,
                                'remarks' => $logData['remarks'] ?? null,
                                'created_at' => $createdAt,
                                'updated_at' => $updatedAt,
                            ]);

                            Log::info("✅ Weight log created: UUID {$uuid}");
                        }

                        $syncedWeights[] = ['uuid' => $uuid];
                        break;


                    case 'update':
                        $log = WeightChange::where('uuid', $uuid)->first();

                        if ($log) {
                            if (Carbon::parse($updatedAt)->greaterThan(Carbon::parse($log->updated_at))) {
                                $log->update([
                                    'farmUuid' => $farmUuid,
                                    'oldWeight' => $oldWeight,
                                    'newWeight' => $newWeight,
                                    'remarks' => $logData['remarks'] ?? null,
                                    'eventDate' => $eventDate,
                                    'updated_at' => $updatedAt,
                                ]);
                                Log::info("✅ Weight log updated: UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Skip update, server newer");
                            }
                        } else {
                            Log::warning("⚠️ Weight log UUID not found for update");
                        }

                        $syncedWeights[] = ['uuid' => $uuid];
                        break;


                    case 'deleted':
                        $log = WeightChange::where('uuid', $uuid)->first();

                        if ($log) {
                            $log->delete();
                            Log::info("✅ Weight log deleted: UUID {$uuid}");
                        } else {
                            Log::info("⏭️ Already deleted on server");
                        }

                        $syncedWeights[] = ['uuid' => $uuid];
                        break;


                    default:
                        Log::warning("⚠️ Unknown sync action for weight change: {$syncAction}");
                        break;
                }

            } catch (\Exception $e) {
                Log::error("❌ ERROR PROCESSING WEIGHT CHANGE", [
                    'uuid' => $uuid ?? 'unknown',
                    'syncAction' => $syncAction,
                    'error' => $e->getMessage(),
                    'payload' => $logData,
                ]);

                continue;
            }
        }

        Log::info("========== PROCESSING WEIGHT CHANGES END ==========");
        Log::info("Total logs synced: " . count($syncedWeights));

        return $syncedWeights;
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $weightChanges = WeightChange::with(['livestock', 'farm'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Weight changes retrieved successfully',
            'data' => $weightChanges,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'required|string|unique:weight_changes,uuid',
            'farmUuid' => 'required|string|exists:farms,uuid',
            'livestockUuid' => 'required|string|exists:livestocks,uuid',
            'oldWeight' => 'nullable|string|max:255',
            'newWeight' => 'nullable|string|max:255',
            'remarks' => 'nullable|string',
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
        if ($request->has('eventDate')) {
            $data['eventDate'] = Carbon::parse($request->eventDate)->format('Y-m-d H:i:s');
        } else {
            // Default to now if not provided
            $data['eventDate'] = now()->format('Y-m-d H:i:s');
        }

        $weightChange = WeightChange::create($data);

        $weightChange->load(['livestock', 'farm']);

        return response()->json([
            'status' => true,
            'message' => 'Weight change created successfully',
            'data' => $weightChange,
        ], 201);
    }

    public function adminShow(WeightChange $weightChange): JsonResponse
    {
        $weightChange->load(['livestock', 'farm']);

        return response()->json([
            'status' => true,
            'message' => 'Weight change retrieved successfully',
            'data' => $weightChange,
        ], 200);
    }

    public function adminUpdate(Request $request, WeightChange $weightChange): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'sometimes|required|string|unique:weight_changes,uuid,' . $weightChange->id,
            'farmUuid' => 'sometimes|required|string|exists:farms,uuid',
            'livestockUuid' => 'sometimes|required|string|exists:livestocks,uuid',
            'oldWeight' => 'sometimes|nullable|string|max:255',
            'newWeight' => 'sometimes|nullable|string|max:255',
            'remarks' => 'sometimes|nullable|string',
            'eventDate' => 'sometimes|nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->except(['eventDate']);
        if ($request->has('eventDate')) {
            $data['eventDate'] = Carbon::parse($request->eventDate)->format('Y-m-d H:i:s');
        }

        $weightChange->fill($data);
        $weightChange->save();

        $weightChange->load(['livestock', 'farm']);

        return response()->json([
            'status' => true,
            'message' => 'Weight change updated successfully',
            'data' => $weightChange,
        ], 200);
    }

    public function adminDestroy(WeightChange $weightChange): JsonResponse
    {
        $weightChange->delete();

        return response()->json([
            'status' => true,
            'message' => 'Weight change deleted successfully',
        ], 200);
    }
}
