<?php

namespace App\Http\Controllers\Sync;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Farm\FarmController;
use App\Http\Controllers\Breed\BreedController;
use App\Http\Controllers\Specie\SpecieController;
use App\Http\Controllers\Location\LocationController;
use App\Http\Controllers\Livestock\LivestockController;
use App\Http\Controllers\LegalStatus\LegalStatusController;
use App\Http\Controllers\SchoolLevel\SchoolLevelController;
use App\Http\Controllers\LivestockType\LivestockTypeController;
use App\Http\Controllers\IdentityCardType\IdentityCardTypeController;
use App\Http\Controllers\LivestockObtainedMethod\LivestockObtainedMethodController;

class SyncController extends Controller
{
    protected $locationController;
    protected $identityCardTypeController;
    protected $schoolLevelController;
    protected $legalStatusController;
    protected $breedController;
    protected $specieController;
    protected $livestockTypeController;
    protected $livestockObtainedMethodController;
    protected $farmController;
    protected $livestockController;

    public function __construct(
        LocationController $locationController,
        IdentityCardTypeController $identityCardTypeController,
        SchoolLevelController $schoolLevelController,
        LegalStatusController $legalStatusController,
        BreedController $breedController,
        SpecieController $specieController,
        LivestockTypeController $livestockTypeController,
        LivestockObtainedMethodController $livestockObtainedMethodController,
        FarmController $farmController,
        LivestockController $livestockController
    ) {
        $this->locationController = $locationController;
        $this->identityCardTypeController = $identityCardTypeController;
        $this->schoolLevelController = $schoolLevelController;
        $this->legalStatusController = $legalStatusController;
        $this->breedController = $breedController;
        $this->specieController = $specieController;
        $this->livestockTypeController = $livestockTypeController;
        $this->livestockObtainedMethodController = $livestockObtainedMethodController;
        $this->farmController = $farmController;
        $this->livestockController = $livestockController;
    }

