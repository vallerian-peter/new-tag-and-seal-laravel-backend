<?php

namespace App\Http\Controllers\Logs\Deworming;

use Carbon\Carbon;
use App\Models\Deworming;
use App\Traits\ConvertsDateFormat;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;

class DewormingController extends Controller
{
    use ConvertsDateFormat;
    /**
     * Display a listing of deworming logs.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $dewormings = Deworming::with(['livestock', 'farm', 'administrationRoute', 'medicine'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Deworming logs retrieved successfully',
                'data' => $dewormings,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching deworming logs: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve deworming logs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch deworming logs for given farm and livestock UUIDs.
     */
    public function fetchDewormingsWithUuid($farmUuids, $livestockUuids): array
    {
        if (empty($farmUuids) || empty($livestockUuids)) {
            return [];
        }

        return Deworming::whereIn('farmUuid', (array) $farmUuids)
            ->whereIn('livestockUuid', (array) $livestockUuids)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'uuid' => $log->uuid,
                    'farmUuid' => $log->farmUuid,
                    'livestockUuid' => $log->livestockUuid,
                    'administrationRouteId' => $log->administrationRouteId,
                    'medicineId' => $log->medicineId,
                    'vetId' => $log->vetId,
                    'extensionOfficerId' => $log->extensionOfficerId,
                    'quantity' => $log->quantity,
                    'dose' => $log->dose,
                    'nextAdministrationDate' => $log->nextAdministrationDate?->toDateString(),
                    'eventDate' => $log->eventDate ? Carbon::parse($log->eventDate)->toIso8601String() : $log->created_at?->toIso8601String(),
                    'createdAt' => $log->created_at?->toIso8601String(),
                    'updatedAt' => $log->updated_at?->toIso8601String(),
                ];
            })
            ->toArray();
    }

    /**
     * Process deworming records coming from the mobile app.
     */
    public function processDewormings(array $dewormings, string $livestockUuid): array
    {
        $syncedDewormings = [];

        Log::info('========== PROCESSING DEWORMINGS START ==========');
        Log::info('Total dewormings to process: ' . count($dewormings));
        Log::info("Livestock UUID: {$livestockUuid}");

        foreach ($dewormings as $dewormingData) {
            try {
                $syncAction = $dewormingData['syncAction'] ?? 'create';
                $uuid = $dewormingData['uuid'] ?? null;

                if (!$uuid) {
                    Log::warning('⚠️ Deworming entry without UUID skipped', ['deworming' => $dewormingData]);
                    continue;
                }

                Log::info("Processing deworming: UUID={$uuid}, Action={$syncAction}");

                $dewormingData['livestockUuid'] = $livestockUuid;
                $farmUuid = $dewormingData['farmUuid'] ?? null;

                $vetId = isset($dewormingData['vetId'])
                    ? trim((string) $dewormingData['vetId'])
                    : null;
                $vetId = $vetId !== '' ? $vetId : null;

                $extensionOfficerId = isset($dewormingData['extensionOfficerId'])
                    ? trim((string) $dewormingData['extensionOfficerId'])
                    : null;
                $extensionOfficerId = $extensionOfficerId !== '' ? $extensionOfficerId : null;

                $nextAdministrationDate = $this->convertDateFormat($dewormingData['nextAdministrationDate'] ?? null);

                $createdAt = isset($dewormingData['createdAt'])
                    ? Carbon::parse($dewormingData['createdAt'])->format('Y-m-d H:i:s')
                    : now();

                $updatedAt = isset($dewormingData['updatedAt'])
                    ? Carbon::parse($dewormingData['updatedAt'])->format('Y-m-d H:i:s')
                    : now();

                // Handle eventDate - if not provided, default to createdAt for backward compatibility
                $eventDate = isset($dewormingData['eventDate'])
                    ? Carbon::parse($dewormingData['eventDate'])->format('Y-m-d H:i:s')
                    : $createdAt;

                switch ($syncAction) {
                    case 'create':
                        $existing = Deworming::where('uuid', $uuid)->first();

                        if ($existing) {
                            $local = Carbon::parse($updatedAt);
                            $server = Carbon::parse($existing->updated_at);

                            if ($local->greaterThan($server)) {
                                $existing->update([
                                    'farmUuid' => $farmUuid,
                                    'livestockUuid' => $livestockUuid,
                                    'administrationRouteId' => $dewormingData['administrationRouteId'] ?? null,
                                    'medicineId' => $dewormingData['medicineId'] ?? null,
                                    'vetId' => $vetId,
                                    'extensionOfficerId' => $extensionOfficerId,
                                    'quantity' => $dewormingData['quantity'] ?? null,
                                    'dose' => $dewormingData['dose'] ?? null,
                                    'nextAdministrationDate' => $nextAdministrationDate,
                                    'eventDate' => $eventDate,
                                    'updated_at' => $updatedAt,
                                ]);

                                Log::info("✅ Deworming updated (local newer): UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Deworming skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Deworming::create([
                                'uuid' => $uuid,
                                'eventDate' => $eventDate,
                                'farmUuid' => $farmUuid,
                                'livestockUuid' => $livestockUuid,
                                'administrationRouteId' => $dewormingData['administrationRouteId'] ?? null,
                                'medicineId' => $dewormingData['medicineId'] ?? null,
                                'vetId' => $vetId,
                                'extensionOfficerId' => $extensionOfficerId,
                                'quantity' => $dewormingData['quantity'] ?? null,
                                'dose' => $dewormingData['dose'] ?? null,
                                'nextAdministrationDate' => $nextAdministrationDate,
                                'created_at' => $createdAt,
                                'updated_at' => $updatedAt,
                            ]);

                            Log::info("✅ Deworming created: UUID {$uuid}");
                        }

                        $syncedDewormings[] = ['uuid' => $uuid];
                        break;

                    case 'update':
                        $deworming = Deworming::where('uuid', $uuid)->first();

                        if ($deworming) {
                            $local = Carbon::parse($updatedAt);
                            $server = Carbon::parse($deworming->updated_at);

                            if ($local->greaterThan($server)) {
                                $deworming->update([
                                    'farmUuid' => $farmUuid,
                                    'administrationRouteId' => $dewormingData['administrationRouteId'] ?? null,
                                    'medicineId' => $dewormingData['medicineId'] ?? null,
                                'vetId' => $vetId,
                                'extensionOfficerId' => $extensionOfficerId,
                                    'quantity' => $dewormingData['quantity'] ?? null,
                                    'dose' => $dewormingData['dose'] ?? null,
                                    'nextAdministrationDate' => $nextAdministrationDate,
                                    'eventDate' => $eventDate,
                                    'updated_at' => $updatedAt,
                                ]);

                                Log::info("✅ Deworming updated: UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Deworming update skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Log::warning("⚠️ Deworming not found for update: UUID {$uuid}");
                        }

                        $syncedDewormings[] = ['uuid' => $uuid];
                        break;

                    case 'deleted':
                        $deworming = Deworming::where('uuid', $uuid)->first();

                        if ($deworming) {
                            $deworming->delete();
                            Log::info("✅ Deworming deleted: UUID {$uuid}");
                        } else {
                            Log::info("⏭️ Deworming already deleted on server: UUID {$uuid}");
                        }

                        $syncedDewormings[] = ['uuid' => $uuid];
                        break;

                    default:
                        Log::warning("⚠️ Unknown sync action for deworming: {$syncAction}", ['uuid' => $uuid]);
                        break;
                }
            } catch (\Exception $e) {
                Log::error('❌ ERROR PROCESSING DEWORMING', [
                    'uuid' => $uuid ?? 'unknown',
                    'syncAction' => $syncAction ?? 'unknown',
                    'error' => $e->getMessage(),
                    'payload' => $dewormingData,
                ]);

                continue;
            }
        }

        Log::info('========== PROCESSING DEWORMINGS END ==========');
        Log::info('Total dewormings synced: ' . count($syncedDewormings));

        return $syncedDewormings;
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $dewormings = Deworming::with(['livestock', 'farm', 'administrationRoute', 'medicine', 'vet', 'extensionOfficer'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Dewormings retrieved successfully',
            'data' => $dewormings,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'required|string|unique:dewormings,uuid',
            'farmUuid' => 'required|string|exists:farms,uuid',
            'livestockUuid' => 'required|string|exists:livestocks,uuid',
            'administrationRouteId' => 'nullable|integer|exists:administration_routes,id',
            'medicineId' => 'nullable|integer|exists:medicines,id',
            'vetId' => 'nullable|string',
            'extensionOfficerId' => 'nullable|string',
            'quantity' => 'nullable|string|max:255',
            'dose' => 'nullable|string|max:255',
            'nextAdministrationDate' => 'nullable|date',
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
        if ($request->has('nextAdministrationDate')) {
            $data['nextAdministrationDate'] = $this->convertDateFormat($request->nextAdministrationDate);
        }
        if ($request->has('eventDate')) {
            $data['eventDate'] = Carbon::parse($request->eventDate)->format('Y-m-d H:i:s');
        } else {
            // Default to now if not provided
            $data['eventDate'] = now()->format('Y-m-d H:i:s');
        }

        $deworming = Deworming::create($data);

        $deworming->load(['livestock', 'farm', 'administrationRoute', 'medicine', 'vet', 'extensionOfficer']);

        return response()->json([
            'status' => true,
            'message' => 'Deworming created successfully',
            'data' => $deworming,
        ], 201);
    }

    public function adminShow(Deworming $deworming): JsonResponse
    {
        $deworming->load(['livestock', 'farm', 'administrationRoute', 'medicine', 'vet', 'extensionOfficer']);

        return response()->json([
            'status' => true,
            'message' => 'Deworming retrieved successfully',
            'data' => $deworming,
        ], 200);
    }

    public function adminUpdate(Request $request, Deworming $deworming): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'sometimes|required|string|unique:dewormings,uuid,' . $deworming->id,
            'farmUuid' => 'sometimes|required|string|exists:farms,uuid',
            'livestockUuid' => 'sometimes|required|string|exists:livestocks,uuid',
            'administrationRouteId' => 'sometimes|nullable|integer|exists:administration_routes,id',
            'medicineId' => 'sometimes|nullable|integer|exists:medicines,id',
            'vetId' => 'sometimes|nullable|string',
            'extensionOfficerId' => 'sometimes|nullable|string',
            'quantity' => 'sometimes|nullable|string|max:255',
            'dose' => 'sometimes|nullable|string|max:255',
            'nextAdministrationDate' => 'sometimes|nullable|date',
            'eventDate' => 'sometimes|nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->except(['nextAdministrationDate', 'eventDate']);
        
        if ($request->has('nextAdministrationDate')) {
            $data['nextAdministrationDate'] = $this->convertDateFormat($request->nextAdministrationDate);
        }
        if ($request->has('eventDate')) {
            $data['eventDate'] = Carbon::parse($request->eventDate)->format('Y-m-d H:i:s');
        }

        $deworming->fill($data);
        $deworming->save();

        $deworming->load(['livestock', 'farm', 'administrationRoute', 'medicine', 'vet', 'extensionOfficer']);

        return response()->json([
            'status' => true,
            'message' => 'Deworming updated successfully',
            'data' => $deworming,
        ], 200);
    }

    public function adminDestroy(Deworming $deworming): JsonResponse
    {
        $deworming->delete();

        return response()->json([
            'status' => true,
            'message' => 'Deworming deleted successfully',
        ], 200);
    }
}


