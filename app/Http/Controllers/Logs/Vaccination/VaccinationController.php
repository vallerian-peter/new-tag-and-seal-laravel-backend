<?php

namespace App\Http\Controllers\Logs\Vaccination;

use App\Http\Controllers\Controller;
use App\Models\Vaccination;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class VaccinationController extends Controller
{
    /**
     * Display a listing of vaccinations.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $vaccinations = Vaccination::with(['livestock', 'farm', 'vaccine', 'disease', 'vet', 'extensionOfficer'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Vaccination logs retrieved successfully',
                'data' => $vaccinations,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching vaccination logs: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve vaccination logs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch vaccinations for given farm and livestock UUIDs.
     */
    public function fetchVaccinationsWithUuid($farmUuids, $livestockUuids): array
    {
        if (empty($farmUuids) || empty($livestockUuids)) {
            \Log::info("VaccinationController: Empty farmUuids or livestockUuids - farmUuids: " . json_encode($farmUuids) . ", livestockUuids: " . json_encode($livestockUuids));
            return [];
        }

        \Log::info("VaccinationController: Fetching vaccinations - farmUuids: " . json_encode($farmUuids) . ", livestockUuids: " . json_encode($livestockUuids));

        $vaccinations = Vaccination::whereIn('farmUuid', (array) $farmUuids)
            ->whereIn('livestockUuid', (array) $livestockUuids)
            ->get();

        \Log::info("VaccinationController: Found " . $vaccinations->count() . " vaccination(s) in database");

        return $vaccinations->map(function (Vaccination $log) {
            return [
                'id' => $log->id,
                'uuid' => $log->uuid,
                'vaccinationNo' => $log->vaccinationNo,
                'farmUuid' => $log->farmUuid,
                'livestockUuid' => $log->livestockUuid,
                'vaccineUuid' => $log->vaccineUuid,
                'diseaseId' => $log->diseaseId,
                'vetId' => $log->vetId,
                'extensionOfficerId' => $log->extensionOfficerId,
                'status' => $log->status,
                'eventDate' => $log->eventDate ? Carbon::parse($log->eventDate)->toIso8601String() : $log->created_at?->toIso8601String(),
                'createdAt' => $log->created_at?->toIso8601String(),
                'updatedAt' => $log->updated_at?->toIso8601String(),
            ];
        })
            ->toArray();
    }

    /**
     * Process vaccination records coming from the mobile app.
     */
    public function processVaccinations(array $vaccinations, string $livestockUuid): array
    {
        $syncedVaccinations = [];

        Log::info('========== PROCESSING VACCINATIONS START ==========');
        Log::info('Total vaccinations to process: ' . count($vaccinations));
        Log::info("Livestock UUID: {$livestockUuid}");

        foreach ($vaccinations as $vaccinationData) {
            try {
                $syncAction = $vaccinationData['syncAction'] ?? 'create';
                $uuid = $vaccinationData['uuid'] ?? null;

                if (!$uuid) {
                    Log::warning('⚠️ Vaccination entry without UUID skipped', ['vaccination' => $vaccinationData]);
                    continue;
                }

                Log::info("Processing vaccination: UUID={$uuid}, Action={$syncAction}");

                $vaccinationData['livestockUuid'] = $livestockUuid;
                $farmUuid = $vaccinationData['farmUuid'] ?? null;

                $vaccinationNo = isset($vaccinationData['vaccinationNo'])
                    ? trim((string) $vaccinationData['vaccinationNo'])
                    : null;
                $vaccinationNo = $vaccinationNo === '' ? null : $vaccinationNo;

                $vetId = isset($vaccinationData['vetId'])
                    ? trim((string) $vaccinationData['vetId'])
                    : null;
                $vetId = $vetId === '' ? null : $vetId;

                $extensionOfficerId = isset($vaccinationData['extensionOfficerId'])
                    ? trim((string) $vaccinationData['extensionOfficerId'])
                    : null;
                $extensionOfficerId = $extensionOfficerId === '' ? null : $extensionOfficerId;

                $status = isset($vaccinationData['status'])
                    ? strtolower((string) $vaccinationData['status'])
                    : 'completed';
                if (!in_array($status, ['pending', 'completed', 'failed'], true)) {
                    $status = 'completed';
                }

                $createdAt = isset($vaccinationData['createdAt'])
                    ? Carbon::parse($vaccinationData['createdAt'])->format('Y-m-d H:i:s')
                    : now();

                $updatedAt = isset($vaccinationData['updatedAt'])
                    ? Carbon::parse($vaccinationData['updatedAt'])->format('Y-m-d H:i:s')
                    : now();

                // Handle eventDate - if not provided, default to createdAt for backward compatibility
                $eventDate = isset($vaccinationData['eventDate'])
                    ? Carbon::parse($vaccinationData['eventDate'])->format('Y-m-d H:i:s')
                    : $createdAt;

                // Handle vaccineUuid
                $vaccineUuid = $vaccinationData['vaccineUuid'] ?? null;

                // If vaccineUuid is null, log it but allow the vaccination to be created
                if ($vaccineUuid === null) {
                    Log::info("ℹ️ Vaccination will be created without vaccineUuid (vaccine may not be synced yet)");
                }

                // Handle diseaseId: negative values indicate locally created diseases not yet synced
                $diseaseId = isset($vaccinationData['diseaseId']) && $vaccinationData['diseaseId'] !== null
                    ? (int) $vaccinationData['diseaseId']
                    : null;
                if ($diseaseId !== null && $diseaseId < 1) {
                    Log::info("⚠️ Vaccination has negative/invalid diseaseId ({$diseaseId}) - setting to null (disease not yet synced)");
                    $diseaseId = null;
                }

                switch ($syncAction) {
                    case 'create':
                        $existing = Vaccination::where('uuid', $uuid)->first();

                        if ($existing) {
                            $local = Carbon::parse($updatedAt);
                            $server = Carbon::parse($existing->updated_at);

                            if ($local->greaterThan($server)) {
                                $existing->update([
                                    'vaccinationNo' => $vaccinationNo ?? $existing->vaccinationNo,
                                    'farmUuid' => $farmUuid,
                                    'livestockUuid' => $livestockUuid,
                                    'vaccineUuid' => $vaccineUuid,
                                    'diseaseId' => $diseaseId,
                                    'vetId' => $vetId,
                                    'extensionOfficerId' => $extensionOfficerId,
                                    'status' => $status,
                                    'eventDate' => $eventDate,
                                    'updated_at' => $updatedAt,
                                ]);

                                Log::info("✅ Vaccination updated (local newer): UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Vaccination skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Vaccination::create([
                                'uuid' => $uuid,
                                'eventDate' => $eventDate,
                                'vaccinationNo' => $vaccinationNo ?? $uuid,
                                'farmUuid' => $farmUuid,
                                'livestockUuid' => $livestockUuid,
                                'vaccineUuid' => $vaccineUuid,
                                'diseaseId' => $diseaseId,
                                'vetId' => $vetId,
                                'extensionOfficerId' => $extensionOfficerId,
                                'status' => $status,
                                'created_at' => $createdAt,
                                'updated_at' => $updatedAt,
                            ]);

                            Log::info("✅ Vaccination created: UUID {$uuid}");
                        }

                        $syncedVaccinations[] = ['uuid' => $uuid];
                        break;

                    case 'update':
                        $vaccination = Vaccination::where('uuid', $uuid)->first();

                        if ($vaccination) {
                            $local = Carbon::parse($updatedAt);
                            $server = Carbon::parse($vaccination->updated_at);

                            if ($local->greaterThan($server)) {
                                $vaccination->update([
                                    'vaccinationNo' => $vaccinationNo ?? $vaccination->vaccinationNo,
                                    'farmUuid' => $farmUuid,
                                    'vaccineUuid' => $vaccineUuid,
                                    'diseaseId' => $diseaseId,
                                    'vetId' => $vetId,
                                    'extensionOfficerId' => $extensionOfficerId,
                                    'status' => $status,
                                    'eventDate' => $eventDate,
                                    'updated_at' => $updatedAt,
                                ]);

                                Log::info("✅ Vaccination updated: UUID {$uuid}");
                            } else {
                                Log::info("⏭️ Vaccination update skipped (server newer): UUID {$uuid}");
                            }
                        } else {
                            Log::warning("⚠️ Vaccination not found for update: UUID {$uuid}");
                        }

                        $syncedVaccinations[] = ['uuid' => $uuid];
                        break;

                    case 'deleted':
                        $vaccination = Vaccination::where('uuid', $uuid)->first();

                        if ($vaccination) {
                            $vaccination->delete();
                            Log::info("✅ Vaccination deleted: UUID {$uuid}");
                        } else {
                            Log::info("⏭️ Vaccination already deleted on server: UUID {$uuid}");
                        }

                        $syncedVaccinations[] = ['uuid' => $uuid];
                        break;

                    default:
                        Log::warning("⚠️ Unknown sync action for vaccination: {$syncAction}", ['uuid' => $uuid]);
                        break;
                }
            } catch (\Exception $e) {
                Log::error('❌ ERROR PROCESSING VACCINATION', [
                    'uuid' => $uuid ?? 'unknown',
                    'syncAction' => $syncAction ?? 'unknown',
                    'error' => $e->getMessage(),
                    'payload' => $vaccinationData,
                ]);

                continue;
            }
        }

        Log::info('========== PROCESSING VACCINATIONS END ==========');
        Log::info('Total vaccinations synced: ' . count($syncedVaccinations));

        return $syncedVaccinations;
    }

    // ============================================================================
    // Admin CRUD Methods (SystemUser-only)
    // ============================================================================

    public function adminIndex(): JsonResponse
    {
        $vaccinations = Vaccination::with(['livestock', 'farm', 'vaccine', 'disease', 'vet', 'extensionOfficer'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Vaccinations retrieved successfully',
            'data' => $vaccinations,
        ], 200);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'nullable|string|unique:vaccinations,uuid',
            'vaccinationNo' => 'nullable|string|unique:vaccinations,vaccinationNo',
            'farmUuid' => 'required|string|exists:farms,uuid',
            'livestockUuid' => 'required|string|exists:livestocks,uuid',
            'vaccineUuid' => 'nullable|string',
            'vaccineId' => 'nullable|integer|exists:vaccines,id',
            'diseaseId' => 'nullable|integer|exists:diseases,id',
            'vetId' => 'nullable|string',
            'extensionOfficerId' => 'nullable|string',
            'status' => 'nullable|string|in:pending,completed,failed',
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
        if (empty($data['uuid'])) {
            $data['uuid'] = (string) \Illuminate\Support\Str::uuid();
        }
        if (empty($data['vaccinationNo'])) {
            $data['vaccinationNo'] = 'VAC-' . strtoupper(\Illuminate\Support\Str::random(6));
        }

        if ($request->has('eventDate')) {
            $data['eventDate'] = Carbon::parse($request->eventDate)->format('Y-m-d H:i:s');
        } else {
            // Default to now if not provided
            $data['eventDate'] = now()->format('Y-m-d H:i:s');
        }

        $vaccination = Vaccination::create($data);

        $vaccination->load(['livestock', 'farm', 'vaccine', 'disease', 'vet', 'extensionOfficer']);

        return response()->json([
            'status' => true,
            'message' => 'Vaccination created successfully',
            'data' => $vaccination,
        ], 201);
    }

    public function adminShow(Vaccination $vaccination): JsonResponse
    {
        $vaccination->load(['livestock', 'farm', 'vaccine', 'disease', 'vet', 'extensionOfficer']);

        return response()->json([
            'status' => true,
            'message' => 'Vaccination retrieved successfully',
            'data' => $vaccination,
        ], 200);
    }

    public function adminUpdate(Request $request, Vaccination $vaccination): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'sometimes|required|string|unique:vaccinations,uuid,' . $vaccination->id,
            'vaccinationNo' => 'sometimes|nullable|string|unique:vaccinations,vaccinationNo,' . $vaccination->id,
            'farmUuid' => 'sometimes|required|string|exists:farms,uuid',
            'livestockUuid' => 'sometimes|required|string|exists:livestocks,uuid',
            'vaccineUuid' => 'sometimes|nullable|string',
            'vaccineId' => 'sometimes|nullable|integer|exists:vaccines,id',
            'diseaseId' => 'sometimes|nullable|integer|exists:diseases,id',
            'vetId' => 'sometimes|nullable|string',
            'extensionOfficerId' => 'sometimes|nullable|string',
            'status' => 'sometimes|nullable|string|in:pending,completed,failed',
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

        $vaccination->fill($data);
        $vaccination->save();

        $vaccination->load(['livestock', 'farm', 'vaccine', 'disease', 'vet', 'extensionOfficer']);

        return response()->json([
            'status' => true,
            'message' => 'Vaccination updated successfully',
            'data' => $vaccination,
        ], 200);
    }

    public function adminDestroy(Vaccination $vaccination): JsonResponse
    {
        $vaccination->delete();

        return response()->json([
            'status' => true,
            'message' => 'Vaccination deleted successfully',
        ], 200);
    }
}

