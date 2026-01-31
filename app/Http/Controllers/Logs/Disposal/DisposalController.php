<?php

namespace App\Http\Controllers\Logs\Disposal;

use App\Http\Controllers\Controller;
use App\Models\Disposal;
use App\Models\Livestock;
use App\Models\DisposalType;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DisposalController extends Controller
{
    /**
     * Display a listing of disposals.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $disposals = Disposal::with(['livestock', 'farm', 'disposalType'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Disposal logs retrieved successfully',
                'data' => $disposals,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching disposals: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve disposals',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch disposals for given farm and livestock UUIDs.
     */
    public function fetchDisposalsWithUuid($farmUuids, $livestockUuids): array
    {
        if (empty($farmUuids) || empty($livestockUuids)) {
            return [];
        }

        return Disposal::whereIn('farmUuid', (array) $farmUuids)
            ->whereIn('livestockUuid', (array) $livestockUuids)
            ->get()
            ->map(function (Disposal $log) {
                return [
                    'id' => $log->id,
                    'uuid' => $log->uuid,
                    'farmUuid' => $log->farmUuid,
                    'livestockUuid' => $log->livestockUuid,
                    'disposalTypeId' => $log->disposalTypeId,
                    'reasons' => $log->reasons,
                    'remarks' => $log->remarks,
                    'status' => $log->status,
                    'eventDate' => $log->eventDate ? Carbon::parse($log->eventDate)->toIso8601String() : $log->created_at?->toIso8601String(),
                    'createdAt' => $log->created_at?->toIso8601String(),
                    'updatedAt' => $log->updated_at?->toIso8601String(),
                ];
            })
            ->toArray();
    }

    /**
     * Process disposal records coming from the mobile app.
     */
    public function processDisposals(array $disposals, string $livestockUuid): array
    {
        $syncedDisposals = [];

        Log::info('========== PROCESSING DISPOSALS START ==========');
        Log::info('Total disposals to process: ' . count($disposals));
        Log::info("Livestock UUID: {$livestockUuid}");

        foreach ($disposals as $disposalData) {
            try {
                $syncAction = $disposalData['syncAction'] ?? 'create';
                $uuid = $disposalData['uuid'] ?? null;

                if (!$uuid) {
                    Log::warning('⚠️ Disposal entry without UUID skipped', ['disposal' => $disposalData]);
                    continue;
                }

                Log::info("Processing disposal: UUID={$uuid}, Action={$syncAction}");

                $disposalData['livestockUuid'] = $livestockUuid;
                $farmUuid = $disposalData['farmUuid'] ?? null;

                $status = isset($disposalData['status'])
                    ? strtolower((string) $disposalData['status'])
                    : 'completed';
                if (!in_array($status, ['pending', 'completed', 'failed'], true)) {
                    $status = 'completed';
                }

                $createdAt = isset($disposalData['createdAt'])
                    ? Carbon::parse($disposalData['createdAt'])->format('Y-m-d H:i:s')
                    : now();

                $updatedAt = isset($disposalData['updatedAt'])
                    ? Carbon::parse($disposalData['updatedAt'])->format('Y-m-d H:i:s')
                    : now();

                // Handle eventDate - if not provided, default to createdAt for backward compatibility
                $eventDate = isset($disposalData['eventDate'])
                    ? Carbon::parse($disposalData['eventDate'])->format('Y-m-d H:i:s')
                    : $createdAt;

                switch ($syncAction) {
                    case 'create':
                        $existing = Disposal::where('uuid', $uuid)->first();

                        if ($existing) {
                            $local = Carbon::parse($updatedAt);
                            $server = Carbon::parse($existing->updated_at);

                            if ($local->greaterThan($server)) {
                                $existing->update([
                                    'farmUuid' => $farmUuid,
                                    'livestockUuid' => $livestockUuid,
                                    'disposalTypeId' => $disposalData['disposalTypeId'] ?? null,
                                    'reasons' => $disposalData['reasons'] ?? $existing->reasons,
                                    'remarks' => $disposalData['remarks'] ?? $existing->remarks,
                                    'status' => $status,
                                    'eventDate' => $eventDate,
                                    'updated_at' => $updatedAt,
                                ]);

                                Log::info("✅ Disposal updated (local newer): UUID {$uuid}");

                                // Update livestock status to 'not-active' if disposal type exists
                                $this->updateLivestockStatusForDisposal($livestockUuid, $disposalData['disposalTypeId'] ?? null);
                            } else {
                                Log::info("⏭️ Disposal skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Disposal::create([
                                'uuid' => $uuid,
                                'eventDate' => $eventDate,
                                'farmUuid' => $farmUuid,
                                'livestockUuid' => $livestockUuid,
                                'disposalTypeId' => $disposalData['disposalTypeId'] ?? null,
                                'reasons' => $disposalData['reasons'] ?? '',
                                'remarks' => $disposalData['remarks'] ?? '',
                                'status' => $status,
                                'created_at' => $createdAt,
                                'updated_at' => $updatedAt,
                            ]);

                            Log::info("✅ Disposal created: UUID {$uuid}");

                            // Update livestock status to 'not-active' if disposal type exists
                            $this->updateLivestockStatusForDisposal($livestockUuid, $disposalData['disposalTypeId'] ?? null);
                        }

                        $syncedDisposals[] = ['uuid' => $uuid];
                        break;

                    case 'update':
                        $disposal = Disposal::where('uuid', $uuid)->first();

                        if ($disposal) {
                            $local = Carbon::parse($updatedAt);
                            $server = Carbon::parse($disposal->updated_at);

                            if ($local->greaterThan($server)) {
                                $disposal->update([
                                    'farmUuid' => $farmUuid,
                                    'disposalTypeId' => $disposalData['disposalTypeId'] ?? null,
                                    'reasons' => $disposalData['reasons'] ?? $disposal->reasons,
                                    'remarks' => $disposalData['remarks'] ?? $disposal->remarks,
                                    'status' => $status,
                                    'eventDate' => $eventDate,
                                    'updated_at' => $updatedAt,
                                ]);

                                Log::info("✅ Disposal updated: UUID {$uuid}");

                                // Update livestock status to 'not-active' if disposal type exists
                                $this->updateLivestockStatusForDisposal($livestockUuid, $disposalData['disposalTypeId'] ?? null);
                            } else {
                                Log::info("⏭️ Disposal update skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Log::warning("⚠️ Disposal not found for update: UUID {$uuid}");
                        }

                        $syncedDisposals[] = ['uuid' => $uuid];
                        break;

                    case 'deleted':
                        $disposal = Disposal::where('uuid', $uuid)->first();

                        if ($disposal) {
                            $disposal->delete();
                            Log::info("✅ Disposal deleted: UUID {$uuid}");
                        } else {
                            Log::info("⏭️ Disposal already deleted on server: UUID {$uuid}");
                        }

                        $syncedDisposals[] = ['uuid' => $uuid];
                        break;

                    default:
                        Log::warning("⚠️ Unknown sync action for disposal: {$syncAction}", ['uuid' => $uuid]);
                        break;
                }
            } catch (\Exception $e) {
                Log::error('❌ ERROR PROCESSING DISPOSAL', [
                    'uuid' => $uuid ?? 'unknown',
                    'syncAction' => $syncAction ?? 'unknown',
                    'error' => $e->getMessage(),
                    'payload' => $disposalData,
                ]);

                continue;
            }
        }

        Log::info('========== PROCESSING DISPOSALS END ==========');
        Log::info('Total disposals synced: ' . count($syncedDisposals));

        return $syncedDisposals;
    }

    /**
     * Update livestock status to 'notActive' when a disposal is created/updated.
     *
     * All disposal types (Dead, Slaughtered, Lost, Culled) indicate that the livestock
     * is no longer active in the farm, so the status should be updated to 'notActive'.
     *
     * @param string $livestockUuid
     * @param int|null $disposalTypeId
     * @return void
     */
    private function updateLivestockStatusForDisposal(string $livestockUuid, ?int $disposalTypeId): void
    {
        // Only update if disposal type exists (all disposal types mean livestock is no longer active)
        if ($disposalTypeId === null) {
            return;
        }

        try {
            $livestock = Livestock::where('uuid', $livestockUuid)->first();

            if (!$livestock) {
                Log::warning("⚠️ Livestock not found for disposal status update: UUID {$livestockUuid}");
                return;
            }

            // Check if livestock is already notActive (check both formats for backward compatibility)
            if ($livestock->status === 'notActive' || $livestock->status === 'not-active') {
                Log::debug("ℹ️ Livestock already notActive: UUID {$livestockUuid}");
                return;
            }

            // Get disposal type name for logging
            $disposalType = DisposalType::find($disposalTypeId);
            $disposalTypeName = $disposalType ? $disposalType->name : "Unknown (ID: {$disposalTypeId})";

            // Update livestock status to 'notActive' (matches frontend format)
            $livestock->update(['status' => 'notActive']);

            Log::info("✅ Livestock status updated to 'notActive' for disposal type '{$disposalTypeName}' (ID: {$disposalTypeId}): UUID {$livestockUuid}");
        } catch (\Exception $e) {
            Log::error("❌ Failed to update livestock status for disposal", [
                'livestockUuid' => $livestockUuid,
                'disposalTypeId' => $disposalTypeId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $disposals = Disposal::with(['livestock', 'farm', 'disposalType'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Disposals retrieved successfully',
            'data' => $disposals,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'nullable|string|unique:disposals,uuid',
            'farmUuid' => 'required|string|exists:farms,uuid',
            'livestockUuid' => 'required|string|exists:livestocks,uuid',
            'disposalTypeId' => 'nullable|integer|exists:disposal_types,id',
            'reasons' => 'nullable|string',
            'remarks' => 'nullable|string',
            'status' => 'nullable|string|in:completed,pending,failed',
            'eventDate' => 'nullable|date',
        ]);

        $data = $request->all();
        if (empty($data['uuid'])) {
            $data['uuid'] = (string) \Illuminate\Support\Str::uuid();
        }

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

        $disposal = Disposal::create($data);

        $disposal->load(['livestock', 'farm', 'disposalType']);

        return response()->json([
            'status' => true,
            'message' => 'Disposal created successfully',
            'data' => $disposal,
        ], 201);
    }

    public function adminShow(Disposal $disposal): JsonResponse
    {
        $disposal->load(['livestock', 'farm', 'disposalType']);

        return response()->json([
            'status' => true,
            'message' => 'Disposal retrieved successfully',
            'data' => $disposal,
        ], 200);
    }

    public function adminUpdate(Request $request, Disposal $disposal): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'sometimes|required|string|unique:disposals,uuid,' . $disposal->id,
            'farmUuid' => 'sometimes|required|string|exists:farms,uuid',
            'livestockUuid' => 'sometimes|required|string|exists:livestocks,uuid',
            'disposalTypeId' => 'sometimes|nullable|integer|exists:disposal_types,id',
            'reasons' => 'sometimes|nullable|string',
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

        $data = $request->except(['eventDate']);
        if ($request->has('eventDate')) {
            $data['eventDate'] = Carbon::parse($request->eventDate)->format('Y-m-d H:i:s');
        }

        $disposal->fill($data);
        $disposal->save();

        $disposal->load(['livestock', 'farm', 'disposalType']);

        return response()->json([
            'status' => true,
            'message' => 'Disposal updated successfully',
            'data' => $disposal,
        ], 200);
    }

    public function adminDestroy(Disposal $disposal): JsonResponse
    {
        $disposal->delete();

        return response()->json([
            'status' => true,
            'message' => 'Disposal deleted successfully',
        ], 200);
    }
}

