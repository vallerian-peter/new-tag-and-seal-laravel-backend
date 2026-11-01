<?php

namespace App\Http\Controllers\Livestock;

use App\Http\Controllers\Controller;
use App\Models\Livestock;
use App\Models\User;
use App\Services\LivestockLifecycleService;
use App\Traits\ConvertsDateFormat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class LivestockController extends Controller
{
    use ConvertsDateFormat;

    public function __construct(
        private readonly LivestockLifecycleService $lifecycleService,
    ) {}

    /**
     * Display a listing of all livestock.
     */
    public function index(): JsonResponse
    {
        try {
            $livestock = Livestock::with(['farm', 'livestockType', 'breed', 'species', 'livestockObtainedMethod'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Livestock retrieved successfully',
                'data' => $livestock,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve livestock',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all livestock by farm IDs (for a specific farmer).
     * This is used when a farmer logs in to get all their livestock across all their farms.
     */
    public function getAllLivestockByFarmIds(array $farmIds): JsonResponse
    {
        try {
            $livestock = Livestock::whereIn('farmId', $farmIds)
                ->with(['farm', 'livestockType', 'breed', 'species', 'livestockObtainedMethod', 'mother', 'father'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Livestock retrieved successfully',
                'data' => $livestock,
                'count' => $livestock->count(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve livestock',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified livestock.
     */
    public function show(Livestock $livestock): JsonResponse
    {
        try {
            $livestock->load(['farm', 'livestockType', 'breed', 'species', 'livestockObtainedMethod', 'mother', 'father']);

            return response()->json([
                'status' => true,
                'message' => 'Livestock retrieved successfully',
                'data' => $livestock,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve livestock',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch livestock by farm UUIDs as array (for sync).
     */
    public function fetchByFarmUuids(array $farmUuids): array
    {
        return Livestock::whereIn('farmUuid', $farmUuids)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($livestock) {
                return [
                    'id' => $livestock->id,
                    'farmUuid' => $livestock->farmUuid ?? '',
                    'uuid' => $livestock->uuid ?? '',
                    'identificationNumber' => $livestock->identificationNumber ?? '',
                    'dummyTagId' => $livestock->dummyTagId ?? '',
                    'barcodeTagId' => $livestock->barcodeTagId ?? '',
                    'rfidTagId' => $livestock->rfidTagId ?? '',
                    'livestockTypeId' => $livestock->livestockTypeId ?? 0,
                    'name' => $livestock->name ?? '',
                    'dateOfBirth' => $livestock->dateOfBirth?->toDateString() ?? '',
                    'motherUuid' => $livestock->motherUuid ?? '',
                    'fatherUuid' => $livestock->fatherUuid ?? '',
                    'birthEventUuid' => $livestock->birthEventUuid ?? '',
                    'stageId' => $livestock->stageId ?? 0,
                    'isIdentified' => $livestock->isIdentified ?? false,
                    'gender' => $livestock->gender ?? '',
                    'breedId' => $livestock->breedId ?? 0,
                    'speciesId' => $livestock->speciesId ?? 0,
                    'status' => $livestock->status ?? 'active',
                    'livestockObtainedMethodId' => $livestock->livestockObtainedMethodId ?? 0,
                    'dateFirstEnteredToFarm' => $livestock->dateFirstEnteredToFarm?->toDateString() ?? '',
                    'weightAsOnRegistration' => $livestock->weightAsOnRegistration ?? '0',
                    'primaryColor' => $livestock->primaryColor,
                    'secondaryColor' => $livestock->secondaryColor,
                    'createdAt' => $livestock->created_at?->toIso8601String() ?? '',
                    'updatedAt' => $livestock->updated_at?->toIso8601String() ?? '',
                ];
            })
            ->toArray();
    }

    public function handlePostLivestockAction(Request $request)
    {
        try {
            // hendelt syncAction delete && update && create || fromTheServecreatedAt-wins-if-is-greate  & fromServerupdatedAt-wins-if-greater
            $data = $request->all();
            Livestock::create($data);

            return response()->json([
                'status' => true,
                'message' => 'Livestock created successfully',
                'timestamp' => now()->toIso8601String(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to handle post livestock action',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process livestock sync data from mobile app
     * Handles create, update, and delete operations with timestamp-based conflict resolution
     *
     * @param  array  $livestock  Array of livestock data from mobile app
     * @param  int  $farmerId  The authenticated farmer's ID
     * @return array Array of synced livestock UUIDs
     */
    public function processLivestock(array $livestock, int $farmerId, ?User $actor = null): array
    {
        $syncedLivestock = [];

        Log::info('========== PROCESSING LIVESTOCK START ==========');
        Log::info('Total livestock to process: '.count($livestock));
        Log::info("Authenticated Farmer ID: {$farmerId}");

        foreach ($livestock as $livestockData) {
            try {
                $syncAction = $livestockData['syncAction'] ?? 'create';
                $uuid = $livestockData['uuid'] ?? null;

                Log::info("Processing livestock: UUID={$uuid}, Action={$syncAction}, Name={$livestockData['name']}");

                if (! $uuid) {
                    Log::warning('⚠️ Livestock without UUID skipped', ['livestock' => $livestockData]);

                    continue;
                }

                // Convert data types from Flutter to MySQL format
                $createdAt = isset($livestockData['createdAt'])
                    ? \Carbon\Carbon::parse($livestockData['createdAt'])->format('Y-m-d H:i:s')
                    : now();
                $updatedAt = isset($livestockData['updatedAt'])
                    ? \Carbon\Carbon::parse($livestockData['updatedAt'])->format('Y-m-d H:i:s')
                    : now();

                // Convert date fields using trait method
                $dateOfBirth = $this->convertDateFormat($livestockData['dateOfBirth'] ?? null);
                $dateFirstEnteredToFarm = $this->convertDateFormat($livestockData['dateFirstEnteredToFarm'] ?? null);

                // Convert weight to string (database uses varchar)
                $weightAsOnRegistration = (string) ($livestockData['weightAsOnRegistration'] ?? '0');

                Log::info("Converted data types - Weight: {$weightAsOnRegistration}, DOB: {$dateOfBirth}");

                switch ($syncAction) {
                    case 'create':
                        // Create new livestock or update if exists (upsert)
                        $existingLivestock = Livestock::where('uuid', $uuid)->first();

                        if ($existingLivestock) {
                            // Livestock exists - check if local is newer
                            $localUpdatedAt = \Carbon\Carbon::parse($updatedAt);
                            $serverUpdatedAt = \Carbon\Carbon::parse($existingLivestock->updated_at);

                            if ($localUpdatedAt->greaterThan($serverUpdatedAt)) {
                                // Local is newer - update
                                $existingLivestock->update([
                                    'farmUuid' => $livestockData['farmUuid'],
                                    'identificationNumber' => $livestockData['identificationNumber'] ?? null,
                                    'dummyTagId' => $livestockData['dummyTagId'],
                                    'barcodeTagId' => $livestockData['barcodeTagId'],
                                    'rfidTagId' => $livestockData['rfidTagId'],
                                    'livestockTypeId' => $livestockData['livestockTypeId'],
                                    'name' => $livestockData['name'],
                                    'dateOfBirth' => $dateOfBirth,
                                    'motherUuid' => $livestockData['motherUuid'] ?? null,
                                    'fatherUuid' => $livestockData['fatherUuid'] ?? null,
                                    'birthEventUuid' => $livestockData['birthEventUuid'] ?? null,
                                    'stageId' => $livestockData['stageId'] ?? null,
                                    'isIdentified' => array_key_exists('isIdentified', $livestockData)
                                        ? (bool) $livestockData['isIdentified']
                                        : true,
                                    'gender' => $livestockData['gender'],
                                    'breedId' => $livestockData['breedId'],
                                    'speciesId' => $livestockData['speciesId'],
                                    'status' => $livestockData['status'] ?? 'active',
                                    'livestockObtainedMethodId' => $livestockData['livestockObtainedMethodId'],
                                    'dateFirstEnteredToFarm' => $dateFirstEnteredToFarm,
                                    'weightAsOnRegistration' => $weightAsOnRegistration,
                                    'primaryColor' => $livestockData['primaryColor'] ?? null,
                                    'secondaryColor' => $livestockData['secondaryColor'] ?? null,
                                    'updated_at' => $updatedAt,
                                ]);
                                Log::info("✅ Livestock updated (local newer): {$existingLivestock->name} (UUID: {$uuid})");
                            } else {
                                Log::info("⏭️ Livestock skipped (server newer): {$existingLivestock->name} (UUID: {$uuid})");
                            }
                        } else {
                            // Livestock doesn't exist - create new
                            Log::info("Creating new livestock: {$livestockData['name']} (farmUuid: {$livestockData['farmUuid']})");

                            $newLivestock = Livestock::create([
                                'farmUuid' => $livestockData['farmUuid'],
                                'uuid' => $uuid,
                                'identificationNumber' => $livestockData['identificationNumber'] ?? null,
                                'dummyTagId' => $livestockData['dummyTagId'],
                                'barcodeTagId' => $livestockData['barcodeTagId'],
                                'rfidTagId' => $livestockData['rfidTagId'],
                                'livestockTypeId' => $livestockData['livestockTypeId'],
                                'name' => $livestockData['name'],
                                'dateOfBirth' => $dateOfBirth,
                                'motherUuid' => $livestockData['motherUuid'] ?? null,
                                'fatherUuid' => $livestockData['fatherUuid'] ?? null,
                                'birthEventUuid' => $livestockData['birthEventUuid'] ?? null,
                                'stageId' => $livestockData['stageId'] ?? null,
                                'isIdentified' => array_key_exists('isIdentified', $livestockData)
                                    ? (bool) $livestockData['isIdentified']
                                    : true,
                                'gender' => $livestockData['gender'],
                                'breedId' => $livestockData['breedId'],
                                'speciesId' => $livestockData['speciesId'],
                                'status' => $livestockData['status'] ?? 'active',
                                'livestockObtainedMethodId' => $livestockData['livestockObtainedMethodId'],
                                'dateFirstEnteredToFarm' => $dateFirstEnteredToFarm,
                                'weightAsOnRegistration' => $weightAsOnRegistration,
                                'primaryColor' => $livestockData['primaryColor'] ?? null,
                                'secondaryColor' => $livestockData['secondaryColor'] ?? null,
                                'created_at' => $createdAt,
                                'updated_at' => $updatedAt,
                            ]);
                            Log::info("✅ Livestock created successfully: {$newLivestock->name} (ID: {$newLivestock->id}, UUID: {$uuid})");
                        }

                        $syncedLivestock[] = ['uuid' => $uuid];
                        break;

                    case 'update':
                        // Update existing livestock only if local is newer
                        $livestock = Livestock::where('uuid', $uuid)->first();

                        if ($livestock) {
                            // Compare timestamps
                            $localUpdatedAt = \Carbon\Carbon::parse($updatedAt);
                            $serverUpdatedAt = \Carbon\Carbon::parse($livestock->updated_at);

                            if ($localUpdatedAt->greaterThan($serverUpdatedAt)) {
                                // Local is newer - perform update
                                $livestock->update([
                                    'farmUuid' => $livestockData['farmUuid'],
                                    'identificationNumber' => $livestockData['identificationNumber'] ?? null,
                                    'dummyTagId' => $livestockData['dummyTagId'],
                                    'barcodeTagId' => $livestockData['barcodeTagId'],
                                    'rfidTagId' => $livestockData['rfidTagId'],
                                    'livestockTypeId' => $livestockData['livestockTypeId'],
                                    'name' => $livestockData['name'],
                                    'dateOfBirth' => $dateOfBirth,
                                    'motherUuid' => $livestockData['motherUuid'] ?? null,
                                    'fatherUuid' => $livestockData['fatherUuid'] ?? null,
                                    'birthEventUuid' => $livestockData['birthEventUuid'] ?? null,
                                    'stageId' => $livestockData['stageId'] ?? null,
                                    'isIdentified' => array_key_exists('isIdentified', $livestockData)
                                        ? (bool) $livestockData['isIdentified']
                                        : true,
                                    'gender' => $livestockData['gender'],
                                    'breedId' => $livestockData['breedId'],
                                    'speciesId' => $livestockData['speciesId'],
                                    'status' => $livestockData['status'] ?? 'active',
                                    'livestockObtainedMethodId' => $livestockData['livestockObtainedMethodId'],
                                    'dateFirstEnteredToFarm' => $dateFirstEnteredToFarm,
                                    'weightAsOnRegistration' => $weightAsOnRegistration,
                                    'primaryColor' => $livestockData['primaryColor'] ?? null,
                                    'secondaryColor' => $livestockData['secondaryColor'] ?? null,
                                    'updated_at' => $updatedAt,
                                ]);

                                $syncedLivestock[] = ['uuid' => $uuid];
                                Log::info("✅ Livestock updated (local newer): {$livestock->name} (UUID: {$uuid}) - Local: {$localUpdatedAt}, Server: {$serverUpdatedAt}");
                            } else {
                                Log::info("⏭️ Livestock update skipped (server is newer or same): {$livestock->name} (UUID: {$uuid}) - Local: {$localUpdatedAt}, Server: {$serverUpdatedAt}");
                                // Still add to synced list so mobile app doesn't keep trying to sync
                                $syncedLivestock[] = ['uuid' => $uuid];
                            }
                        } else {
                            Log::warning("⚠️ Livestock not found for update: UUID {$uuid}");
                        }
                        break;

                    case 'deleted':
                        // Delete livestock
                        $livestock = Livestock::where('uuid', $uuid)->first();

                        if ($livestock) {
                            $this->lifecycleService->archive(
                                $livestock,
                                $actor,
                                'Deleted through mobile synchronization.',
                                $this->requestAuditContext(request()),
                            );
                            $syncedLivestock[] = ['uuid' => $uuid];
                            Log::info("✅ Livestock archived: {$livestock->name} (UUID: {$uuid})");
                        } else {
                            // Livestock already deleted on server
                            $syncedLivestock[] = ['uuid' => $uuid];
                            Log::info("⏭️ Livestock already deleted on server: UUID {$uuid}");
                        }
                        break;

                    default:
                        Log::warning("⚠️ Unknown sync action for livestock: {$syncAction}", ['uuid' => $uuid]);
                        break;
                }

            } catch (\Exception $e) {
                Log::error('❌ ERROR PROCESSING LIVESTOCK', [
                    'uuid' => $uuid ?? 'unknown',
                    'livestockName' => $livestockData['name'] ?? 'unknown',
                    'farmUuid' => $livestockData['farmUuid'] ?? 'unknown',
                    'syncAction' => $syncAction ?? 'unknown',
                    'errorMessage' => $e->getMessage(),
                    'errorCode' => $e->getCode(),
                    'livestockData' => $livestockData,
                ]);

                // Continue processing other livestock even if one fails
                continue;
            }
        }

        Log::info('========== PROCESSING LIVESTOCK END ==========');
        Log::info('Total livestock synced: '.count($syncedLivestock));

        return $syncedLivestock;
    }

    private function requestAuditContext(Request $request): array
    {
        return [
            'request_uuid' => $request->header('X-Request-ID'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];
    }
}
