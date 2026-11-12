<?php

namespace App\Http\Controllers\Sync;

use App\Enums\UserRole;
use App\Http\Controllers\AdministrationRoute\AdministrationRouteController;
use App\Http\Controllers\Bill\BillController;
use App\Http\Controllers\BirthProblem\BirthProblemController;
use App\Http\Controllers\BirthType\BirthTypeController;
use App\Http\Controllers\Breed\BreedController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Disease\DiseaseController;
use App\Http\Controllers\DisposalType\DisposalTypeController;
use App\Http\Controllers\ExtensionOfficerFarmInvite\ExtensionOfficerFarmInviteController;
use App\Http\Controllers\Farm\FarmController;
use App\Http\Controllers\FarmUser\FarmUserController;
use App\Http\Controllers\FeedingType\FeedingTypeController;
use App\Http\Controllers\HeatType\HeatTypeController;
use App\Http\Controllers\IdentityCardType\IdentityCardTypeController;
use App\Http\Controllers\InseminationService\InseminationServiceController;
use App\Http\Controllers\LegalStatus\LegalStatusController;
use App\Http\Controllers\Livestock\LivestockController;
use App\Http\Controllers\LivestockMarkingType\LivestockMarkingTypeController;
use App\Http\Controllers\LivestockObtainedMethod\LivestockObtainedMethodController;
use App\Http\Controllers\LivestockType\LivestockTypeController;
use App\Http\Controllers\Location\LocationController;
use App\Http\Controllers\Logs\AbortedPregnancy\AbortedPregnancyController;
use App\Http\Controllers\Logs\Birth\BirthEventController;
use App\Http\Controllers\Logs\Deworming\DewormingController;
use App\Http\Controllers\Logs\Disposal\DisposalController;
use App\Http\Controllers\Logs\Dryoff\DryoffController;
use App\Http\Controllers\Logs\Feeding\FeedingController;
use App\Http\Controllers\Logs\Insemination\InseminationController;
use App\Http\Controllers\Logs\IronInjection\IronInjectionController;
use App\Http\Controllers\Logs\LivestockMarking\LivestockMarkingController;
use App\Http\Controllers\Logs\LogController;
use App\Http\Controllers\Logs\Milking\MilkingController;
use App\Http\Controllers\Logs\Pregnancy\PregnancyController;
use App\Http\Controllers\Logs\PrepuceCondition\PrepuceConditionController;
use App\Http\Controllers\Logs\StageChange\StageChangeController;
use App\Http\Controllers\Logs\TailDocking\TailDockingController;
use App\Http\Controllers\Logs\TeethClipping\TeethClippingController;
use App\Http\Controllers\Logs\Transfer\TransferController;
use App\Http\Controllers\Logs\Treatment\TreatmentController;
use App\Http\Controllers\Logs\Vaccination\VaccinationController;
use App\Http\Controllers\Logs\WeightChange\WeightChangeController;
use App\Http\Controllers\Medicine\MedicineController;
use App\Http\Controllers\MedicineType\MedicineTypeController;
use App\Http\Controllers\MilkingMethod\MilkingMethodController;
use App\Http\Controllers\PrepuceConditionLookup\PrepuceConditionLookupController;
use App\Http\Controllers\ReproductiveProblem\ReproductiveProblemController;
use App\Http\Controllers\SchoolLevel\SchoolLevelController;
use App\Http\Controllers\SemenStrawType\SemenStrawTypeController;
use App\Http\Controllers\Specie\SpecieController;
use App\Http\Controllers\TailDockingMethod\TailDockingMethodController;
use App\Http\Controllers\TeethClippingMethod\TeethClippingMethodController;
use App\Http\Controllers\TestResult\TestResultController;
use App\Http\Controllers\Vaccine\VaccineController;
use App\Http\Controllers\Vaccine\VaccineTypeController;
use App\Models\AbortedPregnancy;
use App\Models\BirthEvent;
use App\Models\Deworming;
use App\Models\Disposal;
use App\Models\Dryoff;
use App\Models\ExtensionOfficer;
use App\Models\ExtensionOfficerFarmInvite;
use App\Models\Farm;
use App\Models\Farmer;
use App\Models\FarmUser;
use App\Models\Feeding;
use App\Models\FinanceExpense;
use App\Models\FinanceIncome;
use App\Models\Insemination;
use App\Models\IronInjection;
use App\Models\Livestock;
use App\Models\LivestockMarking;
use App\Models\Milking;
use App\Models\Pregnancy;
use App\Models\PrepuceCondition;
use App\Models\Stage;
use App\Models\StageChange;
use App\Models\SyncTombstone;
use App\Models\TailDocking;
use App\Models\TeethClipping;
use App\Models\Transfer;
use App\Models\Treatment;
use App\Models\User;
use App\Models\Vaccination;
use App\Models\Vaccine;
use App\Models\WeightChange;
use App\Services\Dynamic\FarmAccessService;
use App\Services\Hiring\FarmOwnership;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    protected $billController;

    protected $farmUserController;

    protected $treatmentController;

    protected $vaccinationController;

    protected $disposalController;

    protected $birthEventController;

    protected $abortedPregnancyController;

    protected $milkingController;

    protected $pregnancyController;

    protected $inseminationController;

    protected $dryoffController;

    protected $transferController;

    protected $birthTypeController;

    protected $birthProblemController;

    protected $reproductiveProblemController;

    protected $diseaseController;

    protected $disposalTypeController;

    protected $heatTypeController;

    protected $inseminationServiceController;

    protected $semenStrawTypeController;

    protected $testResultController;

    protected $milkingMethodController;

    protected $teethClippingMethodController;

    protected $tailDockingMethodController;

    protected $livestockMarkingTypeController;

    protected $extensionOfficerFarmInviteController;

    protected $teethClippingController;

    protected $tailDockingController;

    protected $ironInjectionController;

    protected $livestockMarkingController;

    protected $stageChangeController;

    protected $prepuceConditionLookupController;

    protected $prepuceConditionController;

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
        TreatmentController $treatmentController,
        VaccinationController $vaccinationController,
        DisposalController $disposalController,
        BirthEventController $birthEventController,
        AbortedPregnancyController $abortedPregnancyController,
        MilkingController $milkingController,
        PregnancyController $pregnancyController,
        InseminationController $inseminationController,
        DryoffController $dryoffController,
        TransferController $transferController,
        FeedingTypeController $feedingTypeController,
        AdministrationRouteController $administrationRouteController,
        MedicineTypeController $medicineTypeController,
        MedicineController $medicineController,
        LogController $logController,
        VaccineController $vaccineController,
        VaccineTypeController $vaccineTypeController,
        FarmUserController $farmUserController,
        BirthTypeController $birthTypeController,
        BirthProblemController $birthProblemController,
        ReproductiveProblemController $reproductiveProblemController,
        DiseaseController $diseaseController,
        DisposalTypeController $disposalTypeController,
        HeatTypeController $heatTypeController,
        InseminationServiceController $inseminationServiceController,
        SemenStrawTypeController $semenStrawTypeController,
        TestResultController $testResultController,
        MilkingMethodController $milkingMethodController,
        TeethClippingMethodController $teethClippingMethodController,
        TailDockingMethodController $tailDockingMethodController,
        LivestockMarkingTypeController $livestockMarkingTypeController,
        ExtensionOfficerFarmInviteController $extensionOfficerFarmInviteController,
        BillController $billController,
        TeethClippingController $teethClippingController,
        TailDockingController $tailDockingController,
        IronInjectionController $ironInjectionController,
        LivestockMarkingController $livestockMarkingController,
        StageChangeController $stageChangeController,
        PrepuceConditionLookupController $prepuceConditionLookupController,
        PrepuceConditionController $prepuceConditionController
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
        $this->treatmentController = $treatmentController;
        $this->vaccinationController = $vaccinationController;
        $this->disposalController = $disposalController;
        $this->birthEventController = $birthEventController;
        $this->abortedPregnancyController = $abortedPregnancyController;
        $this->milkingController = $milkingController;
        $this->pregnancyController = $pregnancyController;
        $this->inseminationController = $inseminationController;
        $this->dryoffController = $dryoffController;
        $this->transferController = $transferController;
        $this->feedingTypeController = $feedingTypeController;
        $this->administrationRouteController = $administrationRouteController;
        $this->medicineTypeController = $medicineTypeController;
        $this->medicineController = $medicineController;
        $this->logController = $logController;
        $this->vaccineController = $vaccineController;
        $this->vaccineTypeController = $vaccineTypeController;
        $this->farmUserController = $farmUserController;
        $this->birthTypeController = $birthTypeController;
        $this->birthProblemController = $birthProblemController;
        $this->reproductiveProblemController = $reproductiveProblemController;
        $this->diseaseController = $diseaseController;
        $this->disposalTypeController = $disposalTypeController;
        $this->heatTypeController = $heatTypeController;
        $this->inseminationServiceController = $inseminationServiceController;
        $this->semenStrawTypeController = $semenStrawTypeController;
        $this->testResultController = $testResultController;
        $this->milkingMethodController = $milkingMethodController;
        $this->teethClippingMethodController = $teethClippingMethodController;
        $this->tailDockingMethodController = $tailDockingMethodController;
        $this->livestockMarkingTypeController = $livestockMarkingTypeController;
        $this->extensionOfficerFarmInviteController = $extensionOfficerFarmInviteController;
        $this->billController = $billController;
        $this->teethClippingController = $teethClippingController;
        $this->tailDockingController = $tailDockingController;
        $this->ironInjectionController = $ironInjectionController;
        $this->livestockMarkingController = $livestockMarkingController;
        $this->stageChangeController = $stageChangeController;
        $this->prepuceConditionLookupController = $prepuceConditionLookupController;
        $this->prepuceConditionController = $prepuceConditionController;
    }

    /**
     * Initial sync for registration - Returns only reference data needed for registration.
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
                'error' => $e->getMessage(),
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
     */
    public function splashSync(Request $request, int $userId): JsonResponse
    {
        try {
            // Get authenticated user from request
            $authenticatedUser = $request->user();

            if (! $authenticatedUser) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            // Validate that the authenticated user matches the requested userId
            if ($authenticatedUser->id !== $userId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized access to user data',
                ], 403);
            }

            $user = User::find($userId);

            if (! $user) {
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
                'referenceData' => array_merge([
                    'identityCardTypes' => $this->identityCardTypeController->fetchAll(),
                    'schoolLevels' => $this->schoolLevelController->fetchAll(),
                    'legalStatuses' => $this->legalStatusController->fetchAll(),
                    'feedingTypes' => $this->feedingTypeController->fetchAll(),
                    'administrationRoutes' => $this->administrationRouteController->fetchAll(),
                    'medicineTypes' => $this->medicineTypeController->fetchAll(),
                    'medicines' => $this->medicineController->fetchAll(),
                    'diseases' => $this->diseaseController->fetchAll(),
                    'disposalTypes' => $this->disposalTypeController->fetchAll(),
                    'birthTypes' => $this->birthTypeController->fetchAll(),
                    'birthProblems' => $this->birthProblemController->fetchAll(),
                    'reproductiveProblems' => $this->reproductiveProblemController->fetchAll(),
                    'heatTypes' => $this->heatTypeController->fetchAll(),
                    'inseminationServices' => $this->inseminationServiceController->fetchAll(),
                    'semenStrawTypes' => $this->semenStrawTypeController->fetchAll(),
                    'testResults' => $this->testResultController->fetchAll(),
                    'milkingMethods' => $this->milkingMethodController->fetchAll(),
                    'teethClippingMethods' => $this->teethClippingMethodController->fetchAll(),
                    'tailDockingMethods' => $this->tailDockingMethodController->fetchAll(),
                    'livestockMarkingTypes' => $this->livestockMarkingTypeController->fetchAll(),
                ], $this->prepuceConditionLookupController->referenceDataForSync()),

                // 3. Livestock reference data (species, types, breeds, methods, vaccine types)
                'livestockReferenceData' => [
                    'species' => $this->specieController->fetchAll(),
                    'livestockTypes' => $this->livestockTypeController->fetchAll(),
                    'breeds' => $this->breedController->fetchAll(),
                    'livestockObtainedMethods' => $this->livestockObtainedMethodController->fetchAll(),
                    'vaccineTypes' => $this->vaccineTypeController->fetchAll(),
                    'stages' => Stage::query()
                        ->orderBy('livestockTypeId')
                        ->orderBy('name')
                        ->get()
                        ->map(static fn (Stage $stage) => [
                            'id' => $stage->id,
                            'name' => $stage->name,
                            'livestockTypeId' => $stage->livestockTypeId,
                            'isTagApplicable' => $stage->isTagApplicable(),
                            'is_tag_applicable' => $stage->isTagApplicable(),
                        ])
                        ->values()
                        ->all(),
                ],

                // 4. Logs specific data (populated based on role)
                'userSpecificData' => [], // Dont touch since at default is empty

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
            // Normalize role to lowercase and remove all separators for comparison
            $normalizedRole = strtolower(str_replace([' ', '_', '-'], '', trim($user->role ?? '')));

            // Check role and assign appropriate data
            if (in_array($normalizedRole, ['farmer'])) {
                if (! Farmer::where('id', $user->roleId)->where('status', 'active')->exists()) {
                    return response()->json(['status' => false, 'message' => 'Farmer profile is inactive.'], 403);
                }
                $data['userSpecificData'] = $this->getFarmerData($user->roleId);
                if (DB::table('farm_access_grants')->where('user_id', $user->id)->exists()) {
                    $data['userSpecificData'] = array_replace($data['userSpecificData'], $this->getGrantedFarmData($user), ['type' => 'farmer']);
                }
            } elseif ($normalizedRole === 'appuser' || DB::table('farm_access_grants')->where('user_id', $user->id)->exists() || app(FarmOwnership::class)->farmerIds($user)) {
                $data['userSpecificData'] = $this->getGrantedFarmData($user);
            } elseif (in_array($normalizedRole, [
                'extensionofficer',
                'vet',
                'farminviteduser',
                'auctionofficer',
            ])) {
                $data['userSpecificData'] = $this->getFieldWorkerData($user->roleId);
            } elseif (in_array($normalizedRole, ['systemuser'])) {
                $data['userSpecificData'] = $this->getSystemUserData();
            } else {
                // Return empty object structure (not array) for unknown roles
                \Log::warning("Unknown user role for splash sync: '{$user->role}' (normalized: '{$normalizedRole}')");
                $data['userSpecificData'] = [
                    'type' => 'unknown',
                    'farms' => [],
                    'livestock' => [],
                    'logs' => [],
                    'vaccines' => [],
                    'farmUsers' => [],
                    'farmsCount' => 0,
                    'livestockCount' => 0,
                    'vaccinesCount' => 0,
                ];
            }

            return response()->json([
                'status' => true,
                'message' => 'Splash sync completed successfully',
                'data' => $data,
                'timestamp' => now()->toIso8601String(),
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Splash sync error: '.$e->getMessage(), [
                'userId' => $userId,
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

    private function getGrantedFarmData(User $user): array
    {
        $access = app(FarmAccessService::class);
        $farmUuids = $access->accessibleFarmUuids($user) ?? [];
        $farms = $farmUuids ? $this->farmController->fetchByUuids($farmUuids) : [];
        $livestock = $farmUuids ? $this->livestockController->fetchByFarmUuids($farmUuids) : [];
        $logs = $farmUuids ? $this->logController->fetchLogsByFarmLivestockUuids($farmUuids, array_column($livestock, 'uuid')) : [];
        $financeFarms = array_values(array_filter($farmUuids, fn ($farm) => $access->can($user, $farm, 'finance.manage')));

        return [
            'type' => 'field_worker', 'farmUser' => null, 'farms' => $farms, 'livestock' => $livestock,
            'logs' => $this->filterWorkerLogs($user, $logs), 'vaccines' => $this->vaccineController->fetchByFarmUuids(array_values(array_filter($farmUuids, fn ($farm) => $access->canModule($user, $farm, 'vaccination', 'view')))),
            'tombstones' => $this->livestockTombstonesForFarmUuids($farmUuids),
            'financeExpenses' => $financeFarms ? $this->fetchFinanceExpensesManualForFarmUuids($financeFarms) : [],
            'financeIncomes' => $financeFarms ? $this->fetchFinanceIncomesForFarmUuids($financeFarms) : [],
            'farmsCount' => count($farms), 'livestockCount' => count($livestock),
        ];
    }

    /**
     * Get farmer-specific data (farms and livestock).
     */
    private function getFarmerData(int $farmerId): array
    {
        // Get all farms for this farmer
        $farms = $this->farmController->fetchByFarmerId($farmerId);

        // Extract farm UUIDs (not IDs) for livestock lookup
        $farmUuids = array_column($farms, 'uuid');

        // Get all livestock for these farms using UUIDs
        $livestock = [];
        if (! empty($farmUuids)) {
            $livestock = $this->livestockController->fetchByFarmUuids($farmUuids);
        }

        // Get all Logs of the farmer
        $logs = [];
        $livestockUuids = array_column($livestock, 'uuid');
        if (! empty($farmUuids)) {
            // Fetch logs - transfers will be fetched even if livestockUuids is empty
            // (since transferred livestock may no longer be in the source farm)
            $logs = $this->logController->fetchLogsByFarmLivestockUuids($farmUuids, $livestockUuids);
            \Log::info('Farmer sync: Fetched logs - vaccinations: '.(isset($logs['vaccinations']) ? count($logs['vaccinations']) : 0).', transfers: '.(isset($logs['transfers']) ? count($logs['transfers']) : 0));
        } else {
            \Log::warning('Farmer sync: Cannot fetch logs - farmUuids: '.json_encode($farmUuids).', livestockUuids: '.json_encode($livestockUuids));
        }

        // Get all vaccines for the farmer's farms
        $vaccines = [];
        if (! empty($farmUuids)) {
            $vaccines = $this->vaccineController->fetchByFarmUuids($farmUuids);
        }

        // Get all bills for the farmer's farms
        $bills = [];
        if (! empty($farmUuids)) {
            $bills = $this->billController->fetchByFarmUuids($farmUuids);
        }

        $financeExpenses = ! empty($farmUuids)
            ? $this->fetchFinanceExpensesManualForFarmUuids($farmUuids)
            : [];

        $financeIncomes = ! empty($farmUuids)
            ? $this->fetchFinanceIncomesForFarmUuids($farmUuids)
            : [];

        // Get all farm users assigned to the farmer's farms
        $farmUsers = [];
        if (! empty($farmUuids)) {
            $farmUsers = $this->farmUserController->fetchByFarmUuids($farmUuids);
        }

        // Get Invited Extension Officers
        $invitedExtensionOfficers = $this->extensionOfficerFarmInviteController->fetchByFarmerId($farmerId);

        return [
            'type' => 'farmer',
            'farms' => $farms,
            'livestock' => $livestock,
            'tombstones' => $this->livestockTombstonesForFarmUuids($farmUuids),
            'logs' => $logs,
            'vaccines' => $vaccines,
            'bills' => $bills,
            'financeExpenses' => $financeExpenses,
            'financeIncomes' => $financeIncomes,
            'farmUsers' => $farmUsers,
            'invitedExtensionOfficers' => $invitedExtensionOfficers,
            'farmsCount' => count($farms),
            'livestockCount' => count($livestock),
            'tombstonesCount' => count($this->livestockTombstonesForFarmUuids($farmUuids)),
            'vaccinesCount' => count($vaccines),
            'billsCount' => count($bills),
            'financeExpensesCount' => count($financeExpenses),
            'financeIncomesCount' => count($financeIncomes),
            'farmUsersCount' => count($farmUsers),
            'invitedExtensionOfficersCount' => count($invitedExtensionOfficers),
        ];
    }

    /**
     * Get field worker data (extension officer, vet, farm invited user).
     * They have access to specific farms they're assigned to.
     *
     * @param  int  $farmUserId  (This is User.roleId which points to FarmUser.id)
     */
    /**
     * Data for a veterinarian: farms, livestock and logs of the service requests
     * they are currently assigned to (plus 30 days of completed jobs).
     */
    private function getVetData(\App\Models\User $user): array
    {
        $farmUuids = \App\Services\VetServices\VetServiceRequestService::farmUuidsForVetUser($user);
        $vet = \App\Models\Vet::where('userId', $user->id)->first();

        $farms = ! empty($farmUuids) ? $this->farmController->fetchByUuids($farmUuids) : [];
        $livestock = ! empty($farmUuids) ? $this->livestockController->fetchByFarmUuids($farmUuids) : [];
        $livestockUuids = array_column($livestock, 'uuid');
        $logs = ! empty($farmUuids) ? $this->logController->fetchLogsByFarmLivestockUuids($farmUuids, $livestockUuids) : [];
        $vaccines = ! empty($farmUuids) ? $this->vaccineController->fetchByFarmUuids($farmUuids) : [];

        return [
            'type' => 'field_worker',
            'farmUser' => null,
            'vet' => $vet ? \App\Http\Controllers\VetServices\VetProfileController::privateProfileFor($vet) : null,
            'farms' => $farms,
            'livestock' => $livestock,
            'logs' => $logs,
            'vaccines' => $vaccines,
            'farmsCount' => count($farms),
            'livestockCount' => count($livestock),
            'logsCount' => is_array($logs) ? count($logs) : 0,
            'vaccinesCount' => count($vaccines),
        ];
    }

    private function getFieldWorkerData(int $farmUserId): array
    {
        try {
            // Determine caller role to route logic correctly
            $authenticated = auth()->user();
            $roleNormalized = is_object($authenticated) && isset($authenticated->role)
                ? strtolower(str_replace([' ', '_', '-'], '', trim((string) $authenticated->role)))
                : '';

            // Veterinarians sync the farms attached to their active service requests
            if ($roleNormalized === 'vet' && is_object($authenticated)) {
                return $this->getVetData($authenticated);
            }

            // Auction officers work from the auction module; they hold no farm data
            if ($roleNormalized === 'auctionofficer' && is_object($authenticated)) {
                $officer = \App\Models\AuctionOfficer::where('userId', $authenticated->id)->first();

                return [
                    'type' => 'field_worker',
                    'farmUser' => null,
                    'auctionOfficer' => $officer?->toWire(true),
                    'farms' => [],
                    'livestock' => [],
                    'logs' => [],
                    'vaccines' => [],
                    'farmsCount' => 0,
                    'livestockCount' => 0,
                    'logsCount' => 0,
                    'vaccinesCount' => 0,
                ];
            }

            // If caller is an Extension Officer, handle EO path regardless of FarmUser presence
            if (in_array($roleNormalized, ['extensionofficer'])) {
                \Log::info("Routing field worker sync via ExtensionOfficer branch for roleId={$farmUserId}");

                $extensionOfficer = ExtensionOfficer::find($farmUserId);

                if ($extensionOfficer) {
                    \Log::info("Handling field worker sync as ExtensionOfficer ID: {$extensionOfficer->id}");

                    // Read selection hints from request (provided by mobile app)
                    $req = request();
                    $farmerIdParam = $req->query('farmerId') ?: $req->query('farmer_id') ?: $req->header('X-ExtensionOfficer-Farmer-Id');
                    $inviteIdParam = $req->query('inviteId') ?: $req->query('invite_id') ?: $req->header('X-ExtensionOfficer-Invite-Id');
                    $accessCodeParam = $req->query('access_code') ?: $req->query('accessCode') ?: $req->query('access-code') ?: $req->header('X-ExtensionOfficer-Access-Code');

                    \Log::info('EO splash params: farmerId='.($farmerIdParam ?: 'null').', inviteId='.($inviteIdParam ?: 'null').', accessCodePresent='.(! empty($accessCodeParam) ? 'yes' : 'no'));

                    $selectedFarmerId = null;
                    $invite = null;

                    // If farmerId was explicitly provided, prefer it; try to resolve invite for context but do not block sync
                    if ($farmerIdParam) {
                        $selectedFarmerId = (int) $farmerIdParam;

                        $inviteQuery = ExtensionOfficerFarmInvite::where('extensionOfficerId', $extensionOfficer->id)
                            ->where('farmerId', $selectedFarmerId);
                        if ($inviteIdParam) {
                            $inviteQuery->where('id', (int) $inviteIdParam);
                        }
                        if ($accessCodeParam) {
                            $inviteQuery->where('access_code', $accessCodeParam);
                        }
                        $invite = $inviteQuery->orderByDesc('updated_at')->first();
                    } else {
                        // No explicit farmerId - resolve via invite selection hints
                        $inviteQueryAll = ExtensionOfficerFarmInvite::where('extensionOfficerId', $extensionOfficer->id);
                        if ($inviteIdParam) {
                            $inviteQueryAll->where('id', (int) $inviteIdParam);
                        }
                        if ($accessCodeParam) {
                            $inviteQueryAll->where('access_code', $accessCodeParam);
                        }

                        // Resolve any matching invite (no status column in table)
                        $invite = $inviteQueryAll->orderByDesc('updated_at')->first();

                        if ($invite) {
                            $selectedFarmerId = (int) $invite->farmerId;
                        }
                    }

                    if (! $selectedFarmerId || ! $invite) {
                        \Log::warning("EO sync: No farmerId could be resolved for ExtensionOfficer ID {$extensionOfficer->id}");

                        return [
                            'type' => 'field_worker',
                            'farmUser' => null,
                            'farms' => [],
                            'livestock' => [],
                            'logs' => [],
                            'vaccines' => [],
                            'farmsCount' => 0,
                            'livestockCount' => 0,
                            'vaccinesCount' => 0,
                        ];
                    }

                    \Log::info("ExtensionOfficer sync using farmerId={$selectedFarmerId}, inviteId=".($invite->id ?? 'null'));

                    // Fetch only data for the invited farmer
                    $farms = $this->farmController->fetchByFarmerId($selectedFarmerId);

                    $farmUuids = array_column($farms, 'uuid');

                    $livestock = ! empty($farmUuids)
                        ? $this->livestockController->fetchByFarmUuids($farmUuids)
                        : [];
                    $livestockUuids = array_column($livestock, 'uuid');

                    $logs = ! empty($farmUuids)
                        ? $this->logController->fetchLogsByFarmLivestockUuids($farmUuids, $livestockUuids)
                        : [];

                    $vaccines = ! empty($farmUuids)
                        ? $this->vaccineController->fetchByFarmUuids($farmUuids)
                        : [];

                    $bills = ! empty($farmUuids)
                        ? $this->billController->fetchByFarmUuids($farmUuids)
                        : [];
                    $financeExpenses = ! empty($farmUuids)
                        ? $this->fetchFinanceExpensesManualForFarmUuids($farmUuids)
                        : [];
                    $financeIncomes = ! empty($farmUuids)
                        ? $this->fetchFinanceIncomesForFarmUuids($farmUuids)
                        : [];

                    return [
                        'type' => 'field_worker',
                        'farmUser' => null,
                        'farms' => $farms,
                        'livestock' => $livestock,
                        'tombstones' => $this->livestockTombstonesForFarmUuids($farmUuids),
                        'logs' => $this->filterWorkerLogs($authenticated, $logs),
                        'vaccines' => array_values(array_filter($vaccines, fn ($vaccine) => app(FarmAccessService::class)->canModule($authenticated, $vaccine['farmUuid'], 'vaccination', 'view'))),
                        'bills' => $bills,
                        'financeExpenses' => $financeExpenses,
                        'financeIncomes' => $financeIncomes,
                        'farmsCount' => count($farms),
                        'livestockCount' => count($livestock),
                        'tombstonesCount' => count($this->livestockTombstonesForFarmUuids($farmUuids)),
                        'logsCount' => is_array($logs) ? count($logs) : 0,
                        'vaccinesCount' => count($vaccines),
                        'billsCount' => count($bills),
                        'financeExpensesCount' => count($financeExpenses),
                        'financeIncomesCount' => count($financeIncomes),
                        'selectedInvite' => [
                            'inviteId' => $invite->id ?? null,
                            'farmerId' => $selectedFarmerId,
                            'access_code' => $invite->access_code ?? ($accessCodeParam ?? null),
                        ],
                    ];
                }

                // EO not found - return empty structure
                return [
                    'type' => 'field_worker',
                    'farmUser' => null,
                    'farms' => [],
                    'livestock' => [],
                    'logs' => [],
                    'vaccines' => [],
                    'farmsCount' => 0,
                    'livestockCount' => 0,
                    'vaccinesCount' => 0,
                    'financeExpensesCount' => 0,
                    'financeIncomesCount' => 0,
                ];
            }

            // Step 1: Get FarmUser record (for farm invited users)
            $farmUser = FarmUser::find($farmUserId);

            if (! $farmUser) {
                \Log::warning("FarmUser not found for roleId: {$farmUserId}");

                // Attempt Extension Officer path when User.roleId points to extension_officers.id
                $extensionOfficer = ExtensionOfficer::find($farmUserId);

                if ($extensionOfficer) {
                    \Log::info("Handling field worker sync as ExtensionOfficer ID: {$extensionOfficer->id}");

                    // Read selection hints from request (provided by mobile app)
                    $req = request();
                    $farmerIdParam = $req->query('farmerId') ?: $req->query('farmer_id') ?: $req->header('X-ExtensionOfficer-Farmer-Id');
                    $inviteIdParam = $req->query('inviteId') ?: $req->query('invite_id') ?: $req->header('X-ExtensionOfficer-Invite-Id');
                    $accessCodeParam = $req->query('access_code') ?: $req->query('accessCode') ?: $req->query('access-code') ?: $req->header('X-ExtensionOfficer-Access-Code');

                    $selectedFarmerId = null;
                    $invite = null;

                    if ($farmerIdParam) {
                        $selectedFarmerId = (int) $farmerIdParam;

                        $inviteQuery = ExtensionOfficerFarmInvite::where('extensionOfficerId', $extensionOfficer->id)
                            ->where('farmerId', $selectedFarmerId);
                        if ($inviteIdParam) {
                            $inviteQuery->where('id', (int) $inviteIdParam);
                        }
                        if ($accessCodeParam) {
                            $inviteQuery->where('access_code', $accessCodeParam);
                        }
                        $invite = $inviteQuery->orderByDesc('updated_at')->first();
                    } else {
                        $inviteQueryAll = ExtensionOfficerFarmInvite::where('extensionOfficerId', $extensionOfficer->id);
                        if ($inviteIdParam) {
                            $inviteQueryAll->where('id', (int) $inviteIdParam);
                        }
                        if ($accessCodeParam) {
                            $inviteQueryAll->where('access_code', $accessCodeParam);
                        }

                        $invite = $inviteQueryAll->orderByDesc('updated_at')->first();
                        if ($invite) {
                            $selectedFarmerId = (int) $invite->farmerId;
                        }
                    }

                    if (! $selectedFarmerId) {
                        \Log::warning("EO sync: No farmerId could be resolved for ExtensionOfficer ID {$extensionOfficer->id}");

                        return [
                            'type' => 'field_worker',
                            'farmUser' => null,
                            'farms' => [],
                            'livestock' => [],
                            'logs' => [],
                            'vaccines' => [],
                            'farmsCount' => 0,
                            'livestockCount' => 0,
                            'vaccinesCount' => 0,
                        ];
                    }

                    \Log::info("ExtensionOfficer sync using farmerId={$selectedFarmerId}, inviteId=".($invite->id ?? 'null'));

                    // Fetch only data for the invited farmer
                    $farms = $this->farmController->fetchByFarmerId($selectedFarmerId);

                    $farmUuids = array_column($farms, 'uuid');

                    $livestock = ! empty($farmUuids)
                        ? $this->livestockController->fetchByFarmUuids($farmUuids)
                        : [];
                    $livestockUuids = array_column($livestock, 'uuid');

                    $logs = ! empty($farmUuids)
                        ? $this->logController->fetchLogsByFarmLivestockUuids($farmUuids, $livestockUuids)
                        : [];

                    $vaccines = ! empty($farmUuids)
                        ? $this->vaccineController->fetchByFarmUuids($farmUuids)
                        : [];

                    return [
                        'type' => 'field_worker',
                        'farmUser' => null,
                        'farms' => $farms,
                        'livestock' => $livestock,
                        'tombstones' => $this->livestockTombstonesForFarmUuids($farmUuids),
                        'logs' => $logs,
                        'vaccines' => $vaccines,
                        'farmsCount' => count($farms),
                        'livestockCount' => count($livestock),
                        'tombstonesCount' => count($this->livestockTombstonesForFarmUuids($farmUuids)),
                        'logsCount' => is_array($logs) ? count($logs) : 0,
                        'vaccinesCount' => count($vaccines),
                        'selectedInvite' => [
                            'inviteId' => $invite->id ?? null,
                            'farmerId' => $selectedFarmerId,
                            'access_code' => $invite->access_code ?? ($accessCodeParam ?? null),
                            'status' => $invite->status ?? null,
                        ],
                    ];
                }

                return [
                    'type' => 'field_worker',
                    'farmUser' => null,
                    'farms' => [],
                    'livestock' => [],
                    'logs' => [],
                    'vaccines' => [],
                    'farmsCount' => 0,
                    'livestockCount' => 0,
                    'vaccinesCount' => 0,
                    'financeExpensesCount' => 0,
                    'financeIncomesCount' => 0,
                ];
            }

            // Step 2: Get assigned farm UUIDs (supports multiple farms)
            $farmUuids = app(FarmAccessService::class)->accessibleFarmUuids($authenticated) ?? [];

            \Log::info("Fetching data for FarmUser ID: {$farmUserId}, FarmUuids: ".json_encode($farmUuids).", RoleTitle: {$farmUser->roleTitle}");

            if (empty($farmUuids)) {
                \Log::warning("FarmUser {$farmUserId} has no assigned farmUuids");

                return [
                    'type' => 'field_worker',
                    'farmUser' => [
                        'uuid' => $farmUser->uuid,
                        'farmUuid' => null,
                        'farmUuids' => [],
                        'roleTitle' => $farmUser->roleTitle,
                        'firstName' => $farmUser->firstName,
                        'lastName' => $farmUser->lastName,
                        'email' => $farmUser->email,
                    ],
                    'farms' => [],
                    'livestock' => [],
                    'logs' => [],
                    'vaccines' => [],
                    'farmsCount' => 0,
                    'livestockCount' => 0,
                    'vaccinesCount' => 0,
                ];
            }

            // Step 3: Get farm details for all assigned farms
            $farms = $this->farmController->fetchByUuids($farmUuids);

            // Step 4: Get livestock for all assigned farms
            $livestock = [];
            if (! empty($farmUuids)) {
                $livestock = $this->livestockController->fetchByFarmUuids($farmUuids);
            }

            // Step 5: Get logs for assigned livestock/farms
            $logs = [];
            $livestockUuids = array_column($livestock, 'uuid');
            if (! empty($farmUuids)) {
                // Fetch logs - transfers will be fetched even if livestockUuids is empty
                // (since transferred livestock may no longer be in the source farm)
                $logs = $this->logController->fetchLogsByFarmLivestockUuids(
                    $farmUuids,
                    $livestockUuids
                );
                \Log::info('Field worker sync: Fetched logs - vaccinations: '.(isset($logs['vaccinations']) ? count($logs['vaccinations']) : 0).', transfers: '.(isset($logs['transfers']) ? count($logs['transfers']) : 0));
            } else {
                \Log::warning('Field worker sync: Cannot fetch logs - farmUuids: '.json_encode($farmUuids).', livestockUuids: '.json_encode($livestockUuids));
            }

            // Step 6: Get vaccines for all assigned farms
            $vaccines = [];
            if (! empty($farmUuids)) {
                $vaccines = $this->vaccineController->fetchByFarmUuids($farmUuids);
            }

            \Log::info('FarmUser sync data fetched - Farms: '.count($farms).
                      ', Livestock: '.count($livestock).
                      ', Logs: '.(is_array($logs) ? count($logs) : 0).
                      ', Vaccines: '.count($vaccines));

            return [
                'type' => 'field_worker',
                'farmUser' => [
                    'uuid' => $farmUser->uuid,
                    'farmUuid' => $farmUuids[0] ?? null, // Legacy single farm support
                    'farmUuids' => $farmUuids, // Multiple farms array
                    'roleTitle' => $farmUser->roleTitle,
                    'firstName' => $farmUser->firstName,
                    'middleName' => $farmUser->middleName,
                    'lastName' => $farmUser->lastName,
                    'phone' => $farmUser->phone,
                    'email' => $farmUser->email,
                    'gender' => $farmUser->gender,
                    'createdAt' => $farmUser->created_at?->toIso8601String(),
                    'updatedAt' => $farmUser->updated_at?->toIso8601String(),
                ],
                'farms' => $farms,
                'livestock' => $livestock,
                'tombstones' => $this->livestockTombstonesForFarmUuids($farmUuids),
                'logs' => $logs,
                'vaccines' => $vaccines,
                'farmsCount' => count($farms),
                'livestockCount' => count($livestock),
                'tombstonesCount' => count($this->livestockTombstonesForFarmUuids($farmUuids)),
                'logsCount' => is_array($logs) ? count($logs) : 0,
                'vaccinesCount' => count($vaccines),
            ];
        } catch (\Exception $e) {
            \Log::error("Error fetching field worker data for roleId {$farmUserId}: ".$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'type' => 'field_worker',
                'farmUser' => null,
                'farms' => [],
                'livestock' => [],
                'logs' => [],
                'vaccines' => [],
                'farmsCount' => 0,
                'livestockCount' => 0,
                'vaccinesCount' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Return only active deletion markers for livestock in farms visible to the user.
     * Restored records supersede their tombstone and return through the normal upsert list.
     */
    private function livestockTombstonesForFarmUuids(array $farmUuids): array
    {
        if ($farmUuids === []) {
            return [];
        }

        return SyncTombstone::query()
            ->where('entity_type', 'livestock')
            ->where('operation', 'deleted')
            ->whereNull('superseded_at')
            ->whereIn('farm_uuid', $farmUuids)
            ->orderBy('id')
            ->get()
            ->map(static fn (SyncTombstone $tombstone): array => [
                'uuid' => $tombstone->uuid,
                'entityType' => $tombstone->entity_type,
                'entityUuid' => $tombstone->entity_uuid,
                'farmUuid' => $tombstone->farm_uuid,
                'revision' => $tombstone->entity_revision,
                'operation' => $tombstone->operation,
                'occurredAt' => $tombstone->occurred_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Get system user data (admins can see everything).
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
                'error' => $e->getMessage(),
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
     */
    public function postSync(Request $request, int $userId): JsonResponse
    {
        try {
            \Log::info('========== POST SYNC START ==========');
            \Log::info("Request User ID: {$userId}");

            // Get authenticated user from request
            $authenticatedUser = $request->user();

            if (! $authenticatedUser) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            \Log::info("Authenticated User ID: {$authenticatedUser->id}");
            \Log::info("Authenticated User Role: {$authenticatedUser->role}");
            \Log::info("Authenticated User Role ID: {$authenticatedUser->roleId}");

            // Validate that the authenticated user matches the requested userId
            if ($authenticatedUser->id !== $userId) {
                \Log::warning('Unauthorized sync attempt', [
                    'authenticatedUserId' => $authenticatedUser->id,
                    'requestedUserId' => $userId,
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized: You can only sync your own data',
                ], 403);
            }

            $user = User::find($userId);

            if (! $user) {
                \Log::error("User not found: {$userId}");

                return response()->json([
                    'status' => false,
                    'message' => 'User not found',
                ], 404);
            }

            // Get the payload
            $data = $request->all();

            // PDF V2 modules use separate admin APIs. Reject legacy Flutter payloads
            // so core sync never writes marketplace or dairy V2 records.
            foreach (['dairyMcc', 'dairyLogistics', 'marketplace'] as $section) {
                if (! array_key_exists($section, $data)) {
                    continue;
                }
                if (! is_array($data[$section])) {
                    return response()->json(['status' => false, 'message' => 'V2 synchronization is disabled.'], 422);
                }
                foreach ($data[$section] as $records) {
                    if (! is_array($records) || $records !== []) {
                        return response()->json(['status' => false, 'message' => 'V2 synchronization is disabled.'], 422);
                    }
                }
            }

            \Log::info('Payload received', [
                'farmsCount' => isset($data['farms']) ? count($data['farms']) : 0,
                'livestockCount' => isset($data['livestock']) ? count($data['livestock']) : 0,
            ]);

            // Track conflicts detected and resolved across all steps
            $conflictsResolved = [];

            // Initialize response data
            $syncedData = [
                'syncedFarms' => [],
                'syncedLivestock' => [],
                'syncedLogs' => [
                    'feedings' => [],
                    'weightChanges' => [],
                    'dewormings' => [],
                    'treatments' => [],
                    'vaccinations' => [],
                    'disposals' => [],
                    'birthEvents' => [],
                    'abortedPregnancies' => [],
                    'milkings' => [],
                    'pregnancies' => [],
                    'inseminations' => [],
                    'dryoffs' => [],
                    'transfers' => [],
                    'teethClippings' => [],
                    'tailDockings' => [],
                    'ironInjections' => [],
                    'livestockMarkings' => [],
                    'stageChanges' => [],
                    'prepuceConditions' => [],
                ],
                'syncedVaccines' => [],
                'syncedBills' => [],
                'syncedFinanceExpenses' => [],
                'syncedFinanceIncomes' => [],
                'syncedFarmUsers' => [],
                'syncedInvitedExtensionOfficers' => [],
                'invitedExtensionOfficers' => [],
                'invitedExtensionOfficersCount' => 0,
                // Add more collections as they're implemented
            ];

            $syncedData['syncedFarms'] = isset($data['farms']) && is_array($data['farms'])
                ? $this->processFarmSync($data['farms'], $user, $userId)
                : [];

            $syncedData['syncedLivestock'] = isset($data['livestock']) && is_array($data['livestock'])
                ? $this->processLivestockSync($data['livestock'], $user, $userId)
                : [];

            // Process vaccines BEFORE logs to ensure vaccines exist when vaccination logs reference them
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

            $syncedData['syncedLogs']['treatments'] = $this->processLogSync(
                $logsPayload['treatments'] ?? [],
                $user,
                $userId,
                'treatment',
                fn (array $collection, string $livestockUuid) => $this->treatmentController->processTreatments($collection, $livestockUuid)
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

            $syncedData['syncedLogs']['birthEvents'] = $this->processLogSync(
                $logsPayload['birthEvents'] ?? [],
                $user,
                $userId,
                'birth event',
                fn (array $collection, string $livestockUuid) => $this->birthEventController->processBirthEvents($collection, $livestockUuid)
            );

            $syncedData['syncedLogs']['abortedPregnancies'] = $this->processLogSync(
                $logsPayload['abortedPregnancies'] ?? [],
                $user,
                $userId,
                'aborted pregnancy',
                fn (array $collection, string $livestockUuid) => $this->abortedPregnancyController->processAbortedPregnancies($collection, $livestockUuid)
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

            $syncedData['syncedLogs']['inseminations'] = $this->processLogSync(
                $logsPayload['inseminations'] ?? [],
                $user,
                $userId,
                'insemination',
                fn (array $collection, string $livestockUuid) => $this->inseminationController->processInseminations($collection, $livestockUuid)
            );

            $syncedData['syncedLogs']['dryoffs'] = $this->processLogSync(
                $logsPayload['dryoffs'] ?? [],
                $user,
                $userId,
                'dryoff',
                fn (array $collection, string $livestockUuid) => $this->dryoffController->processDryoffs($collection, $livestockUuid)
            );

            $syncedData['syncedLogs']['transfers'] = $this->processLogSync(
                $logsPayload['transfers'] ?? [],
                $user,
                $userId,
                'transfer',
                fn (array $collection, string $livestockUuid) => $this->transferController->processTransfers($collection, $livestockUuid)
            );

            $syncedData['syncedLogs']['teethClippings'] = $this->processLogSync(
                $logsPayload['teethClippings'] ?? [],
                $user,
                $userId,
                'teeth clipping',
                fn (array $collection, string $livestockUuid) => $this->teethClippingController->processTeethClippings($collection, $livestockUuid)
            );

            $syncedData['syncedLogs']['tailDockings'] = $this->processLogSync(
                $logsPayload['tailDockings'] ?? [],
                $user,
                $userId,
                'tail docking',
                fn (array $collection, string $livestockUuid) => $this->tailDockingController->processTailDockings($collection, $livestockUuid)
            );

            $syncedData['syncedLogs']['ironInjections'] = $this->processLogSync(
                $logsPayload['ironInjections'] ?? [],
                $user,
                $userId,
                'iron injection',
                fn (array $collection, string $livestockUuid) => $this->ironInjectionController->processIronInjections($collection, $livestockUuid)
            );

            $syncedData['syncedLogs']['livestockMarkings'] = $this->processLogSync(
                $logsPayload['livestockMarkings'] ?? [],
                $user,
                $userId,
                'livestock marking',
                fn (array $collection, string $livestockUuid) => $this->livestockMarkingController->processLivestockMarkings($collection, $livestockUuid)
            );

            $syncedData['syncedLogs']['stageChanges'] = $this->processLogSync(
                $logsPayload['stageChanges'] ?? [],
                $user,
                $userId,
                'stage change',
                fn (array $collection, string $livestockUuid) => $this->stageChangeController->processStageChanges($collection, $livestockUuid)
            );

            $syncedData['syncedLogs']['prepuceConditions'] = $this->processLogSync(
                $logsPayload['prepuceConditions'] ?? [],
                $user,
                $userId,
                'prepuce condition',
                fn (array $collection, string $livestockUuid) => $this->prepuceConditionController->processPrepuceConditions($collection, $livestockUuid)
            );

            // Process farm users
            $syncedData['syncedFarmUsers'] = isset($data['farmUsers']) && is_array($data['farmUsers'])
                ? $this->processFarmUserSync($data['farmUsers'], $user, $userId)
                : [];

            // Process invited extension officers
            $syncedData['syncedInvitedExtensionOfficers'] = isset($data['invitedExtensionOfficers']) && is_array($data['invitedExtensionOfficers'])
                ? $this->processInvitedExtensionOfficerSync($data['invitedExtensionOfficers'], $user, $userId)
                : [];

            // Process bills LAST and only for extension officers
            $syncedData['syncedBills'] = isset($data['bills']) && is_array($data['bills'])
                ? $this->processBillSync($data['bills'], $user, $userId)
                : [];

            $syncedData['syncedFinanceExpenses'] = isset($data['financeExpenses']) && is_array($data['financeExpenses'])
                ? $this->processFinanceExpenseSync($data['financeExpenses'], $user, $userId)
                : [];

            $syncedData['syncedFinanceIncomes'] = isset($data['financeIncomes']) && is_array($data['financeIncomes'])
                ? $this->processFinanceIncomeSync($data['financeIncomes'], $user, $userId)
                : [];

            // TODO: Process other collections (feeds, etc.)
            // Follow the same pattern:
            // 1. Check user role
            // 2. Get appropriate roleId (farmerId, vetId, etc.)
            // 3. Pass to controller's process method

            \Log::info('Sync summary', [
                'syncedFarmsCount' => count($syncedData['syncedFarms']),
                'syncedLivestockCount' => count($syncedData['syncedLivestock']),
                'syncedFeedingsCount' => isset($syncedData['syncedLogs']['feedings'])
                    ? count($syncedData['syncedLogs']['feedings'])
                    : 0,
                'syncedWeightChangesCount' => isset($syncedData['syncedLogs']['weightChanges'])
                    ? count($syncedData['syncedLogs']['weightChanges'])
                    : 0,
                'syncedDewormingsCount' => isset($syncedData['syncedLogs']['dewormings'])
                    ? count($syncedData['syncedLogs']['dewormings'])
                    : 0,
                'syncedTreatmentsCount' => isset($syncedData['syncedLogs']['treatments'])
                    ? count($syncedData['syncedLogs']['treatments'])
                    : 0,
                'syncedVaccinationsCount' => isset($syncedData['syncedLogs']['vaccinations'])
                    ? count($syncedData['syncedLogs']['vaccinations'])
                    : 0,
                'syncedDisposalsCount' => isset($syncedData['syncedLogs']['disposals'])
                    ? count($syncedData['syncedLogs']['disposals'])
                    : 0,
                'syncedBirthEventsCount' => isset($syncedData['syncedLogs']['birthEvents'])
                    ? count($syncedData['syncedLogs']['birthEvents'])
                    : 0,
                'syncedAbortedPregnanciesCount' => isset($syncedData['syncedLogs']['abortedPregnancies'])
                    ? count($syncedData['syncedLogs']['abortedPregnancies'])
                    : 0,
                'syncedMilkingsCount' => isset($syncedData['syncedLogs']['milkings'])
                    ? count($syncedData['syncedLogs']['milkings'])
                    : 0,
                'syncedPregnanciesCount' => isset($syncedData['syncedLogs']['pregnancies'])
                    ? count($syncedData['syncedLogs']['pregnancies'])
                    : 0,
                'syncedInseminationsCount' => isset($syncedData['syncedLogs']['inseminations'])
                    ? count($syncedData['syncedLogs']['inseminations'])
                    : 0,
                'syncedDryoffsCount' => isset($syncedData['syncedLogs']['dryoffs'])
                    ? count($syncedData['syncedLogs']['dryoffs'])
                    : 0,
                'syncedTransfersCount' => isset($syncedData['syncedLogs']['transfers'])
                    ? count($syncedData['syncedLogs']['transfers'])
                    : 0,
                'syncedTeethClippingsCount' => isset($syncedData['syncedLogs']['teethClippings'])
                    ? count($syncedData['syncedLogs']['teethClippings'])
                    : 0,
                'syncedTailDockingsCount' => isset($syncedData['syncedLogs']['tailDockings'])
                    ? count($syncedData['syncedLogs']['tailDockings'])
                    : 0,
                'syncedIronInjectionsCount' => isset($syncedData['syncedLogs']['ironInjections'])
                    ? count($syncedData['syncedLogs']['ironInjections'])
                    : 0,
                'syncedLivestockMarkingsCount' => isset($syncedData['syncedLogs']['livestockMarkings'])
                    ? count($syncedData['syncedLogs']['livestockMarkings'])
                    : 0,
                'syncedStageChangesCount' => isset($syncedData['syncedLogs']['stageChanges'])
                    ? count($syncedData['syncedLogs']['stageChanges'])
                    : 0,
                'syncedPrepuceConditionsCount' => isset($syncedData['syncedLogs']['prepuceConditions'])
                    ? count($syncedData['syncedLogs']['prepuceConditions'])
                    : 0,
                'syncedVaccinesCount' => isset($syncedData['syncedVaccines'])
                    ? count($syncedData['syncedVaccines'])
                    : 0,
                'syncedFarmUsersCount' => isset($syncedData['syncedFarmUsers'])
                    ? count($syncedData['syncedFarmUsers'])
                    : 0,
                'syncedInvitedExtensionOfficersCount' => isset($syncedData['syncedInvitedExtensionOfficers'])
                    ? count($syncedData['syncedInvitedExtensionOfficers'])
                    : 0,
                'syncedFinanceIncomesCount' => isset($syncedData['syncedFinanceIncomes'])
                    ? count($syncedData['syncedFinanceIncomes'])
                    : 0,
            ]);
            \Log::info('========== POST SYNC END ==========');
            // Include invited extension officers for farmer so client can upsert them
            try {
                if (strtolower($user->role) === 'farmer') {
                    $farmerIdForInvites = $user->roleId;
                    $syncedData['invitedExtensionOfficers'] = $this->extensionOfficerFarmInviteController->fetchByFarmerId($farmerIdForInvites);
                    $syncedData['invitedExtensionOfficersCount'] = count($syncedData['invitedExtensionOfficers']);
                } else {
                    $syncedData['invitedExtensionOfficers'] = [];
                    $syncedData['invitedExtensionOfficersCount'] = 0;
                }
            } catch (\Exception $e) {
                \Log::error('Failed to fetch invited extension officers for postSync: '.$e->getMessage());
                $syncedData['invitedExtensionOfficers'] = [];
                $syncedData['invitedExtensionOfficersCount'] = 0;
            }

            return response()->json([
                'status' => true,
                'message' => 'Post sync completed successfully with step-by-step conflict resolution',
                'step_by_step_execution' => [
                    'step_1_farms',
                    'step_2_livestock',
                    'step_3_vaccines',
                    'step_4_logs',
                    'step_5_bills',
                    'step_6_finance_expenses',
                    'step_7_finance_incomes',
                    'step_8_farm_users',
                    'step_9_invited_extension_officers',
                ],
                'data' => $syncedData,
                'conflicts_count' => count($conflictsResolved),
                'conflicts_resolved' => $conflictsResolved,
                'timestamp' => now()->toIso8601String(),
            ], 200);

        } catch (\Exception $e) {
            \Log::error('========== POST SYNC ERROR ==========');
            \Log::error('Error: '.$e->getMessage());
            \Log::error('Trace: '.$e->getTraceAsString());

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
            \Log::info('No farms provided for sync.');

            return [];
        }

        $farmerIds = app(FarmOwnership::class)->farmerIds($user);
        if (count($farmerIds) !== 1) {
            return [];
        }
        $farmerId = $farmerIds[0];
        $farms = array_values(array_filter($farms, function ($entry) use ($farmerId): bool {
            $uuid = $entry['uuid'] ?? null;
            if (! is_string($uuid) || $uuid === '') {
                return false;
            }
            $existing = Farm::where('uuid', $uuid)->first();

            return ! $existing || (int) $existing->farmerId === (int) $farmerId;
        }));
        \Log::info("Processing farms for User ID: {$userId}, Farmer ID: {$farmerId}, Role: {$user->role}");

        $syncedFarms = $this->farmController->processFarms($farms, $farmerId);
        \Log::info("Farm sync complete for user {$userId}", ['count' => count($syncedFarms)]);

        return $syncedFarms;
    }

    private function processLivestockSync(array $livestock, User $user, int $userId): array
    {
        if (empty($livestock)) {
            \Log::info('No livestock provided for sync.');

            return [];
        }

        if (in_array($user->role, [UserRole::FARMER, UserRole::FARM_INVITED_USER, 'appUser'], true)) {
            $access = app(FarmAccessService::class);
            $livestock = array_values(array_filter($livestock, function ($entry) use ($user, $access): bool {
                $destination = $entry['farmUuid'] ?? null;
                $existing = isset($entry['uuid']) ? Livestock::where('uuid', $entry['uuid'])->first() : null;

                return is_string($destination) && $access->can($user, $destination, 'livestock.manage')
                    && (! $existing || $access->can($user, $existing->farmUuid, 'livestock.manage'));
            }));
            $groups = [];
            foreach ($livestock as $entry) {
                $ownerId = Farm::where('uuid', $entry['farmUuid'])->value('farmerId');
                if ($ownerId) {
                    $groups[$ownerId][] = $entry;
                }
            }
            $synced = [];
            foreach ($groups as $ownerId => $entries) {
                $synced = array_merge($synced, $this->livestockController->processLivestock($entries, (int) $ownerId, $user));
            }

            return $synced;
        }

        // For farm invited users, validate access and get farmer ID from livestock
        $assignedFarmUuids = $this->getAssignedFarmUuidsForUser($user);

        if (empty($assignedFarmUuids)) {
            \Log::warning('User has no assigned farms', [
                'userId' => $userId,
                'role' => $user->role,
            ]);

            return [];
        }

        // Validate access: Filter livestock to only those in assigned farms
        $livestock = $this->validateLivestockAccess($livestock, $assignedFarmUuids, $userId);

        if (empty($livestock)) {
            \Log::info("No livestock passed access validation for user {$userId}");

            return [];
        }

        // Get farmer ID from the livestock's farm
        $farmerId = $this->getFarmerIdForLivestock($livestock, $user);

        if (! $farmerId) {
            \Log::warning('Could not determine farmer ID for livestock sync', [
                'userId' => $userId,
                'role' => $user->role,
            ]);

            return [];
        }

        \Log::info("Processing livestock for User ID: {$userId}, Farmer ID: {$farmerId}, Role: {$user->role}");

        $syncedLivestock = $this->livestockController->processLivestock($livestock, $farmerId, $user);
        \Log::info("Livestock sync complete for user {$userId}", ['count' => count($syncedLivestock)]);

        return $syncedLivestock;
    }

    /**
     * @param  callable  $processor  function(array $logGroup, string $livestockUuid): array
     */
    private function logModule(string $label): ?string
    {
        return match (strtolower($label)) {
            'feeding', 'feedings' => 'feeding', 'milking', 'milkings' => 'milking',
            'weightchange', 'weightchanges', 'weight change' => 'weight-change',
            'deworming', 'dewormings' => 'deworming', 'treatment', 'treatments' => 'medication',
            'vaccination', 'vaccinations' => 'vaccination', 'disposal', 'disposals' => 'disposal',
            'birthevent', 'birthevents', 'birth event' => 'birth-event',
            'abortedpregnancy', 'abortedpregnancies', 'aborted pregnancy' => 'aborted-pregnancy',
            'dryoff', 'dryoffs' => 'dryoff', 'insemination', 'inseminations' => 'insemination',
            'pregnancy', 'pregnancies' => 'pregnancy', 'transfer', 'transfers' => 'transfer',
            'teeth clipping', 'teethclippings' => 'teeth-clipping', 'tail docking', 'taildockings' => 'tail-docking',
            'iron injection', 'ironinjections' => 'iron-injection', 'livestock marking', 'livestockmarkings' => 'livestock-marking',
            'stage change', 'stagechanges' => 'stage-change', 'prepuce condition', 'prepuceconditions' => 'prepuce-condition',
            default => null,
        };
    }

    private function filterWorkerLogs(User $user, array $logs): array
    {
        $access = app(FarmAccessService::class);
        foreach ($logs as $label => $rows) {
            $logs[$label] = array_values(array_filter($rows, function ($row) use ($user, $label, $access) {
                $farm = $row['farmUuid'] ?? Livestock::where('uuid', $row['livestockUuid'] ?? '')->value('farmUuid');

                return $farm && $access->canModule($user, $farm, $this->logModule($label), 'view');
            }));
        }

        return $logs;
    }

    private function processLogSync(array $logs, User $user, int $userId, string $logLabel, callable $processor): array
    {
        if (empty($logs)) {
            \Log::info("No {$logLabel} logs provided for sync.");

            return [];
        }

        // Get assigned farm UUIDs for access validation
        $assignedFarmUuids = in_array($user->role, [UserRole::FARMER, UserRole::FARM_INVITED_USER, 'appUser'], true)
            ? (app(FarmAccessService::class)->accessibleFarmUuids($user) ?? [])
            : $this->getAssignedFarmUuidsForUser($user);

        if (empty($assignedFarmUuids)) {
            \Log::warning('User has no assigned farms - cannot sync logs', [
                'userId' => $userId,
                'role' => $user->role,
            ]);

            return [];
        }

        \Log::info(strtoupper("========== PROCESSING {$logLabel}s START =========="));
        \Log::info("Total {$logLabel}s to process: ".count($logs));
        \Log::info("User Role: {$user->role}, User ID: {$userId}");

        $groupedLogs = [];
        foreach ($logs as $entry) {
            $livestockUuid = $entry['livestockUuid'] ?? null;
            if (! $livestockUuid) {
                \Log::warning(ucfirst($logLabel).' entry missing livestockUuid', ['entry' => $entry]);

                continue;
            }

            // Validate access for farm invited users
            if (! empty($assignedFarmUuids)) {
                if (! $this->validateLivestockBelongsToFarms($livestockUuid, $assignedFarmUuids)) {
                    \Log::warning("Log for livestock {$livestockUuid} rejected - not in assigned farms", [
                        'userId' => $userId,
                        'assignedFarms' => $assignedFarmUuids,
                    ]);

                    continue;
                }
            }

            if (in_array($user->role, [UserRole::FARMER, UserRole::FARM_INVITED_USER, 'appUser'], true) || DB::table('farm_access_grants')->where('user_id', $user->id)->exists()) {
                $farmUuid = Livestock::where('uuid', $livestockUuid)->value('farmUuid');
                if (! $farmUuid || ! app(FarmAccessService::class)->canModule($user, $farmUuid, $this->logModule($logLabel), 'create')) {
                    continue;
                }
                if (($entry['syncAction'] ?? '') === 'deleted' && ! app(FarmAccessService::class)->can($user, $farmUuid, 'events.void')) {
                    continue;
                }
                $model = match ($this->logModule($logLabel)) {
                    'milking' => Milking::class, 'feeding' => Feeding::class,
                    'weight-change' => WeightChange::class, 'deworming' => Deworming::class,
                    'medication' => Treatment::class, 'vaccination' => Vaccination::class,
                    'disposal' => Disposal::class, 'birth-event' => BirthEvent::class,
                    'aborted-pregnancy' => AbortedPregnancy::class, 'dryoff' => Dryoff::class,
                    'insemination' => Insemination::class, 'pregnancy' => Pregnancy::class,
                    'transfer' => Transfer::class,
                    'teeth-clipping' => TeethClipping::class, 'tail-docking' => TailDocking::class,
                    'iron-injection' => IronInjection::class, 'livestock-marking' => LivestockMarking::class,
                    'stage-change' => StageChange::class, 'prepuce-condition' => PrepuceCondition::class, default => null,
                };
                if ($model && isset($entry['uuid'])) {
                    $existing = $model::where('uuid', $entry['uuid'])->first();
                    if ($existing && $existing->livestockUuid !== $livestockUuid) {
                        continue;
                    }
                }
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
        \Log::info("Total {$logLabel}s synced: ".count($synced));

        return $synced;
    }

    private function processVaccineSync(array $vaccines, User $user, int $userId): array
    {
        if (empty($vaccines)) {
            \Log::info('No vaccines provided for sync.');

            return [];
        }

        if (in_array($user->role, [UserRole::FARMER, UserRole::FARM_INVITED_USER, 'appUser'], true)) {
            $access = app(FarmAccessService::class);
            $groups = [];
            foreach ($vaccines as $entry) {
                $farmUuid = $entry['farmUuid'] ?? null;
                $existing = isset($entry['uuid']) ? Vaccine::where('uuid', $entry['uuid'])->first() : null;
                if (! is_string($farmUuid) || ! $access->canModule($user, $farmUuid, 'vaccination', 'create')
                    || ($existing && ! $access->canModule($user, $existing->farmUuid, 'vaccination', 'create'))) {
                    continue;
                }
                $ownerId = Farm::where('uuid', $farmUuid)->value('farmerId');
                if ($ownerId) {
                    $groups[$ownerId][] = $entry;
                }
            }
            $synced = [];
            foreach ($groups as $ownerId => $entries) {
                $synced = array_merge($synced, $this->vaccineController->processVaccines($entries, (int) $ownerId));
            }

            return $synced;
        }

        // Get assigned farm UUIDs for access validation
        $assignedFarmUuids = $this->getAssignedFarmUuidsForUser($user);

        if (empty($assignedFarmUuids) && strtolower($user->role) !== 'farmer') {
            \Log::warning('User has no assigned farms - cannot sync vaccines', [
                'userId' => $userId,
                'role' => $user->role,
            ]);

            return [];
        }

        // Validate access: Filter vaccines to only those in assigned farms
        if (strtolower($user->role) !== 'farmer' && ! empty($assignedFarmUuids)) {
            $vaccines = $this->validateVaccineAccess($vaccines, $assignedFarmUuids, $userId);

            if (empty($vaccines)) {
                \Log::info("No vaccines passed access validation for user {$userId}");

                return [];
            }
        }

        // Get farmer ID for vaccine processing
        // For farmers: Use their roleId directly
        // For farm invited users: Extract from vaccines' farmUuid → Farm.farmerId
        $farmerId = null;
        if (strtolower($user->role) === 'farmer') {
            $farmerId = $user->roleId;
        } else {
            // Get farmer ID from the first vaccine's farm
            if (! empty($vaccines)) {
                $firstVaccine = $vaccines[0];
                $farmUuid = $firstVaccine['farmUuid'] ?? null;

                if ($farmUuid) {
                    $farm = Farm::where('uuid', $farmUuid)->first();
                    if ($farm) {
                        $farmerId = $farm->farmerId;
                    }
                }
            }
        }

        if (! $farmerId) {
            \Log::warning('Could not determine farmer ID for vaccine sync', [
                'userId' => $userId,
                'role' => $user->role,
            ]);

            return [];
        }

        \Log::info("Processing vaccines for User ID: {$userId}, Farmer ID: {$farmerId}, Role: {$user->role}");

        // Process vaccines using VaccineController
        $syncedVaccines = $this->vaccineController->processVaccines($vaccines, $farmerId);
        \Log::info("Vaccine sync complete for user {$userId}", ['count' => count($syncedVaccines)]);

        return $syncedVaccines;
    }

    private function processFarmUserSync(array $farmUsers, User $user, int $userId): array
    {
        if (empty($farmUsers)) {
            \Log::info('No farm users provided for sync.');

            return [];
        }

        if (strtolower($user->role) !== 'farmer') {
            \Log::warning('Non-farmer attempting to sync farm users', [
                'userId' => $userId,
                'role' => $user->role,
            ]);

            return [];
        }

        $farmerId = $user->roleId;
        \Log::info("Processing farm users for User ID: {$userId}, Farmer ID: {$farmerId}, Role: {$user->role}");

        $owned = Farm::where('farmerId', $farmerId)->pluck('uuid')->all();
        $farmUsers = array_values(array_filter($farmUsers, function ($entry) use ($owned): bool {
            $assigned = $entry['farmUuids'] ?? (empty($entry['farmUuid']) ? [] : [$entry['farmUuid']]);
            if (! is_array($assigned) || ! $assigned || array_diff($assigned, $owned)) {
                return false;
            }
            $existing = isset($entry['uuid']) ? FarmUser::where('uuid', $entry['uuid'])->first() : null;

            return ! $existing || ! array_diff($existing->getFarmUuidsArray(), $owned);
        }));

        // Pass User ID (for createdBy/updatedBy foreign key) and Farmer ID (for reference)
        $syncedFarmUsers = $this->farmUserController->processFarmUsers($farmUsers, $farmerId, $userId);
        \Log::info("Farm user sync complete for user {$userId}", ['count' => count($syncedFarmUsers)]);

        return $syncedFarmUsers;
    }

    private function processInvitedExtensionOfficerSync(array $invites, User $user, int $userId): array
    {
        if (empty($invites)) {
            return [];
        }

        if (strtolower($user->role) !== 'farmer') {
            \Log::warning('Non-farmer attempting to sync invited extension officers', [
                'userId' => $userId,
                'role' => $user->role,
            ]);

            return [];
        }

        $farmerId = $user->roleId;

        // Delegate to specific controller
        return $this->extensionOfficerFarmInviteController->processSync($invites, $farmerId);
    }

    private function processBillSync(array $bills, User $user, int $userId): array
    {
        if (empty($bills)) {
            \Log::info('No bills provided for sync.');

            return [];
        }

        // Only extension officers can sync bills
        $normalizedRole = strtolower(str_replace([' ', '_', '-'], '', $user->role ?? ''));
        if ($normalizedRole !== 'extensionofficer') {
            \Log::info("Skipping bill sync for non-extension officer role: {$user->role}", [
                'userId' => $userId,
            ]);

            return [];
        }

        $extensionOfficerId = $user->roleId;
        \Log::info("Processing bills for ExtensionOfficer ID: {$extensionOfficerId}, User ID: {$userId}");

        // Delegate to BillController which will resolve farmerId from farmUuid
        $syncedBills = $this->billController->processBills($bills, $extensionOfficerId);
        \Log::info("Bill sync complete for user {$userId}", ['count' => count($syncedBills)]);

        return $syncedBills;
    }

    /**
     * Pull manual finance expenses for the given farms (not bill-linked rows; those follow bills locally).
     *
     * @param  array<int, string>  $farmUuids
     * @return array<int, array<string, mixed>>
     */
    private function fetchFinanceExpensesManualForFarmUuids(array $farmUuids): array
    {
        if ($farmUuids === []) {
            return [];
        }

        return FinanceExpense::query()
            ->whereIn('farmUuid', $farmUuids)
            ->where('sourceType', 'manual')
            ->orderByDesc('expenseDate')
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (FinanceExpense $row): array => [
                'uuid' => $row->uuid,
                'sourceType' => $row->sourceType,
                'sourceUuid' => $row->sourceUuid,
                'farmUuid' => $row->farmUuid,
                'farmerId' => $row->farmerId,
                'billNo' => $row->billNo,
                'subjectType' => $row->subjectType,
                'quantity' => $row->quantity,
                'unitCost' => (string) $row->unitCost,
                'totalCost' => (string) $row->totalCost,
                'status' => $row->status,
                'notes' => $row->notes,
                'expenseDate' => $row->expenseDate?->toIso8601String(),
                'createdAt' => $row->created_at?->toIso8601String(),
                'updatedAt' => $row->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Pull finance income rows for the given farms.
     *
     * @param  array<int, string>  $farmUuids
     * @return array<int, array<string, mixed>>
     */
    private function fetchFinanceIncomesForFarmUuids(array $farmUuids): array
    {
        if ($farmUuids === []) {
            return [];
        }

        return FinanceIncome::query()
            ->whereIn('farmUuid', $farmUuids)
            ->orderByDesc('incomeDate')
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (FinanceIncome $row): array => [
                'uuid' => $row->uuid,
                'sourceType' => $row->sourceType,
                'sourceUuid' => $row->sourceUuid,
                'farmUuid' => $row->farmUuid,
                'farmerId' => $row->farmerId,
                'referenceNo' => $row->referenceNo,
                'subjectType' => $row->subjectType,
                'quantity' => $row->quantity,
                'unitAmount' => (string) $row->unitAmount,
                'totalAmount' => (string) $row->totalAmount,
                'status' => $row->status,
                'notes' => $row->notes,
                'incomeDate' => $row->incomeDate?->toIso8601String(),
                'createdAt' => $row->created_at?->toIso8601String(),
                'updatedAt' => $row->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $expenses
     * @return array<int, array{uuid: string}>
     */
    private function processFinanceExpenseSync(array $expenses, User $user, int $userId): array
    {
        if ($expenses === []) {
            return [];
        }

        if ($user->role !== UserRole::FARMER && ! $user->isFarmInvitedUser() && $user->role !== 'appUser' && ! DB::table('farm_access_grants')->where('user_id', $user->id)->exists()) {
            \Log::info("Skipping finance expense sync for role {$user->role}", ['userId' => $userId]);

            return [];
        }

        $synced = [];

        foreach ($expenses as $data) {
            if (! is_array($data)) {
                continue;
            }

            $uuid = $data['uuid'] ?? null;
            if (! $uuid) {
                continue;
            }

            $syncAction = $data['syncAction'] ?? 'create';
            $sourceType = $data['sourceType'] ?? 'manual';

            if ($sourceType !== 'manual') {
                \Log::warning('Rejected finance expense: sourceType must be manual', ['uuid' => $uuid]);

                continue;
            }

            $farmUuid = $data['farmUuid'] ?? null;
            if (! $this->userCanWriteFinanceExpenseToFarm($user, $farmUuid)) {
                \Log::warning('Rejected finance expense: farm access denied', ['uuid' => $uuid, 'farmUuid' => $farmUuid]);

                continue;
            }

            $existing = FinanceExpense::query()->where('sourceType', 'manual')
                ->where(function ($q) use ($uuid): void {
                    $q->where('uuid', $uuid)->orWhere('sourceUuid', $uuid);
                })->first();
            if ($existing && $existing->farmUuid !== $farmUuid) {
                continue;
            }

            $farmerIdForRow = $this->resolveFarmerIdForFinanceExpenseFarm($farmUuid);
            if ($farmerIdForRow === null) {
                continue;
            }

            try {
                switch ($syncAction) {
                    case 'deleted':
                        FinanceExpense::query()
                            ->where('sourceType', 'manual')
                            ->where('farmUuid', $farmUuid)
                            ->where(function ($q) use ($uuid): void {
                                $q->where('uuid', $uuid)->orWhere('sourceUuid', $uuid);
                            })
                            ->delete();
                        $synced[] = ['uuid' => $uuid];
                        break;

                    case 'create':
                    case 'update':
                        $qty = max(1, (int) ($data['quantity'] ?? 1));
                        $total = (float) ($data['totalCost'] ?? 0);
                        if ($total <= 0) {
                            \Log::warning('Finance expense rejected: totalCost', ['uuid' => $uuid]);
                            break;
                        }
                        $unit = $qty > 0 ? $total / $qty : $total;
                        $status = strtolower((string) ($data['status'] ?? 'pending'));
                        if (! in_array($status, ['pending', 'paid'], true)) {
                            $status = 'pending';
                        }
                        $expenseDate = isset($data['expenseDate'])
                            ? Carbon::parse($data['expenseDate'])
                            : now();
                        FinanceExpense::updateOrCreate(
                            [
                                'sourceType' => 'manual',
                                'sourceUuid' => $uuid,
                            ],
                            [
                                'uuid' => $uuid,
                                'farmUuid' => $farmUuid,
                                'farmerId' => $farmerIdForRow,
                                'billNo' => $data['billNo'] ?? null,
                                'subjectType' => substr((string) ($data['subjectType'] ?? 'Other'), 0, 255),
                                'quantity' => $qty,
                                'unitCost' => number_format($unit, 2, '.', ''),
                                'totalCost' => number_format($total, 2, '.', ''),
                                'status' => $status,
                                'notes' => $data['notes'] ?? null,
                                'expenseDate' => $expenseDate,
                            ]
                        );
                        $synced[] = ['uuid' => $uuid];
                        break;

                    default:
                        \Log::warning('Unknown finance expense syncAction', ['uuid' => $uuid, 'syncAction' => $syncAction]);
                }
            } catch (\Throwable $e) {
                \Log::error('Finance expense sync error', ['uuid' => $uuid, 'error' => $e->getMessage()]);
            }
        }

        return $synced;
    }

    /**
     * @param  array<int, array<string, mixed>>  $incomes
     * @return array<int, array{uuid: string}>
     */
    private function processFinanceIncomeSync(array $incomes, User $user, int $userId): array
    {
        if ($incomes === []) {
            return [];
        }

        if ($user->role !== UserRole::FARMER && ! $user->isFarmInvitedUser() && $user->role !== 'appUser' && ! DB::table('farm_access_grants')->where('user_id', $user->id)->exists()) {
            \Log::info("Skipping finance income sync for role {$user->role}", ['userId' => $userId]);

            return [];
        }

        $synced = [];

        foreach ($incomes as $data) {
            if (! is_array($data)) {
                continue;
            }

            $uuid = $data['uuid'] ?? null;
            if (! $uuid) {
                continue;
            }

            $syncAction = $data['syncAction'] ?? 'create';
            $sourceType = isset($data['sourceType']) && $data['sourceType'] !== ''
                ? (string) $data['sourceType']
                : null;
            $sourceUuid = isset($data['sourceUuid']) && $data['sourceUuid'] !== ''
                ? (string) $data['sourceUuid']
                : null;

            $farmUuid = $data['farmUuid'] ?? null;
            if (! $this->userCanWriteFinanceExpenseToFarm($user, $farmUuid)) {
                \Log::warning('Rejected finance income: farm access denied', ['uuid' => $uuid, 'farmUuid' => $farmUuid]);

                continue;
            }

            $existing = FinanceIncome::query()->where('uuid', $uuid)->first();
            if ($existing && $existing->farmUuid !== $farmUuid) {
                continue;
            }

            $farmerIdForRow = $this->resolveFarmerIdForFinanceExpenseFarm($farmUuid);
            if ($farmerIdForRow === null) {
                continue;
            }

            try {
                switch ($syncAction) {
                    case 'deleted':
                        FinanceIncome::query()
                            ->where('uuid', $uuid)
                            ->where('farmUuid', $farmUuid)
                            ->delete();
                        $synced[] = ['uuid' => $uuid];
                        break;

                    case 'create':
                    case 'update':
                        $qty = max(1, (int) ($data['quantity'] ?? 1));
                        $total = (float) ($data['totalAmount'] ?? 0);
                        if ($total <= 0) {
                            \Log::warning('Finance income rejected: totalAmount', ['uuid' => $uuid]);
                            break;
                        }
                        $unit = $qty > 0 ? $total / $qty : $total;
                        $status = strtolower((string) ($data['status'] ?? 'received'));
                        if (! in_array($status, ['pending', 'received'], true)) {
                            $status = 'received';
                        }
                        $incomeDate = isset($data['incomeDate'])
                            ? Carbon::parse($data['incomeDate'])
                            : now();
                        FinanceIncome::updateOrCreate(
                            [
                                'uuid' => $uuid,
                            ],
                            [
                                'uuid' => $uuid,
                                'sourceType' => $sourceType,
                                'sourceUuid' => $sourceUuid,
                                'farmUuid' => $farmUuid,
                                'farmerId' => $farmerIdForRow,
                                'referenceNo' => $data['referenceNo'] ?? null,
                                'subjectType' => substr((string) ($data['subjectType'] ?? 'Other'), 0, 255),
                                'quantity' => $qty,
                                'unitAmount' => number_format($unit, 2, '.', ''),
                                'totalAmount' => number_format($total, 2, '.', ''),
                                'status' => $status,
                                'notes' => $data['notes'] ?? null,
                                'incomeDate' => $incomeDate,
                            ]
                        );
                        $synced[] = ['uuid' => $uuid];
                        break;

                    default:
                        \Log::warning('Unknown finance income syncAction', ['uuid' => $uuid, 'syncAction' => $syncAction]);
                }
            } catch (\Throwable $e) {
                \Log::error('Finance income sync error', ['uuid' => $uuid, 'error' => $e->getMessage()]);
            }
        }

        return $synced;
    }

    private function userCanWriteFinanceExpenseToFarm(User $user, ?string $farmUuid): bool
    {
        if ($farmUuid === null || $farmUuid === '') {
            return false;
        }

        return app(FarmAccessService::class)->can($user, $farmUuid, 'finance.manage');
    }

    private function resolveFarmerIdForFinanceExpenseFarm(?string $farmUuid): ?int
    {
        if ($farmUuid === null || $farmUuid === '') {
            return null;
        }

        return Farm::where('uuid', $farmUuid)->value('farmerId');
    }

    // ============================================================================
    // HELPER METHODS - Access Control & Validation
    // ============================================================================

    /**
     * Get assigned farm UUIDs for a user (for farm invited users)
     *
     * @return array Array of farm UUIDs
     */
    private function getAssignedFarmUuidsForUser(User $user): array
    {
        // Farmers have access to all their farms (handled differently)
        if (strtolower($user->role) === 'farmer') {
            return [];
        }

        // For farm invited users, extension officers, vets
        $normalizedRole = strtolower(str_replace([' ', '_', '-'], '', $user->role ?? ''));

        // Extension officers get farms from extension_officer_farm_invites
        if ($normalizedRole === 'extensionofficer') {
            $extensionOfficer = ExtensionOfficer::find($user->roleId);

            if (! $extensionOfficer) {
                \Log::warning("ExtensionOfficer not found for roleId: {$user->roleId}");

                return [];
            }

            // Get farmer IDs from invites (no status column in table)
            $farmerIds = ExtensionOfficerFarmInvite::where('extensionOfficerId', $extensionOfficer->id)
                ->pluck('farmerId')
                ->toArray();

            if (empty($farmerIds)) {
                \Log::info("No accepted invites found for extension officer {$user->id}");

                return [];
            }

            // Get farm UUIDs from those farmers
            $farmUuids = Farm::whereIn('farmerId', $farmerIds)
                ->pluck('uuid')
                ->toArray();

            \Log::info("Assigned farm UUIDs for extension officer {$user->id}: ".json_encode($farmUuids));

            return $farmUuids;
        }

        // Vets get the farms of the service requests they are working on
        if ($normalizedRole === 'vet') {
            return \App\Services\VetServices\VetServiceRequestService::farmUuidsForVetUser($user);
        }

        // Auction officers have no farms of their own
        if ($normalizedRole === 'auctionofficer') {
            return [];
        }

        // For farm invited users
        if (in_array($normalizedRole, ['farminviteduser'])) {
            $farmUser = \App\Models\FarmUser::find($user->roleId);

            if (! $farmUser) {
                \Log::warning("FarmUser not found for roleId: {$user->roleId}");

                return [];
            }

            $farmUuids = app(FarmAccessService::class)->accessibleFarmUuids($user) ?? [];
            \Log::info("Assigned farm UUIDs for user {$user->id}: ".json_encode($farmUuids));

            return $farmUuids;
        }

        return [];
    }

    /**
     * Validate livestock belongs to assigned farms and filter access
     *
     * @return array Filtered livestock array
     */
    private function validateLivestockAccess(array $livestock, array $assignedFarmUuids, int $userId): array
    {
        if (empty($assignedFarmUuids)) {
            return [];
        }

        $filtered = [];
        foreach ($livestock as $item) {
            $farmUuid = $item['farmUuid'] ?? null;

            if (! $farmUuid || ! in_array($farmUuid, $assignedFarmUuids)) {
                \Log::warning('Livestock rejected - farm not assigned', [
                    'userId' => $userId,
                    'livestockUuid' => $item['uuid'] ?? 'unknown',
                    'farmUuid' => $farmUuid,
                    'assignedFarms' => $assignedFarmUuids,
                ]);

                continue;
            }

            $filtered[] = $item;
        }

        \Log::info('Livestock access validated - '.count($filtered).' of '.count($livestock).' items allowed');

        return $filtered;
    }

    /**
     * Check if livestock belongs to assigned farms
     */
    private function validateLivestockBelongsToFarms(string $livestockUuid, array $assignedFarmUuids): bool
    {
        if (empty($assignedFarmUuids)) {
            return false;
        }

        $livestock = Livestock::where('uuid', $livestockUuid)->first();

        if (! $livestock) {
            \Log::warning("Livestock not found: {$livestockUuid}");

            return false;
        }

        $farmUuid = $livestock->farmUuid;
        $belongsTo = in_array($farmUuid, $assignedFarmUuids);

        if (! $belongsTo) {
            \Log::debug("Livestock {$livestockUuid} (farm: {$farmUuid}) not in assigned farms: ".json_encode($assignedFarmUuids));
        }

        return $belongsTo;
    }

    /**
     * Get farmer ID from livestock data (for validation)
     */
    private function getFarmerIdForLivestock(array $livestock, User $user): ?int
    {
        // For farmers, use their roleId (farmerId)
        if (strtolower($user->role) === 'farmer') {
            return $user->roleId;
        }

        // For farm invited users, get farmer ID from the livestock's farm
        if (! empty($livestock)) {
            $firstLivestock = $livestock[0];
            $farmUuid = $firstLivestock['farmUuid'] ?? null;

            if ($farmUuid) {
                $farm = Farm::where('uuid', $farmUuid)->first();
                if ($farm) {
                    return $farm->farmerId;
                }
            }
        }

        return null;
    }

    /**
     * Validate vaccines belong to assigned farms and filter access
     *
     * @return array Filtered vaccines array
     */
    private function validateVaccineAccess(array $vaccines, array $assignedFarmUuids, int $userId): array
    {
        if (empty($assignedFarmUuids)) {
            return [];
        }

        $filtered = [];
        foreach ($vaccines as $vaccine) {
            $farmUuid = $vaccine['farmUuid'] ?? null;

            if (! $farmUuid || ! in_array($farmUuid, $assignedFarmUuids)) {
                \Log::warning('Vaccine rejected - farm not assigned', [
                    'userId' => $userId,
                    'vaccineUuid' => $vaccine['uuid'] ?? 'unknown',
                    'farmUuid' => $farmUuid,
                    'assignedFarms' => $assignedFarmUuids,
                ]);

                continue;
            }

            $filtered[] = $vaccine;
        }

        \Log::info('Vaccine access validated - '.count($filtered).' of '.count($vaccines).' items allowed');

        return $filtered;
    }
}
