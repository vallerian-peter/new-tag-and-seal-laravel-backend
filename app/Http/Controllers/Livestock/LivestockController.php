<?php

namespace App\Http\Controllers\Livestock;

use App\Http\Controllers\Controller;
use App\Models\Livestock;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Log;
use PhpParser\Node\Expr\FuncCall;

class LivestockController extends Controller
{
    /**
     * Display a listing of all livestock.
     *
     * @return JsonResponse
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
                'data' => $livestock
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve livestock',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all livestock by farm IDs (for a specific farmer).
     * This is used when a farmer logs in to get all their livestock across all their farms.
     *
     * @param array $farmIds
     * @return JsonResponse
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
                'count' => $livestock->count()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve livestock',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified livestock.
     *
     * @param Livestock $livestock
     * @return JsonResponse
     */
    public function show(Livestock $livestock): JsonResponse
    {
        try {
            $livestock->load(['farm', 'livestockType', 'breed', 'species', 'livestockObtainedMethod', 'mother', 'father']);

            return response()->json([
                'status' => true,
                'message' => 'Livestock retrieved successfully',
                'data' => $livestock
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve livestock',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fetch livestock by farm UUIDs as array (for sync).
     *
     * @param array $farmUuids
     * @return array
     */
    public function fetchByFarmUuids(array $farmUuids): array
    {
        return Livestock::whereIn('farmUuid', $farmUuids)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($livestock) {
                return [
                    'id' => $livestock->id,
                    'farmUuid' => $livestock->farmUuid,  // Changed from farmId
                    'uuid' => $livestock->uuid,
                    'identificationNumber' => $livestock->identificationNumber,
                    'dummyTagId' => $livestock->dummyTagId,
                    'barcodeTagId' => $livestock->barcodeTagId,
                    'rfidTagId' => $livestock->rfidTagId,
                    'livestockTypeId' => $livestock->livestockTypeId,
                    'name' => $livestock->name,
                    'dateOfBirth' => $livestock->dateOfBirth?->toDateString(),
                    'motherUuid' => $livestock->motherUuid,  // Changed from motherId
                    'fatherUuid' => $livestock->fatherUuid,  // Changed from fatherId
                    'gender' => $livestock->gender,
                    'breedId' => $livestock->breedId,
                    'speciesId' => $livestock->speciesId,
                    'status' => $livestock->status,
                    'livestockObtainedMethodId' => $livestock->livestockObtainedMethodId,
                    'dateFirstEnteredToFarm' => $livestock->dateFirstEnteredToFarm?->toDateString(),
                    'weightAsOnRegistration' => $livestock->weightAsOnRegistration,
                    'primaryColor' => $livestock->primaryColor,
                    'secondaryColor' => $livestock->secondaryColor,
                    'createdAt' => $livestock->created_at?->toIso8601String(),
                    'updatedAt' => $livestock->updated_at?->toIso8601String(),
                ];
            })
            ->toArray();
    }

    public function handlePostLivestockAction(Request $request){
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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process livestock sync data from mobile app
     * Handles create, update, and delete operations with timestamp-based conflict resolution
     *
     * @param array $livestock Array of livestock data from mobile app
     * @param int $farmerId The authenticated farmer's ID
     * @return array Array of synced livestock UUIDs
     */
    public function processLivestock(array $livestock, int $farmerId): array
    {
        $syncedLivestock = [];

        Log::info("========== PROCESSING LIVESTOCK START ==========");
        Log::info("Total livestock to process: " . count($livestock));
        Log::info("Authenticated Farmer ID: {$farmerId}");

        foreach ($livestock as $livestockData) {
            try {
                $syncAction = $livestockData['syncAction'] ?? 'create';
                $uuid = $livestockData['uuid'] ?? null;

                Log::info("Processing livestock: UUID={$uuid}, Action={$syncAction}, Name={$livestockData['name']}");

                if (!$uuid) {
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

                // Convert date fields
                $dateOfBirth = isset($livestockData['dateOfBirth'])
                    ? \Carbon\Carbon::parse($livestockData['dateOfBirth'])->format('Y-m-d')
                    : null;
                $dateFirstEnteredToFarm = isset($livestockData['dateFirstEnteredToFarm'])
                    ? \Carbon\Carbon::parse($livestockData['dateFirstEnteredToFarm'])->format('Y-m-d')
                    : null;

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
                                    'identificationNumber' => $livestockData['identificationNumber'],
                                    'dummyTagId' => $livestockData['dummyTagId'],
                                    'barcodeTagId' => $livestockData['barcodeTagId'],
                                    'rfidTagId' => $livestockData['rfidTagId'],
                                    'livestockTypeId' => $livestockData['livestockTypeId'],
                                    'name' => $livestockData['name'],
                                    'dateOfBirth' => $dateOfBirth,
                                    'motherUuid' => $livestockData['motherUuid'] ?? null,
                                    'fatherUuid' => $livestockData['fatherUuid'] ?? null,
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
                                'identificationNumber' => $livestockData['identificationNumber'],
                                'dummyTagId' => $livestockData['dummyTagId'],
                                'barcodeTagId' => $livestockData['barcodeTagId'],
                                'rfidTagId' => $livestockData['rfidTagId'],
                                'livestockTypeId' => $livestockData['livestockTypeId'],
                                'name' => $livestockData['name'],
                                'dateOfBirth' => $dateOfBirth,
                                'motherUuid' => $livestockData['motherUuid'] ?? null,
                                'fatherUuid' => $livestockData['fatherUuid'] ?? null,
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
                                    'identificationNumber' => $livestockData['identificationNumber'],
                                    'dummyTagId' => $livestockData['dummyTagId'],
                                    'barcodeTagId' => $livestockData['barcodeTagId'],
                                    'rfidTagId' => $livestockData['rfidTagId'],
                                    'livestockTypeId' => $livestockData['livestockTypeId'],
                                    'name' => $livestockData['name'],
                                    'dateOfBirth' => $dateOfBirth,
                                    'motherUuid' => $livestockData['motherUuid'] ?? null,
                                    'fatherUuid' => $livestockData['fatherUuid'] ?? null,
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
                            $livestock->delete();
                            $syncedLivestock[] = ['uuid' => $uuid];
                            Log::info("✅ Livestock deleted: {$livestock->name} (UUID: {$uuid})");
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
                Log::error("❌ ERROR PROCESSING LIVESTOCK", [
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

        Log::info("========== PROCESSING LIVESTOCK END ==========");
        Log::info("Total livestock synced: " . count($syncedLivestock));

        return $syncedLivestock;
    }
}