    /**
     * Initial sync for registration - Returns only reference data needed for registration.
     *
     * @return JsonResponse
     */
    public function initialRegisterSync(): JsonResponse
    {
        try {
            $data = [
                'locations' => [
                    'countries' => $this->locationController->fetchCountries(),
                    'regions' => $this->locationController->fetchRegions(),
                    'districts' => $this->locationController->fetchDistricts(),
                    'wards' => $this->locationController->fetchWards(),
                    'villages' => $this->locationController->fetchVillages(),
                    'streets' => $this->locationController->fetchStreets(),
                    'divisions' => $this->locationController->fetchDivisions(),
                ],
                'identityCardTypes' => $this->identityCardTypeController->fetchAll(),
                'schoolLevels' => $this->schoolLevelController->fetchAll(),
            ];

            return response()->json([
                'status' => true,
                'message' => 'Registration data retrieved successfully',
                'data' => $data,
                'timestamp' => now()->toIso8601String(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve registration data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Splash Sync - Complete data sync for authenticated users on app startup.
     * Returns ALL data based on the authenticated user's role and permissions.
     *
     * Flow:
     * 1. Get authenticated user from token
     * 2. Determine user role (Farmer, Extension Officer, Vet, etc.)
     * 3. Return reference data (locations, breeds, species, etc.) - EVERYONE gets this
     * 4. Return user-specific data (farms, livestock) based on role
     *
     * @param Request $request
     * @param int $userId
     * @return JsonResponse
     */
    public function splashSync(Request $request, int $userId): JsonResponse
    {
        try {
            // Get authenticated user from request
            $authenticatedUser = $request->user();

            // Validate that the authenticated user matches the requested userId
            if ($authenticatedUser->id !== $userId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized access to user data',
                ], 403);
            }

            $user = User::find($userId);

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found',
                ], 404);
            }

            // Base data structure - everyone gets reference data
            $data = [
                // 1. Location data (for dropdowns, forms, etc.)
                'locations' => [
                    'countries' => $this->locationController->fetchCountries(),
                    'regions' => $this->locationController->fetchRegions(),
                    'districts' => $this->locationController->fetchDistricts(),
                    'wards' => $this->locationController->fetchWards(),
                    'villages' => $this->locationController->fetchVillages(),
                    'streets' => $this->locationController->fetchStreets(),
                    'divisions' => $this->locationController->fetchDivisions(),
                ],

                // 2. Reference data (for forms, validations, etc.)
                'referenceData' => [
                    'identityCardTypes' => $this->identityCardTypeController->fetchAll(),
                    'schoolLevels' => $this->schoolLevelController->fetchAll(),
                    'legalStatuses' => $this->legalStatusController->fetchAll(),
                ],

                // 3. Livestock reference data (species, types, breeds, methods)
                'livestockReferenceData' => [
                    'species' => $this->specieController->fetchAll(),
                    'livestockTypes' => $this->livestockTypeController->fetchAll(),
                    'breeds' => $this->breedController->fetchAll(),
                    'livestockObtainedMethods' => $this->livestockObtainedMethodController->fetchAll(),
                ],

                // 4. User-specific data (populated based on role)
                'userSpecificData' => [],

                // 5. User info
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                    'role' => $user->role,
                    'roleId' => $user->roleId,
                    'status' => $user->status,
                ],
            ];

            // Get role-specific data
            switch ($user->role) {
                case 'Farmer':
                case 'farmer':
                    $data['userSpecificData'] = $this->getFarmerData($user->roleId);
                    break;

                case 'Extension Officer':
                case 'extension officer':
                case 'Vet':
                case 'vet':
                case 'Farm Invited User':
                    $data['userSpecificData'] = $this->getFieldWorkerData($user->roleId);
                    break;

                case 'System User':
                case 'system user':
                    $data['userSpecificData'] = $this->getSystemUserData();
                    break;

                default:
                    $data['userSpecificData'] = [];
                    break;
            }

            return response()->json([
                'status' => true,
                'message' => 'Splash sync completed successfully',
                'data' => $data,
                'timestamp' => now()->toIso8601String(),
            ], 200);

        } catch (\Exception $e) {
            print_r($e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to complete splash sync',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    /**
     * Get farmer-specific data (farms and livestock).
     *
     * @param int $farmerId
     * @return array
     */
    private function getFarmerData(int $farmerId): array
    {
        // Get all farms for this farmer
        $farms = $this->farmController->fetchByFarmerId($farmerId);

        // Extract farm UUIDs (not IDs) for livestock lookup
        $farmUuids = array_column($farms, 'uuid');

        // Get all livestock for these farms using UUIDs
        $livestock = [];
        if (!empty($farmUuids)) {
            $livestock = $this->livestockController->fetchByFarmUuids($farmUuids);
        }

        return [
            'type' => 'farmer',
            'farms' => $farms,
            'livestock' => $livestock,
            'farmsCount' => count($farms),
            'livestockCount' => count($livestock),
        ];
    }

    /**
     * Get field worker data (extension officer, vet, farm invited user).
     * They might have access to specific farms they're assigned to.
     *
     * @param int $userId
     * @return array
     */
    private function getFieldWorkerData(int $userId): array
    {
        // TODO: Implement logic for field workers assigned to farms
        // For now, return empty data structure
        return [
            'type' => 'field_worker',
            'assignedFarms' => [],
            'accessibleLivestock' => [],
        ];
    }

    /**
     * Get system user data (admins can see everything).
     *
     * @return array
     */
    private function getSystemUserData(): array
    {
        return [
            'type' => 'system_user',
            'note' => 'System users have access to all data via admin endpoints',
        ];
    }

    /**
     * Sync all data - Single endpoint to get all data needed for the app.
     *
     * @return JsonResponse
     */
    public function syncAll(): JsonResponse
    {
        try {
            $data = [
                // Locations data (delegated to LocationController)
                'locations' => [
                    'countries' => $this->locationController->fetchCountries(),
                    'regions' => $this->locationController->fetchRegions(),
                    'districts' => $this->locationController->fetchDistricts(),
                    'wards' => $this->locationController->fetchWards(),
                    'villages' => $this->locationController->fetchVillages(),
                    'streets' => $this->locationController->fetchStreets(),
                    'divisions' => $this->locationController->fetchDivisions(),
                ],

                // Future data will be added here
                // 'farms' => $this->farmController->fetchFarms(), //fetchbythefarmerId
                // 'livestock' => $this->livestockController->fetchLivestock(),
                // 'breeds' => $this->breedController->fetchBreeds(),
                // 'species' => $this->speciesController->fetchSpecies(),
                // 'livestockTypes' => $this->livestockTypeController->fetchLivestockTypes(),
            ];

            return response()->json([
                'status' => true,
                'message' => 'All sync data retrieved successfully',
                'data' => $data,
                'timestamp' => now()->toIso8601String(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve sync data',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // ====================================================================================================
    //                                      Post Sync
    // ====================================================================================================

    /**
     * Full Post Sync - Handles incoming data from mobile app and syncs to server.
     *
     * This endpoint receives unsynced data from the mobile app (farms, livestock, etc.)
     * and processes it on the server side.
     *
     * Flow:
     * 1. Validate authenticated user matches userId parameter
     * 2. Process farms data (create/update/delete based on syncAction)
     * 3. Process livestock data (create/update/delete)
     * 4. Process other collections as they're implemented
     * 5. Return success response with synced item UUIDs
     *
     * @param Request $request
     * @param int $userId
     * @return JsonResponse
     */
    public function postSync(Request $request, int $userId): JsonResponse
    {
        try {
            \Log::info("========== POST SYNC START ==========");
            \Log::info("Request User ID: {$userId}");

            // Get authenticated user from request
            $authenticatedUser = $request->user();

            \Log::info("Authenticated User ID: {$authenticatedUser->id}");
            \Log::info("Authenticated User Role: {$authenticatedUser->role}");
            \Log::info("Authenticated User Role ID: {$authenticatedUser->roleId}");

            // Validate that the authenticated user matches the requested userId
            if ($authenticatedUser->id !== $userId) {
                \Log::warning("Unauthorized sync attempt", [
                    'authenticatedUserId' => $authenticatedUser->id,
                    'requestedUserId' => $userId
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized: You can only sync your own data',
                ], 403);
            }

            $user = User::find($userId);

            if (!$user) {
                \Log::error("User not found: {$userId}");

                return response()->json([
                    'status' => false,
                    'message' => 'User not found',
                ], 404);
            }

            // Get the payload
            $data = $request->all();

            \Log::info("Payload received", [
                'farmsCount' => isset($data['farms']) ? count($data['farms']) : 0,
                'livestockCount' => isset($data['livestock']) ? count($data['livestock']) : 0,
            ]);

            // Initialize response data
            $syncedData = [
                'syncedFarms' => [],
                'syncedLivestock' => [],
                // Add more collections as they're implemented
            ];

            // Process farms if present
            if (isset($data['farms']) && is_array($data['farms'])) {
                // Validate user is a farmer before processing
                if (strtolower($user->role) !== 'farmer') {
                    \Log::warning("Non-farmer attempting to sync farms", [
                        'userId' => $userId,
                        'role' => $user->role,
                        'roleId' => $user->roleId
                    ]);

                    // Skip farm processing for non-farmers
                    $syncedData['syncedFarms'] = [];
                } else {
                    // Get the farmer ID from the user's roleId
                    $farmerId = $user->roleId;

                    \Log::info("Processing farms for User ID: {$userId}, Farmer ID: {$farmerId}, Role: {$user->role}");

                    $syncedData['syncedFarms'] = $this->farmController->processFarms($data['farms'], $farmerId);
                }
            }

            // Process livestock if present
            if (isset($data['livestock']) && is_array($data['livestock'])) {
                // Validate user is a farmer before processing
                if (strtolower($user->role) !== 'farmer') {
                    \Log::warning("Non-farmer attempting to sync livestock", [
                        'userId' => $userId,
                        'role' => $user->role
                    ]);

                    // Skip livestock processing for non-farmers
                    $syncedData['syncedLivestock'] = [];
                } else {
                    // Get the farmer ID from the user's roleId
                    $farmerId = $user->roleId;

                    // TODO: Implement livestock processing
                    // $syncedData['syncedLivestock'] = $this->livestockController->processLivestock($data['livestock'], $farmerId);
                    $syncedData['syncedLivestock'] = [];
                }
            }

            // TODO: Process other collections (vaccines, feeds, etc.)
            // Follow the same pattern:
            // 1. Check user role
            // 2. Get appropriate roleId (farmerId, vetId, etc.)
            // 3. Pass to controller's process method

            \Log::info("Sync summary", [
                'syncedFarmsCount' => count($syncedData['syncedFarms']),
                'syncedLivestockCount' => count($syncedData['syncedLivestock']),
            ]);
            \Log::info("========== POST SYNC END ==========");

            return response()->json([
                'status' => true,
                'message' => 'Post sync completed successfully',
                'data' => $syncedData,
                'timestamp' => now()->toIso8601String(),
            ], 200);

        } catch (\Exception $e) {
            \Log::error("========== POST SYNC ERROR ==========");
            \Log::error("Error: " . $e->getMessage());
            \Log::error("Trace: " . $e->getTraceAsString());

            return response()->json([
                'status' => false,
                'message' => 'Failed to complete post sync',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

}
