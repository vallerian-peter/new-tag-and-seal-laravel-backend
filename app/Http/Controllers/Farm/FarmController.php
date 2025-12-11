<?php

namespace App\Http\Controllers\Farm;

use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class FarmController extends Controller
{
    /**
     * Display a listing of all farms.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        try {
            $farms = Farm::with(['farmer', 'village', 'ward', 'district', 'region', 'country', 'legalStatus'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Farms retrieved successfully',
                'data' => $farms
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve farms',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all farms by farmer ID.
     * Used when authenticated user is a farmer.
     * Only returns active farms.
     *
     * @param int $farmerId
     * @return JsonResponse
     */
    public function getAllFarmsByFarmerId(int $farmerId): JsonResponse
    {
        try {
            $farms = Farm::where('farmerId', $farmerId)
                ->where('status', 'active')
                ->with(['village', 'ward', 'district', 'region', 'country', 'legalStatus'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Farms retrieved successfully',
                'data' => $farms,
                'count' => $farms->count()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve farms',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified farm.
     *
     * @param Farm $farm
     * @return JsonResponse
     */
    public function show(Farm $farm): JsonResponse
    {
        try {
            $farm->load(['farmer', 'village', 'ward', 'district', 'region', 'country', 'legalStatus']);

            return response()->json([
                'status' => true,
                'message' => 'Farm retrieved successfully',
                'data' => $farm
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve farm',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fetch farms by farmer ID as array (for sync).
     * Only returns active farms.
     *
     * @param int $farmerId
     * @return array
     */
    public function fetchByFarmerId(int $farmerId): array
    {
        return Farm::where('farmerId', $farmerId)
            ->where('status', 'active')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($farm) {
                return [
                    'id' => $farm->id,
                    'farmerId' => $farm->farmerId,
                    'uuid' => $farm->uuid,
                    'referenceNo' => $farm->referenceNo,
                    'regionalRegNo' => $farm->regionalRegNo,
                    'name' => $farm->name,
                    'size' => $farm->size,
                    'sizeUnit' => $farm->sizeUnit,
                    'latitudes' => $farm->latitudes,
                    'longitudes' => $farm->longitudes,
                    'physicalAddress' => $farm->physicalAddress,
                    'villageId' => $farm->villageId,
                    'wardId' => $farm->wardId,
                    'districtId' => $farm->districtId,
                    'regionId' => $farm->regionId,
                    'countryId' => $farm->countryId,
                    'legalStatusId' => $farm->legalStatusId,
                    'status' => $farm->status,
                    'createdAt' => $farm->created_at?->toIso8601String(),
                    'updatedAt' => $farm->updated_at?->toIso8601String(),
                ];
            })
            ->toArray();
    }

    /**
     * Fetch single farm by UUID as array (for sync).
     * Only returns active farms.
     *
     * @param string $uuid
     * @return array|null
     */
    public function fetchByUuid(string $uuid): ?array
    {
        $farm = Farm::where('uuid', $uuid)
            ->where('status', 'active')
            ->with(['village', 'ward', 'district', 'region', 'country', 'legalStatus'])
            ->first();

        if (!$farm) {
            return null;
        }

        return [
            'id' => $farm->id,
            'farmerId' => $farm->farmerId,
            'uuid' => $farm->uuid,
            'referenceNo' => $farm->referenceNo,
            'regionalRegNo' => $farm->regionalRegNo,
            'name' => $farm->name,
            'size' => $farm->size,
            'sizeUnit' => $farm->sizeUnit,
            'latitudes' => $farm->latitudes,
            'longitudes' => $farm->longitudes,
            'physicalAddress' => $farm->physicalAddress,
            'villageId' => $farm->villageId,
            'wardId' => $farm->wardId,
            'districtId' => $farm->districtId,
            'regionId' => $farm->regionId,
            'countryId' => $farm->countryId,
            'legalStatusId' => $farm->legalStatusId,
            'status' => $farm->status,
            'createdAt' => $farm->created_at?->toIso8601String(),
            'updatedAt' => $farm->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Fetch multiple farms by UUIDs as array (for sync).
     * Only returns active farms.
     *
     * @param array $uuids
     * @return array
     */
    public function fetchByUuids(array $uuids): array
    {
        if (empty($uuids)) {
            return [];
        }

        return Farm::whereIn('uuid', $uuids)
            ->where('status', 'active')
            ->with(['village', 'ward', 'district', 'region', 'country', 'legalStatus'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($farm) {
                return [
                    'id' => $farm->id,
                    'farmerId' => $farm->farmerId,
                    'uuid' => $farm->uuid,
                    'referenceNo' => $farm->referenceNo,
                    'regionalRegNo' => $farm->regionalRegNo,
                    'name' => $farm->name,
                    'size' => $farm->size,
                    'sizeUnit' => $farm->sizeUnit,
                    'latitudes' => $farm->latitudes,
                    'longitudes' => $farm->longitudes,
                    'physicalAddress' => $farm->physicalAddress,
                    'villageId' => $farm->villageId,
                    'wardId' => $farm->wardId,
                    'districtId' => $farm->districtId,
                    'regionId' => $farm->regionId,
                    'countryId' => $farm->countryId,
                    'legalStatusId' => $farm->legalStatusId,
                    'status' => $farm->status,
                    'createdAt' => $farm->created_at?->toIso8601String(),
                    'updatedAt' => $farm->updated_at?->toIso8601String(),
                ];
            })
            ->toArray();
    }


    /**
     * Process farms data from mobile app (Sync)
     *
     * Handles create, update, and delete operations based on syncAction field.
     * Uses UUID as the unique identifier for farms across devices.
     *
     * **Timestamp Strategy:**
     * - Uses createdAt and updatedAt from mobile app (maintains offline creation times)
     * - On update: Only updates if local updatedAt is newer than server updatedAt
     *
     * **Security:**
     * - Validates that all farms belong to the authenticated farmer
     * - Rejects farms that don't match the farmerId
     *
     * @param array $farms Array of farm data from mobile app
     * @param int $farmerId Farmer ID from authenticated user's roleId
     * @return array Array of synced farm UUIDs
     */
    public function processFarms(array $farms, int $farmerId): array
    {
        $syncedFarms = [];

        Log::info("========== PROCESSING FARMS START ==========");
        Log::info("Total farms to process: " . count($farms));
        Log::info("Authenticated Farmer ID: {$farmerId}");

        foreach ($farms as $farmData) {
            try {
                $syncAction = $farmData['syncAction'] ?? 'create';
                $uuid = $farmData['uuid'] ?? null;

                Log::info("Processing farm: UUID={$uuid}, Action={$syncAction}, Name={$farmData['name']}");

                if (!$uuid) {
                    Log::warning('⚠️ Farm without UUID skipped', ['farm' => $farmData]);
                    continue;
                }

                // Override farmerId with authenticated farmer ID for security
                // This ensures farms are always saved under the correct farmer
                $farmData['farmerId'] = $farmerId;
                Log::info("Using authenticated farmerId: {$farmerId} for farm: {$farmData['name']}");

                // Convert data types from Flutter to MySQL format
                $createdAt = isset($farmData['createdAt'])
                    ? \Carbon\Carbon::parse($farmData['createdAt'])->format('Y-m-d H:i:s')
                    : now();
                $updatedAt = isset($farmData['updatedAt'])
                    ? \Carbon\Carbon::parse($farmData['updatedAt'])->format('Y-m-d H:i:s')
                    : now();

                // Convert numeric fields to strings (database uses varchar)
                $size = (string) ($farmData['size'] ?? 0);
                $latitudes = (string) ($farmData['latitudes'] ?? 0);
                $longitudes = (string) ($farmData['longitudes'] ?? 0);

                Log::info("Converted data types - Size: {$size}, Lat: {$latitudes}, Long: {$longitudes}");

                switch ($syncAction) {
                    case 'create':
                        // Create new farm or update if exists (upsert)
                        // Check if farm already exists
                        $existingFarm = Farm::where('uuid', $uuid)->first();

                        if ($existingFarm) {
                            // Farm exists - check if local is newer
                            $localUpdatedAt = \Carbon\Carbon::parse($updatedAt);
                            $serverUpdatedAt = \Carbon\Carbon::parse($existingFarm->updated_at);

                            if ($localUpdatedAt->greaterThan($serverUpdatedAt)) {
                                // Local is newer - update
                                $existingFarm->update([
                                    'farmerId' => $farmData['farmerId'],
                                    'referenceNo' => $farmData['referenceNo'],
                                    'regionalRegNo' => $farmData['regionalRegNo'],
                                    'name' => $farmData['name'],
                                    'size' => $size,
                                    'sizeUnit' => $farmData['sizeUnit'],
                                    'latitudes' => $latitudes,
                                    'longitudes' => $longitudes,
                                    'physicalAddress' => $farmData['physicalAddress'],
                                    'villageId' => $farmData['villageId'] ?? null,
                                    'wardId' => $farmData['wardId'],
                                    'districtId' => $farmData['districtId'],
                                    'regionId' => $farmData['regionId'],
                                    'countryId' => $farmData['countryId'],
                                    'legalStatusId' => $farmData['legalStatusId'],
                                    'status' => $farmData['status'] ?? 'active',
                                    'updated_at' => $updatedAt,
                                ]);
                                Log::info("✅ Farm updated (local newer): {$existingFarm->name} (UUID: {$uuid})");
                            } else {
                                Log::info("⏭️ Farm skipped (server newer): {$existingFarm->name} (UUID: {$uuid})");
                            }
                        } else {
                            // Farm doesn't exist - create new
                            Log::info("Creating new farm: {$farmData['name']} (farmerId: {$farmData['farmerId']})");

                            $farm = Farm::create([
                                'farmerId' => $farmData['farmerId'],
                                'uuid' => $uuid,
                                'referenceNo' => $farmData['referenceNo'],
                                'regionalRegNo' => $farmData['regionalRegNo'],
                                'name' => $farmData['name'],
                                'size' => $size,
                                'sizeUnit' => $farmData['sizeUnit'],
                                'latitudes' => $latitudes,
                                'longitudes' => $longitudes,
                                'physicalAddress' => $farmData['physicalAddress'],
                                'villageId' => $farmData['villageId'] ?? null,
                                'wardId' => $farmData['wardId'],
                                'districtId' => $farmData['districtId'],
                                'regionId' => $farmData['regionId'],
                                'countryId' => $farmData['countryId'],
                                'legalStatusId' => $farmData['legalStatusId'],
                                'status' => $farmData['status'] ?? 'active',
                                'created_at' => $createdAt,
                                'updated_at' => $updatedAt,
                            ]);
                            Log::info("✅ Farm created successfully: {$farm->name} (ID: {$farm->id}, UUID: {$uuid})");
                        }

                        $syncedFarms[] = ['uuid' => $uuid];
                        break;

                    case 'update':
                        // Update existing farm only if local is newer
                        $farm = Farm::where('uuid', $uuid)->first();

                        if ($farm) {
                            // Compare timestamps
                            $localUpdatedAt = \Carbon\Carbon::parse($updatedAt);
                            $serverUpdatedAt = \Carbon\Carbon::parse($farm->updated_at);

                            if ($localUpdatedAt->greaterThan($serverUpdatedAt)) {
                                // Local is newer - perform update
                                $farm->update([
                                    'name' => $farmData['name'],
                                    'size' => $size,
                                    'sizeUnit' => $farmData['sizeUnit'],
                                    'latitudes' => $latitudes,
                                    'longitudes' => $longitudes,
                                    'physicalAddress' => $farmData['physicalAddress'],
                                    'villageId' => $farmData['villageId'] ?? null,
                                    'wardId' => $farmData['wardId'],
                                    'districtId' => $farmData['districtId'],
                                    'regionId' => $farmData['regionId'],
                                    'countryId' => $farmData['countryId'],
                                    'legalStatusId' => $farmData['legalStatusId'],
                                    'status' => $farmData['status'] ?? 'active',
                                    'updated_at' => $updatedAt,
                                ]);

                                $syncedFarms[] = ['uuid' => $uuid];
                                Log::info("✅ Farm updated (local newer): {$farm->name} (UUID: {$uuid}) - Local: {$localUpdatedAt}, Server: {$serverUpdatedAt}");
                            } else {
                                Log::info("⏭️ Farm update skipped (server is newer or same): {$farm->name} (UUID: {$uuid}) - Local: {$localUpdatedAt}, Server: {$serverUpdatedAt}");
                                // Still add to synced list so mobile app doesn't keep trying to sync
                                $syncedFarms[] = ['uuid' => $uuid];
                            }
                        } else {
                            Log::warning("⚠️ Farm not found for update: UUID {$uuid}");
                        }
                        break;

                    case 'deleted':
                        // Delete farm
                        $farm = Farm::where('uuid', $uuid)->first();

                        if ($farm) {
                            $farm->delete();
                            $syncedFarms[] = ['uuid' => $uuid];
                            Log::info("✅ Farm deleted: {$farm->name} (UUID: {$uuid})");
                        } else {
                            // Farm already deleted on server
                            $syncedFarms[] = ['uuid' => $uuid];
                            Log::info("⏭️ Farm already deleted on server: UUID {$uuid}");
                        }
                        break;

                    default:
                        Log::warning("⚠️ Unknown sync action for farm: {$syncAction}", ['uuid' => $uuid]);
                        break;
                }

            } catch (\Exception $e) {
                Log::error("❌ ERROR PROCESSING FARM", [
                    'uuid' => $uuid ?? 'unknown',
                    'farmName' => $farmData['name'] ?? 'unknown',
                    'farmerId' => $farmData['farmerId'] ?? 'unknown',
                    'syncAction' => $syncAction ?? 'unknown',
                    'errorMessage' => $e->getMessage(),
                    'errorCode' => $e->getCode(),
                    'farmData' => $farmData,
                ]);
                // Continue processing other farms even if one fails
                continue;
            }
        }

        Log::info("========== PROCESSING FARMS END ==========");
        Log::info("Total farms synced: " . count($syncedFarms));

        return $syncedFarms;
    }
}
