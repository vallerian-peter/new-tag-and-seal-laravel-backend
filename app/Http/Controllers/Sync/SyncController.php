<?php

namespace App\Http\Controllers\Sync;

use App\Models\User;
use App\Models\Farm;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\CalvingProblem\CalvingProblemController;
use App\Http\Controllers\CalvingType\CalvingTypeController;
use App\Http\Controllers\HeatType\HeatTypeController;
use App\Http\Controllers\InseminationService\InseminationServiceController;
use App\Http\Controllers\MilkingMethod\MilkingMethodController;
use App\Http\Controllers\ReproductiveProblem\ReproductiveProblemController;
use App\Http\Controllers\SemenStrawType\SemenStrawTypeController;
use App\Http\Controllers\TestResult\TestResultController;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Farm\FarmController;
use App\Http\Controllers\Breed\BreedController;
use App\Http\Controllers\Specie\SpecieController;
use App\Http\Controllers\Location\LocationController;
use App\Http\Controllers\Livestock\LivestockController;
use App\Http\Controllers\Logs\Feeding\FeedingController;
use App\Http\Controllers\Logs\LogController;
use App\Http\Controllers\Logs\WeightChange\WeightChangeController;
use App\Http\Controllers\Logs\Deworming\DewormingController;
use App\Http\Controllers\Logs\Medication\MedicationController;
use App\Http\Controllers\Logs\Vaccination\VaccinationController;
use App\Http\Controllers\Logs\Disposal\DisposalController;
use App\Http\Controllers\Logs\Milking\MilkingController;
use App\Http\Controllers\Logs\Pregnancy\PregnancyController;
use App\Http\Controllers\Logs\Calving\CalvingController;
use App\Http\Controllers\Logs\Dryoff\DryoffController;
use App\Http\Controllers\Logs\Insemination\InseminationController;
use App\Http\Controllers\Logs\Transfer\TransferController;
use App\Http\Controllers\FeedingType\FeedingTypeController;
use App\Http\Controllers\AdministrationRoute\AdministrationRouteController;
use App\Http\Controllers\MedicineType\MedicineTypeController;
use App\Http\Controllers\Medicine\MedicineController;
use App\Http\Controllers\Vaccine\VaccineController;
use App\Http\Controllers\Vaccine\VaccineTypeController;
use App\Http\Controllers\DisposalType\DisposalTypeController;
use App\Http\Controllers\LegalStatus\LegalStatusController;
use App\Http\Controllers\SchoolLevel\SchoolLevelController;
use App\Http\Controllers\LivestockType\LivestockTypeController;
use App\Http\Controllers\IdentityCardType\IdentityCardTypeController;
use App\Http\Controllers\LivestockObtainedMethod\LivestockObtainedMethodController;
use App\Http\Controllers\Disease\DiseaseController;

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
    protected $feedingController;
    protected $weightChangeController;
    protected $dewormingController;
    protected $feedingTypeController;
    protected $administrationRouteController;
    protected $medicineTypeController;
    protected $medicineController;
    protected $logController;
    protected $vaccineController;
    protected $vaccineTypeController;
    protected $medicationController;
    protected $vaccinationController;
    protected $disposalController;
    protected $disposalTypeController;
    protected $diseaseController;
    protected $milkingController;
    protected $pregnancyController;
    protected $calvingController;
    protected $dryoffController;
    protected $inseminationController;
    protected $transferController;
    protected $heatTypeController;
    protected $semenStrawTypeController;
    protected $inseminationServiceController;
    protected $milkingMethodController;
    protected $calvingTypeController;
    protected $calvingProblemController;
    protected $reproductiveProblemController;
    protected $testResultController;

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
        LivestockController $livestockController,
        FeedingController $feedingController,
        WeightChangeController $weightChangeController,
        DewormingController $dewormingController,
        MedicationController $medicationController,
        VaccinationController $vaccinationController,
        DisposalController $disposalController,
        FeedingTypeController $feedingTypeController,
        AdministrationRouteController $administrationRouteController,
        MedicineTypeController $medicineTypeController,
        MedicineController $medicineController,
        LogController $logController,
        VaccineController $vaccineController,
        VaccineTypeController $vaccineTypeController,
        DisposalTypeController $disposalTypeController,
        DiseaseController $diseaseController,
        MilkingController $milkingController,
        PregnancyController $pregnancyController,
        CalvingController $calvingController,
        DryoffController $dryoffController,
        InseminationController $inseminationController,
        TransferController $transferController,
        HeatTypeController $heatTypeController,
        SemenStrawTypeController $semenStrawTypeController,
        InseminationServiceController $inseminationServiceController,
        MilkingMethodController $milkingMethodController,
        CalvingTypeController $calvingTypeController,
        CalvingProblemController $calvingProblemController,
        ReproductiveProblemController $reproductiveProblemController,
        TestResultController $testResultController
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
        $this->feedingController = $feedingController;
        $this->weightChangeController = $weightChangeController;
        $this->dewormingController = $dewormingController;
        $this->medicationController = $medicationController;
        $this->vaccinationController = $vaccinationController;
        $this->disposalController = $disposalController;
        $this->feedingTypeController = $feedingTypeController;
        $this->administrationRouteController = $administrationRouteController;
        $this->medicineTypeController = $medicineTypeController;
        $this->medicineController = $medicineController;
        $this->logController = $logController;
        $this->vaccineController = $vaccineController;
        $this->vaccineTypeController = $vaccineTypeController;
        $this->disposalTypeController = $disposalTypeController;
        $this->diseaseController = $diseaseController;
        $this->milkingController = $milkingController;
        $this->pregnancyController = $pregnancyController;
        $this->calvingController = $calvingController;
        $this->dryoffController = $dryoffController;
        $this->inseminationController = $inseminationController;
        $this->transferController = $transferController;
        $this->heatTypeController = $heatTypeController;
        $this->semenStrawTypeController = $semenStrawTypeController;
        $this->inseminationServiceController = $inseminationServiceController;
        $this->milkingMethodController = $milkingMethodController;
        $this->calvingTypeController = $calvingTypeController;
        $this->calvingProblemController = $calvingProblemController;
        $this->reproductiveProblemController = $reproductiveProblemController;
        $this->testResultController = $testResultController;
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
                // TODO: Add more reference data here,(logs reference data should be added here)
                'referenceData' => [
                    'identityCardTypes' => $this->identityCardTypeController->fetchAll(),
                    'schoolLevels' => $this->schoolLevelController->fetchAll(),
                    'legalStatuses' => $this->legalStatusController->fetchAll(),
                    'feedingTypes' => $this->feedingTypeController->fetchAll(),
                    'administrationRoutes' => $this->administrationRouteController->fetchAll(),
                    'medicineTypes' => $this->medicineTypeController->fetchAll(),
                    'medicines' => $this->medicineController->fetchAll(),
                    'vaccineTypes' => $this->vaccineTypeController->fetchAll(),
                    'disposalTypes' => $this->disposalTypeController->fetchAll(),
                    'diseases' => $this->diseaseController->fetchAll(),
                    'heatTypes' => $this->heatTypeController->fetchAll(),
                    'semenStrawTypes' => $this->semenStrawTypeController->fetchAll(),
                    'inseminationServices' => $this->inseminationServiceController->fetchAll(),
                    'milkingMethods' => $this->milkingMethodController->fetchAll(),
                    'calvingTypes' => $this->calvingTypeController->fetchAll(),
                    'calvingProblems' => $this->calvingProblemController->fetchAll(),
                    'reproductiveProblems' => $this->reproductiveProblemController->fetchAll(),
                    'testResults' => $this->testResultController->fetchAll(),
                ],

                // 3. Livestock reference data (species, types, breeds, methods)
                'livestockReferenceData' => [
                    'species' => $this->specieController->fetchAll(),
                    'livestockTypes' => $this->livestockTypeController->fetchAll(),
                    'breeds' => $this->breedController->fetchAll(),
                    'livestockObtainedMethods' => $this->livestockObtainedMethodController->fetchAll(),
                ],

                // 4. Logs specific data (populated based on role)
                'userSpecificData' => [], # Dont touch since at default is empty

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
            Log::error('Splash sync error', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
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

        // Get all Logs of the farmer
        $logs = [];
        $livestockUuids = array_column($livestock, 'uuid');
        if(!empty($farmUuids) && !empty($livestockUuids)){
            $logs = $this->logController->fetchLogsByFarmLivestockUuids($farmUuids, $livestockUuids);
        }

        // Get all vaccines for the farmer's farms
        $vaccines = [];
        if (!empty($farmUuids)) {
            $vaccines = $this->vaccineController->fetchByFarmUuids($farmUuids);
        }

        return [
            'type' => 'farmer',
            'farms' => $farms,
            'livestock' => $livestock,
            'logs' => $logs,
            'vaccines' => $vaccines,
            'farmsCount' => count($farms),
            'livestockCount' => count($livestock),
            'vaccinesCount' => count($vaccines),
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
                'syncedVaccines' => [],
                'syncedLogs' => [
                    'feedings' => [],
                    'weightChanges' => [],
                    'dewormings' => [],
                    'medications' => [],
                    'vaccinations' => [],
                    'disposals' => [],
                    'milkings' => [],
                    'pregnancies' => [],
                    'calvings' => [],
                    'dryoffs' => [],
                    'inseminations' => [],
                    'transfers' => [],
                ],
                // Add more collections as they're implemented
            ];

            $syncedData['syncedFarms'] = isset($data['farms']) && is_array($data['farms'])
                ? $this->processFarmSync($data['farms'], $user, $userId)
                : [];

            $syncedData['syncedLivestock'] = isset($data['livestock']) && is_array($data['livestock'])
                ? $this->processLivestockSync($data['livestock'], $user, $userId)
                : [];

            $syncedData['syncedVaccines'] = isset($data['vaccines']) && is_array($data['vaccines'])
                ? $this->processVaccineSync($data['vaccines'], $user, $userId)
                : [];

            $logsPayload = $data['logs'] ?? [];
            $syncedData['syncedLogs']['feedings'] = $this->processLogSync(
                $logsPayload['feedings'] ?? [],
                $user,
                $userId,
                'feeding',
                fn (array $collection, string $livestockUuid) => $this->feedingController->processFeedings($collection, $livestockUuid)
            );

            $syncedData['syncedLogs']['weightChanges'] = $this->processLogSync(
                $logsPayload['weightChanges'] ?? [],
                $user,
                $userId,
                'weight change',
                fn (array $collection, string $livestockUuid) => $this->weightChangeController->processWeightChanges($collection, $livestockUuid)
            );

            $syncedData['syncedLogs']['dewormings'] = $this->processLogSync(
                $logsPayload['dewormings'] ?? [],
                $user,
                $userId,
                'deworming',
                fn (array $collection, string $livestockUuid) => $this->dewormingController->processDewormings($collection, $livestockUuid)
            );

        $syncedData['syncedLogs']['medications'] = $this->processLogSync(
            $logsPayload['medications'] ?? [],
            $user,
            $userId,
            'medication',
            fn (array $collection, string $livestockUuid) => $this->medicationController->processMedications($collection, $livestockUuid)
        );

        $syncedData['syncedLogs']['vaccinations'] = $this->processLogSync(
            $logsPayload['vaccinations'] ?? [],
            $user,
            $userId,
            'vaccination',
            fn (array $collection, string $livestockUuid) => $this->vaccinationController->processVaccinations($collection, $livestockUuid)
        );

        $syncedData['syncedLogs']['disposals'] = $this->processLogSync(
            $logsPayload['disposals'] ?? [],
            $user,
            $userId,
            'disposal',
            fn (array $collection, string $livestockUuid) => $this->disposalController->processDisposals($collection, $livestockUuid)
        );

            $syncedData['syncedLogs']['milkings'] = $this->processLogSync(
                $logsPayload['milkings'] ?? [],
                $user,
                $userId,
                'milking',
                fn (array $collection, string $livestockUuid) => $this->milkingController->processMilkings($collection, $livestockUuid)
            );

            $syncedData['syncedLogs']['pregnancies'] = $this->processLogSync(
                $logsPayload['pregnancies'] ?? [],
                $user,
                $userId,
                'pregnancy',
                fn (array $collection, string $livestockUuid) => $this->pregnancyController->processPregnancies($collection, $livestockUuid)
            );

            $syncedData['syncedLogs']['calvings'] = $this->processLogSync(
                $logsPayload['calvings'] ?? [],
                $user,
                $userId,
                'calving',
                fn (array $collection, string $livestockUuid) => $this->calvingController->processCalvings($collection, $livestockUuid)
            );

            $syncedData['syncedLogs']['dryoffs'] = $this->processLogSync(
                $logsPayload['dryoffs'] ?? [],
                $user,
                $userId,
                'dryoff',
                fn (array $collection, string $livestockUuid) => $this->dryoffController->processDryoffs($collection, $livestockUuid)
            );

            $syncedData['syncedLogs']['inseminations'] = $this->processLogSync(
                $logsPayload['inseminations'] ?? [],
                $user,
                $userId,
                'insemination',
                fn (array $collection, string $livestockUuid) => $this->inseminationController->processInseminations($collection, $livestockUuid)
            );

            $syncedData['syncedLogs']['transfers'] = $this->processLogSync(
                $logsPayload['transfers'] ?? [],
                $user,
                $userId,
                'transfer',
                fn (array $collection, string $livestockUuid) => $this->transferController->processTransfers($collection, $livestockUuid)
            );

            // TODO: Process other collections (vaccines, feeds, etc.)
            // Follow the same pattern:
            // 1. Check user role
            // 2. Get appropriate roleId (farmerId, vetId, etc.)
            // 3. Pass to controller's process method

            \Log::info("Sync summary", [
                'syncedFarmsCount' => count($syncedData['syncedFarms']),
                'syncedLivestockCount' => count($syncedData['syncedLivestock']),
                'syncedVaccinesCount' => count($syncedData['syncedVaccines']),
                'syncedFeedingsCount' => isset($syncedData['syncedLogs']['feedings'])
                    ? count($syncedData['syncedLogs']['feedings'])
                    : 0,
                'syncedWeightChangesCount' => isset($syncedData['syncedLogs']['weightChanges'])
                    ? count($syncedData['syncedLogs']['weightChanges'])
                    : 0,
                'syncedDewormingsCount' => isset($syncedData['syncedLogs']['dewormings'])
                    ? count($syncedData['syncedLogs']['dewormings'])
                    : 0,
            'syncedMedicationsCount' => isset($syncedData['syncedLogs']['medications'])
                ? count($syncedData['syncedLogs']['medications'])
                : 0,
            'syncedVaccinationsCount' => isset($syncedData['syncedLogs']['vaccinations'])
                ? count($syncedData['syncedLogs']['vaccinations'])
                : 0,
            'syncedDisposalsCount' => isset($syncedData['syncedLogs']['disposals'])
                ? count($syncedData['syncedLogs']['disposals'])
                : 0,
            'syncedMilkingsCount' => isset($syncedData['syncedLogs']['milkings'])
                ? count($syncedData['syncedLogs']['milkings'])
                : 0,
            'syncedPregnanciesCount' => isset($syncedData['syncedLogs']['pregnancies'])
                ? count($syncedData['syncedLogs']['pregnancies'])
                : 0,
            'syncedCalvingsCount' => isset($syncedData['syncedLogs']['calvings'])
                ? count($syncedData['syncedLogs']['calvings'])
                : 0,
            'syncedDryoffsCount' => isset($syncedData['syncedLogs']['dryoffs'])
                ? count($syncedData['syncedLogs']['dryoffs'])
                : 0,
            'syncedInseminationsCount' => isset($syncedData['syncedLogs']['inseminations'])
                ? count($syncedData['syncedLogs']['inseminations'])
                : 0,
            'syncedTransfersCount' => isset($syncedData['syncedLogs']['transfers'])
                ? count($syncedData['syncedLogs']['transfers'])
                : 0,
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

    private function processFarmSync(array $farms, User $user, int $userId): array
    {
        if (empty($farms)) {
            \Log::info("No farms provided for sync.");
            return [];
        }

        if (strtolower($user->role) !== 'farmer') {
            \Log::warning("Non-farmer attempting to sync farms", [
                'userId' => $userId,
                'role' => $user->role,
                'roleId' => $user->roleId,
            ]);
            return [];
        }

        $farmerId = $user->roleId;
        \Log::info("Processing farms for User ID: {$userId}, Farmer ID: {$farmerId}, Role: {$user->role}");

        $syncedFarms = $this->farmController->processFarms($farms, $farmerId);
        \Log::info("Farm sync complete for user {$userId}", ['count' => count($syncedFarms)]);

        return $syncedFarms;
    }

    private function processLivestockSync(array $livestock, User $user, int $userId): array
    {
        if (empty($livestock)) {
            \Log::info("No livestock provided for sync.");
            return [];
        }

        if (strtolower($user->role) !== 'farmer') {
            \Log::warning("Non-farmer attempting to sync livestock", [
                'userId' => $userId,
                'role' => $user->role,
            ]);
            return [];
        }

        $farmerId = $user->roleId;
        \Log::info("Processing livestock for User ID: {$userId}, Farmer ID: {$farmerId}, Role: {$user->role}");

        $syncedLivestock = $this->livestockController->processLivestock($livestock, $farmerId);
        \Log::info("Livestock sync complete for user {$userId}", ['count' => count($syncedLivestock)]);

        return $syncedLivestock;
    }

    private function processVaccineSync(array $vaccines, User $user, int $userId): array
    {
        if (empty($vaccines)) {
            \Log::info("No vaccines provided for sync.");
            return [];
        }

        if (strtolower($user->role) !== 'farmer') {
            \Log::warning("Non-farmer attempting to sync vaccines", [
                'userId' => $userId,
                'role' => $user->role,
            ]);
            return [];
        }

        $farmerId = $user->roleId;
        $allowedFarmUuids = Farm::where('farmerId', $farmerId)->pluck('uuid')->toArray();

        return $this->vaccineController->processVaccines($vaccines, $allowedFarmUuids);
    }

    /**
     * @param array    $logs
     * @param User     $user
     * @param int      $userId
     * @param string   $logLabel
     * @param callable $processor  function(array $logGroup, string $livestockUuid): array
     * @return array
     */
    private function processLogSync(array $logs, User $user, int $userId, string $logLabel, callable $processor): array
    {
        if (empty($logs)) {
            \Log::info("No {$logLabel} logs provided for sync.");
            return [];
        }

        if (strtolower($user->role) !== 'farmer') {
            \Log::warning("Non-farmer attempting to sync {$logLabel}s", [
                'userId' => $userId,
                'role' => $user->role,
            ]);
            return [];
        }

        \Log::info(strtoupper("========== PROCESSING {$logLabel}s START =========="));
        \Log::info("Total {$logLabel}s to process: " . count($logs));

        $groupedLogs = [];
        foreach ($logs as $entry) {
            $livestockUuid = $entry['livestockUuid'] ?? null;
            if (!$livestockUuid) {
                \Log::warning(ucfirst($logLabel) . " entry missing livestockUuid", ['entry' => $entry]);
                continue;
            }
            $groupedLogs[$livestockUuid][] = $entry;
        }

        $synced = [];
        foreach ($groupedLogs as $livestockUuid => $collection) {
            \Log::info("Processing {$logLabel}s for Livestock UUID: {$livestockUuid} (User ID: {$userId})");
            $result = $processor($collection, $livestockUuid);
            if (is_array($result)) {
                $synced = array_merge($synced, $result);
            }
        }

        \Log::info(strtoupper("========== PROCESSING {$logLabel}s END =========="));
        \Log::info("Total {$logLabel}s synced: " . count($synced));

        return $synced;
    }

}
