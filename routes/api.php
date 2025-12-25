<?php

use App\Enums\UserRole;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\AdministrationRoute\AdministrationRouteController;
use App\Http\Controllers\BirthProblem\BirthProblemController;
use App\Http\Controllers\BirthType\BirthTypeController;
use App\Http\Controllers\Breed\BreedController;
use App\Http\Controllers\CalvingProblem\CalvingProblemController;
use App\Http\Controllers\CalvingType\CalvingTypeController;
use App\Http\Controllers\Disease\DiseaseController;
use App\Http\Controllers\DisposalType\DisposalTypeController;
use App\Http\Controllers\ExtensionOfficer\ExtensionOfficerController;
use App\Http\Controllers\ExtensionOfficerFarmInvite\ExtensionOfficerFarmInviteController;
use App\Http\Controllers\Farm\FarmController;
use App\Http\Controllers\Farmer\FarmerController;
use App\Http\Controllers\FeedingType\FeedingTypeController;
use App\Http\Controllers\HeatType\HeatTypeController;
use App\Http\Controllers\IdentityCardType\IdentityCardTypeController;
use App\Http\Controllers\InseminationService\InseminationServiceController;
use App\Http\Controllers\LegalStatus\LegalStatusController;
use App\Http\Controllers\Livestock\LivestockController;
use App\Http\Controllers\LivestockObtainedMethod\LivestockObtainedMethodController;
use App\Http\Controllers\LivestockType\LivestockTypeController;
use App\Http\Controllers\Location\LocationController;
use App\Http\Controllers\Logs\AbortedPregnancy\AbortedPregnancyController;
use App\Http\Controllers\Logs\Birth\BirthEventController;
use App\Http\Controllers\Logs\Calving\CalvingController;
use App\Http\Controllers\Logs\Disposal\DisposalController;
use App\Http\Controllers\Logs\Deworming\DewormingController;
use App\Http\Controllers\Logs\Dryoff\DryoffController;
use App\Http\Controllers\Logs\Feeding\FeedingController;
use App\Http\Controllers\Logs\Insemination\InseminationController;
use App\Http\Controllers\Logs\Treatment\TreatmentController;
use App\Http\Controllers\Logs\Milking\MilkingController;
use App\Http\Controllers\Logs\Pregnancy\PregnancyController;
use App\Http\Controllers\Logs\Transfer\TransferController;
use App\Http\Controllers\Logs\Vaccination\VaccinationController;
use App\Http\Controllers\Logs\WeightChange\WeightChangeController;
use App\Http\Controllers\Medicine\MedicineController;
use App\Http\Controllers\MedicineType\MedicineTypeController;
use App\Http\Controllers\MilkingMethod\MilkingMethodController;
use App\Http\Controllers\ReproductiveProblem\ReproductiveProblemController;
use App\Http\Controllers\SchoolLevel\SchoolLevelController;
use App\Http\Controllers\SemenStrawType\SemenStrawTypeController;
use App\Http\Controllers\Specie\SpecieController;
use App\Http\Controllers\Stage\StageController;
use App\Http\Controllers\Sync\SyncController;
use App\Http\Controllers\TestResult\TestResultController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\Vaccine\VaccineController;
use App\Http\Controllers\Vaccine\VaccineTypeController;
use App\Http\Controllers\Vet\VetController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

/*
|--------------------------------------------------------------------------
| Public Routes (No Authentication Required)
|--------------------------------------------------------------------------
*/

/*
| Authentication Routes (Public)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/extension-officer/login', [AuthController::class, 'extensionOfficerLogin']);

    // Forgot Password Routes
    Route::post('/forgot-password/send-otp', [AuthController::class, 'sendOtp']);
    Route::post('/forgot-password/reset-password', [AuthController::class, 'resetPassword']);
});

/*
| V1 API Routes - Public (No Authentication Required)
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->group(function () {

    /*
    | Sync Routes (Public - No Auth Required)
    |--------------------------------------------------------------------------
    */
    Route::prefix('sync')->group(function () {
        // Get reference data for registration (no auth needed)
        Route::get('/initial-register', [SyncController::class, 'initialRegisterSync']);
    });

});

/*
|--------------------------------------------------------------------------
| Protected Routes (Authentication Required)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Authentication Routes (Protected)
    |--------------------------------------------------------------------------
    */
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll']);
        Route::get('/profile', [AuthController::class, 'profile']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
    });

    /*
    |--------------------------------------------------------------------------
    | V1 API Routes - Protected (Authentication Required)
    |--------------------------------------------------------------------------
    */
    Route::prefix('v1')->group(function () {

        /*
        | User Routes
        |--------------------------------------------------------------------------
        */
        Route::get('/user', function (Request $request) {
            return response()->json([
                'status' => true,
                'message' => 'User retrieved successfully',
                'data' => $request->user(),
            ]);
        });

        /*
        | User Management Routes (System User Only)
        |--------------------------------------------------------------------------
        | NOTE:
        | - These are the core user management APIs.
        | - They are also exposed under the /v1/admin prefix for the admin portal.
        */
        Route::prefix('users')->middleware('check.role:'.UserRole::SYSTEM_USER)->group(function () {
            Route::get('/', [UserController::class, 'index']);
            Route::post('/', [UserController::class, 'store']);
            Route::get('/statistics', [UserController::class, 'statistics']);
            Route::get('/{user}', [UserController::class, 'show']);
            Route::put('/{user}', [UserController::class, 'update']);
            Route::delete('/{user}', [UserController::class, 'destroy']);
        });

        /*
        |--------------------------------------------------------------------------
        | Admin Portal Routes (System User Only)
        |--------------------------------------------------------------------------
        | Structure:
        |   /api/v1/admin/users...
        | These routes are intended to be consumed by the web-based admin portal
        | and are protected by auth:sanctum + check.role:systemUser.
        */
        Route::prefix('admin')
            ->middleware('check.role:' . UserRole::SYSTEM_USER)
            ->group(function () {
                // Admin user management
                Route::prefix('users')->group(function () {
                    Route::get('/', [UserController::class, 'index']);
                    Route::post('/', [UserController::class, 'store']);
                    Route::get('/statistics', [UserController::class, 'statistics']);
                    Route::get('/{user}', [UserController::class, 'show']);
                    Route::put('/{user}', [UserController::class, 'update']);
                    Route::delete('/{user}', [UserController::class, 'destroy']);
                });

                // Admin location management (starting with Country CRUD)
                Route::prefix('locations')->group(function () {
                    Route::get('countries', [LocationController::class, 'adminListCountries']);
                    Route::post('countries', [LocationController::class, 'adminStoreCountry']);
                    Route::get('countries/{country}', [LocationController::class, 'adminShowCountry']);
                    Route::put('countries/{country}', [LocationController::class, 'adminUpdateCountry']);
                    Route::delete('countries/{country}', [LocationController::class, 'adminDeleteCountry']);

                    Route::get('regions', [LocationController::class, 'adminListRegions']);
                    Route::post('regions', [LocationController::class, 'adminStoreRegion']);
                    Route::get('regions/{region}', [LocationController::class, 'adminShowRegion']);
                    Route::put('regions/{region}', [LocationController::class, 'adminUpdateRegion']);
                    Route::delete('regions/{region}', [LocationController::class, 'adminDeleteRegion']);

                    Route::get('districts', [LocationController::class, 'adminListDistricts']);
                    Route::post('districts', [LocationController::class, 'adminStoreDistrict']);
                    Route::get('districts/{district}', [LocationController::class, 'adminShowDistrict']);
                    Route::put('districts/{district}', [LocationController::class, 'adminUpdateDistrict']);
                    Route::delete('districts/{district}', [LocationController::class, 'adminDeleteDistrict']);

                    Route::get('wards', [LocationController::class, 'adminListWards']);
                    Route::post('wards', [LocationController::class, 'adminStoreWard']);
                    Route::get('wards/{ward}', [LocationController::class, 'adminShowWard']);
                    Route::put('wards/{ward}', [LocationController::class, 'adminUpdateWard']);
                    Route::delete('wards/{ward}', [LocationController::class, 'adminDeleteWard']);

                    Route::get('villages', [LocationController::class, 'adminListVillages']);
                    Route::post('villages', [LocationController::class, 'adminStoreVillage']);
                    Route::get('villages/{village}', [LocationController::class, 'adminShowVillage']);
                    Route::put('villages/{village}', [LocationController::class, 'adminUpdateVillage']);
                    Route::delete('villages/{village}', [LocationController::class, 'adminDeleteVillage']);

                    Route::get('streets', [LocationController::class, 'adminListStreets']);
                    Route::post('streets', [LocationController::class, 'adminStoreStreet']);
                    Route::get('streets/{street}', [LocationController::class, 'adminShowStreet']);
                    Route::put('streets/{street}', [LocationController::class, 'adminUpdateStreet']);
                    Route::delete('streets/{street}', [LocationController::class, 'adminDeleteStreet']);

                    Route::get('divisions', [LocationController::class, 'adminListDivisions']);
                    Route::post('divisions', [LocationController::class, 'adminStoreDivision']);
                    Route::get('divisions/{division}', [LocationController::class, 'adminShowDivision']);
                    Route::put('divisions/{division}', [LocationController::class, 'adminUpdateDivision']);
                    Route::delete('divisions/{division}', [LocationController::class, 'adminDeleteDivision']);
                });

                // Admin reference data management
                Route::prefix('reference')->group(function () {
                    // Birth Problems
                    Route::get('birth-problems', [BirthProblemController::class, 'adminIndex']);
                    Route::post('birth-problems', [BirthProblemController::class, 'adminStore']);
                    Route::get('birth-problems/{birthProblem}', [BirthProblemController::class, 'adminShow']);
                    Route::put('birth-problems/{birthProblem}', [BirthProblemController::class, 'adminUpdate']);
                    Route::delete('birth-problems/{birthProblem}', [BirthProblemController::class, 'adminDestroy']);

                    // Birth Types
                    Route::get('birth-types', [BirthTypeController::class, 'adminIndex']);
                    Route::post('birth-types', [BirthTypeController::class, 'adminStore']);
                    Route::get('birth-types/{birthType}', [BirthTypeController::class, 'adminShow']);
                    Route::put('birth-types/{birthType}', [BirthTypeController::class, 'adminUpdate']);
                    Route::delete('birth-types/{birthType}', [BirthTypeController::class, 'adminDestroy']);

                    // Diseases
                    Route::get('diseases', [DiseaseController::class, 'adminIndex']);
                    Route::post('diseases', [DiseaseController::class, 'adminStore']);
                    Route::get('diseases/{disease}', [DiseaseController::class, 'adminShow']);
                    Route::put('diseases/{disease}', [DiseaseController::class, 'adminUpdate']);
                    Route::delete('diseases/{disease}', [DiseaseController::class, 'adminDestroy']);

                    // Disposal Types
                    Route::get('disposal-types', [DisposalTypeController::class, 'adminIndex']);
                    Route::post('disposal-types', [DisposalTypeController::class, 'adminStore']);
                    Route::get('disposal-types/{disposalType}', [DisposalTypeController::class, 'adminShow']);
                    Route::put('disposal-types/{disposalType}', [DisposalTypeController::class, 'adminUpdate']);
                    Route::delete('disposal-types/{disposalType}', [DisposalTypeController::class, 'adminDestroy']);

                    // Administration Routes
                    Route::get('administration-routes', [AdministrationRouteController::class, 'adminIndex']);
                    Route::post('administration-routes', [AdministrationRouteController::class, 'adminStore']);
                    Route::get('administration-routes/{administrationRoute}', [AdministrationRouteController::class, 'adminShow']);
                    Route::put('administration-routes/{administrationRoute}', [AdministrationRouteController::class, 'adminUpdate']);
                    Route::delete('administration-routes/{administrationRoute}', [AdministrationRouteController::class, 'adminDestroy']);

                    // Breeds
                    Route::get('breeds', [BreedController::class, 'adminIndex']);
                    Route::post('breeds', [BreedController::class, 'adminStore']);
                    Route::get('breeds/{breed}', [BreedController::class, 'adminShow']);
                    Route::put('breeds/{breed}', [BreedController::class, 'adminUpdate']);
                    Route::delete('breeds/{breed}', [BreedController::class, 'adminDestroy']);

                    // Heat Types
                    Route::get('heat-types', [HeatTypeController::class, 'adminIndex']);
                    Route::post('heat-types', [HeatTypeController::class, 'adminStore']);
                    Route::get('heat-types/{heatType}', [HeatTypeController::class, 'adminShow']);
                    Route::put('heat-types/{heatType}', [HeatTypeController::class, 'adminUpdate']);
                    Route::delete('heat-types/{heatType}', [HeatTypeController::class, 'adminDestroy']);

                    // Feeding Types
                    Route::get('feeding-types', [FeedingTypeController::class, 'adminIndex']);
                    Route::post('feeding-types', [FeedingTypeController::class, 'adminStore']);
                    Route::get('feeding-types/{feedingType}', [FeedingTypeController::class, 'adminShow']);
                    Route::put('feeding-types/{feedingType}', [FeedingTypeController::class, 'adminUpdate']);
                    Route::delete('feeding-types/{feedingType}', [FeedingTypeController::class, 'adminDestroy']);

                    // Identity Card Types
                    Route::get('identity-card-types', [IdentityCardTypeController::class, 'adminIndex']);
                    Route::post('identity-card-types', [IdentityCardTypeController::class, 'adminStore']);
                    Route::get('identity-card-types/{identityCardType}', [IdentityCardTypeController::class, 'adminShow']);
                    Route::put('identity-card-types/{identityCardType}', [IdentityCardTypeController::class, 'adminUpdate']);
                    Route::delete('identity-card-types/{identityCardType}', [IdentityCardTypeController::class, 'adminDestroy']);

                    // Insemination Services
                    Route::get('insemination-services', [InseminationServiceController::class, 'adminIndex']);
                    Route::post('insemination-services', [InseminationServiceController::class, 'adminStore']);
                    Route::get('insemination-services/{inseminationService}', [InseminationServiceController::class, 'adminShow']);
                    Route::put('insemination-services/{inseminationService}', [InseminationServiceController::class, 'adminUpdate']);
                    Route::delete('insemination-services/{inseminationService}', [InseminationServiceController::class, 'adminDestroy']);

                    // Legal Statuses
                    Route::get('legal-statuses', [LegalStatusController::class, 'adminIndex']);
                    Route::post('legal-statuses', [LegalStatusController::class, 'adminStore']);
                    Route::get('legal-statuses/{legalStatus}', [LegalStatusController::class, 'adminShow']);
                    Route::put('legal-statuses/{legalStatus}', [LegalStatusController::class, 'adminUpdate']);
                    Route::delete('legal-statuses/{legalStatus}', [LegalStatusController::class, 'adminDestroy']);

                    // Livestock Obtained Methods
                    Route::get('livestock-obtained-methods', [LivestockObtainedMethodController::class, 'adminIndex']);
                    Route::post('livestock-obtained-methods', [LivestockObtainedMethodController::class, 'adminStore']);
                    Route::get('livestock-obtained-methods/{livestockObtainedMethod}', [LivestockObtainedMethodController::class, 'adminShow']);
                    Route::put('livestock-obtained-methods/{livestockObtainedMethod}', [LivestockObtainedMethodController::class, 'adminUpdate']);
                    Route::delete('livestock-obtained-methods/{livestockObtainedMethod}', [LivestockObtainedMethodController::class, 'adminDestroy']);

                    // Livestock Types
                    Route::get('livestock-types', [LivestockTypeController::class, 'adminIndex']);
                    Route::post('livestock-types', [LivestockTypeController::class, 'adminStore']);
                    Route::get('livestock-types/{livestockType}', [LivestockTypeController::class, 'adminShow']);
                    Route::put('livestock-types/{livestockType}', [LivestockTypeController::class, 'adminUpdate']);
                    Route::delete('livestock-types/{livestockType}', [LivestockTypeController::class, 'adminDestroy']);

                    // Medicine Types
                    Route::get('medicine-types', [MedicineTypeController::class, 'adminIndex']);
                    Route::post('medicine-types', [MedicineTypeController::class, 'adminStore']);
                    Route::get('medicine-types/{medicineType}', [MedicineTypeController::class, 'adminShow']);
                    Route::put('medicine-types/{medicineType}', [MedicineTypeController::class, 'adminUpdate']);
                    Route::delete('medicine-types/{medicineType}', [MedicineTypeController::class, 'adminDestroy']);

                    // Medicines
                    Route::get('medicines', [MedicineController::class, 'adminIndex']);
                    Route::post('medicines', [MedicineController::class, 'adminStore']);
                    Route::get('medicines/{medicine}', [MedicineController::class, 'adminShow']);
                    Route::put('medicines/{medicine}', [MedicineController::class, 'adminUpdate']);
                    Route::delete('medicines/{medicine}', [MedicineController::class, 'adminDestroy']);

                    // Milking Methods
                    Route::get('milking-methods', [MilkingMethodController::class, 'adminIndex']);
                    Route::post('milking-methods', [MilkingMethodController::class, 'adminStore']);
                    Route::get('milking-methods/{milkingMethod}', [MilkingMethodController::class, 'adminShow']);
                    Route::put('milking-methods/{milkingMethod}', [MilkingMethodController::class, 'adminUpdate']);
                    Route::delete('milking-methods/{milkingMethod}', [MilkingMethodController::class, 'adminDestroy']);

                    // Reproductive Problems
                    Route::get('reproductive-problems', [ReproductiveProblemController::class, 'adminIndex']);
                    Route::post('reproductive-problems', [ReproductiveProblemController::class, 'adminStore']);
                    Route::get('reproductive-problems/{reproductiveProblem}', [ReproductiveProblemController::class, 'adminShow']);
                    Route::put('reproductive-problems/{reproductiveProblem}', [ReproductiveProblemController::class, 'adminUpdate']);
                    Route::delete('reproductive-problems/{reproductiveProblem}', [ReproductiveProblemController::class, 'adminDestroy']);

                    // School Levels
                    Route::get('school-levels', [SchoolLevelController::class, 'adminIndex']);
                    Route::post('school-levels', [SchoolLevelController::class, 'adminStore']);
                    Route::get('school-levels/{schoolLevel}', [SchoolLevelController::class, 'adminShow']);
                    Route::put('school-levels/{schoolLevel}', [SchoolLevelController::class, 'adminUpdate']);
                    Route::delete('school-levels/{schoolLevel}', [SchoolLevelController::class, 'adminDestroy']);

                    // Semen Straw Types
                    Route::get('semen-straw-types', [SemenStrawTypeController::class, 'adminIndex']);
                    Route::post('semen-straw-types', [SemenStrawTypeController::class, 'adminStore']);
                    Route::get('semen-straw-types/{semenStrawType}', [SemenStrawTypeController::class, 'adminShow']);
                    Route::put('semen-straw-types/{semenStrawType}', [SemenStrawTypeController::class, 'adminUpdate']);
                    Route::delete('semen-straw-types/{semenStrawType}', [SemenStrawTypeController::class, 'adminDestroy']);

                    // Species
                    Route::get('species', [SpecieController::class, 'adminIndex']);
                    Route::post('species', [SpecieController::class, 'adminStore']);
                    Route::get('species/{specie}', [SpecieController::class, 'adminShow']);
                    Route::put('species/{specie}', [SpecieController::class, 'adminUpdate']);
                    Route::delete('species/{specie}', [SpecieController::class, 'adminDestroy']);

                    // Stages
                    Route::get('stages', [StageController::class, 'adminIndex']);
                    Route::post('stages', [StageController::class, 'adminStore']);
                    Route::get('stages/{stage}', [StageController::class, 'adminShow']);
                    Route::put('stages/{stage}', [StageController::class, 'adminUpdate']);
                    Route::delete('stages/{stage}', [StageController::class, 'adminDestroy']);

                    // Test Results
                    Route::get('test-results', [TestResultController::class, 'adminIndex']);
                    Route::post('test-results', [TestResultController::class, 'adminStore']);
                    Route::get('test-results/{testResult}', [TestResultController::class, 'adminShow']);
                    Route::put('test-results/{testResult}', [TestResultController::class, 'adminUpdate']);
                    Route::delete('test-results/{testResult}', [TestResultController::class, 'adminDestroy']);

                    // Vaccine Types
                    Route::get('vaccine-types', [VaccineTypeController::class, 'adminIndex']);
                    Route::post('vaccine-types', [VaccineTypeController::class, 'adminStore']);
                    Route::get('vaccine-types/{vaccineType}', [VaccineTypeController::class, 'adminShow']);
                    Route::put('vaccine-types/{vaccineType}', [VaccineTypeController::class, 'adminUpdate']);
                    Route::delete('vaccine-types/{vaccineType}', [VaccineTypeController::class, 'adminDestroy']);
                });

                // Admin entity management
                Route::prefix('entities')->group(function () {
                    // Extension Officers
                    Route::get('extension-officers', [ExtensionOfficerController::class, 'adminIndex']);
                    Route::post('extension-officers', [ExtensionOfficerController::class, 'adminStore']);
                    Route::get('extension-officers/{extensionOfficer}', [ExtensionOfficerController::class, 'adminShow']);
                    Route::put('extension-officers/{extensionOfficer}', [ExtensionOfficerController::class, 'adminUpdate']);
                    Route::delete('extension-officers/{extensionOfficer}', [ExtensionOfficerController::class, 'adminDestroy']);

                    // Extension Officer Farm Invites
                    Route::get('extension-officer-farm-invites', [ExtensionOfficerFarmInviteController::class, 'adminIndex']);
                    Route::post('extension-officer-farm-invites', [ExtensionOfficerFarmInviteController::class, 'adminStore']);
                    Route::get('extension-officer-farm-invites/{extensionOfficerFarmInvite}', [ExtensionOfficerFarmInviteController::class, 'adminShow']);
                    Route::put('extension-officer-farm-invites/{extensionOfficerFarmInvite}', [ExtensionOfficerFarmInviteController::class, 'adminUpdate']);
                    Route::delete('extension-officer-farm-invites/{extensionOfficerFarmInvite}', [ExtensionOfficerFarmInviteController::class, 'adminDestroy']);

                    // Farmers
                    Route::get('farmers', [FarmerController::class, 'adminIndex']);
                    Route::post('farmers', [FarmerController::class, 'adminStore']);
                    Route::get('farmers/{farmer}', [FarmerController::class, 'adminShow']);
                    Route::put('farmers/{farmer}', [FarmerController::class, 'adminUpdate']);
                    Route::delete('farmers/{farmer}', [FarmerController::class, 'adminDestroy']);

                    // Farms
                    Route::get('farms', [FarmController::class, 'adminIndex']);
                    Route::post('farms', [FarmController::class, 'adminStore']);
                    Route::get('farms/{farm}', [FarmController::class, 'adminShow']);
                    Route::put('farms/{farm}', [FarmController::class, 'adminUpdate']);
                    Route::delete('farms/{farm}', [FarmController::class, 'adminDestroy']);

                    // Livestock
                    Route::get('livestock', [LivestockController::class, 'adminIndex']);
                    Route::post('livestock', [LivestockController::class, 'adminStore']);
                    Route::get('livestock/{livestock}', [LivestockController::class, 'adminShow']);
                    Route::put('livestock/{livestock}', [LivestockController::class, 'adminUpdate']);
                    Route::delete('livestock/{livestock}', [LivestockController::class, 'adminDestroy']);

                    // Vets
                    Route::get('vets', [VetController::class, 'adminIndex']);
                    Route::post('vets', [VetController::class, 'adminStore']);
                    Route::get('vets/{vet}', [VetController::class, 'adminShow']);
                    Route::put('vets/{vet}', [VetController::class, 'adminUpdate']);
                    Route::delete('vets/{vet}', [VetController::class, 'adminDestroy']);

                    // Vaccines
                    Route::get('vaccines', [VaccineController::class, 'adminIndex']);
                    Route::post('vaccines', [VaccineController::class, 'adminStore']);
                    Route::get('vaccines/{vaccine}', [VaccineController::class, 'adminShow']);
                    Route::put('vaccines/{vaccine}', [VaccineController::class, 'adminUpdate']);
                    Route::delete('vaccines/{vaccine}', [VaccineController::class, 'adminDestroy']);
                });

                // Admin log management
                Route::prefix('logs')->group(function () {
                    // Birth Events
                    Route::get('birth-events', [BirthEventController::class, 'adminIndex']);
                    Route::post('birth-events', [BirthEventController::class, 'adminStore']);
                    Route::get('birth-events/{birthEvent}', [BirthEventController::class, 'adminShow']);
                    Route::put('birth-events/{birthEvent}', [BirthEventController::class, 'adminUpdate']);
                    Route::delete('birth-events/{birthEvent}', [BirthEventController::class, 'adminDestroy']);

                    // Aborted Pregnancies
                    Route::get('aborted-pregnancies', [AbortedPregnancyController::class, 'adminIndex']);
                    Route::post('aborted-pregnancies', [AbortedPregnancyController::class, 'adminStore']);
                    Route::get('aborted-pregnancies/{abortedPregnancy}', [AbortedPregnancyController::class, 'adminShow']);
                    Route::put('aborted-pregnancies/{abortedPregnancy}', [AbortedPregnancyController::class, 'adminUpdate']);
                    Route::delete('aborted-pregnancies/{abortedPregnancy}', [AbortedPregnancyController::class, 'adminDestroy']);

                    // Calvings (legacy)
                    Route::get('calvings', [CalvingController::class, 'adminIndex']);
                    Route::post('calvings', [CalvingController::class, 'adminStore']);
                    Route::get('calvings/{calving}', [CalvingController::class, 'adminShow']);
                    Route::put('calvings/{calving}', [CalvingController::class, 'adminUpdate']);
                    Route::delete('calvings/{calving}', [CalvingController::class, 'adminDestroy']);

                    // Feedings
                    Route::get('feedings', [FeedingController::class, 'adminIndex']);
                    Route::post('feedings', [FeedingController::class, 'adminStore']);
                    Route::get('feedings/{feeding}', [FeedingController::class, 'adminShow']);
                    Route::put('feedings/{feeding}', [FeedingController::class, 'adminUpdate']);
                    Route::delete('feedings/{feeding}', [FeedingController::class, 'adminDestroy']);

                    // Weight Changes
                    Route::get('weight-changes', [WeightChangeController::class, 'adminIndex']);
                    Route::post('weight-changes', [WeightChangeController::class, 'adminStore']);
                    Route::get('weight-changes/{weightChange}', [WeightChangeController::class, 'adminShow']);
                    Route::put('weight-changes/{weightChange}', [WeightChangeController::class, 'adminUpdate']);
                    Route::delete('weight-changes/{weightChange}', [WeightChangeController::class, 'adminDestroy']);

                    // Dewormings
                    Route::get('dewormings', [DewormingController::class, 'adminIndex']);
                    Route::post('dewormings', [DewormingController::class, 'adminStore']);
                    Route::get('dewormings/{deworming}', [DewormingController::class, 'adminShow']);
                    Route::put('dewormings/{deworming}', [DewormingController::class, 'adminUpdate']);
                    Route::delete('dewormings/{deworming}', [DewormingController::class, 'adminDestroy']);

                    // Treatments
                    Route::get('treatments', [TreatmentController::class, 'adminIndex']);
                    Route::post('treatments', [TreatmentController::class, 'adminStore']);
                    Route::get('treatments/{treatment}', [TreatmentController::class, 'adminShow']);
                    Route::put('treatments/{treatment}', [TreatmentController::class, 'adminUpdate']);
                    Route::delete('treatments/{treatment}', [TreatmentController::class, 'adminDestroy']);

                    // Vaccinations
                    Route::get('vaccinations', [VaccinationController::class, 'adminIndex']);
                    Route::post('vaccinations', [VaccinationController::class, 'adminStore']);
                    Route::get('vaccinations/{vaccination}', [VaccinationController::class, 'adminShow']);
                    Route::put('vaccinations/{vaccination}', [VaccinationController::class, 'adminUpdate']);
                    Route::delete('vaccinations/{vaccination}', [VaccinationController::class, 'adminDestroy']);

                    // Disposals
                    Route::get('disposals', [DisposalController::class, 'adminIndex']);
                    Route::post('disposals', [DisposalController::class, 'adminStore']);
                    Route::get('disposals/{disposal}', [DisposalController::class, 'adminShow']);
                    Route::put('disposals/{disposal}', [DisposalController::class, 'adminUpdate']);
                    Route::delete('disposals/{disposal}', [DisposalController::class, 'adminDestroy']);

                    // Milkings
                    Route::get('milkings', [MilkingController::class, 'adminIndex']);
                    Route::post('milkings', [MilkingController::class, 'adminStore']);
                    Route::get('milkings/{milking}', [MilkingController::class, 'adminShow']);
                    Route::put('milkings/{milking}', [MilkingController::class, 'adminUpdate']);
                    Route::delete('milkings/{milking}', [MilkingController::class, 'adminDestroy']);

                    // Pregnancies
                    Route::get('pregnancies', [PregnancyController::class, 'adminIndex']);
                    Route::post('pregnancies', [PregnancyController::class, 'adminStore']);
                    Route::get('pregnancies/{pregnancy}', [PregnancyController::class, 'adminShow']);
                    Route::put('pregnancies/{pregnancy}', [PregnancyController::class, 'adminUpdate']);
                    Route::delete('pregnancies/{pregnancy}', [PregnancyController::class, 'adminDestroy']);

                    // Inseminations
                    Route::get('inseminations', [InseminationController::class, 'adminIndex']);
                    Route::post('inseminations', [InseminationController::class, 'adminStore']);
                    Route::get('inseminations/{insemination}', [InseminationController::class, 'adminShow']);
                    Route::put('inseminations/{insemination}', [InseminationController::class, 'adminUpdate']);
                    Route::delete('inseminations/{insemination}', [InseminationController::class, 'adminDestroy']);

                    // Dryoffs
                    Route::get('dryoffs', [DryoffController::class, 'adminIndex']);
                    Route::post('dryoffs', [DryoffController::class, 'adminStore']);
                    Route::get('dryoffs/{dryoff}', [DryoffController::class, 'adminShow']);
                    Route::put('dryoffs/{dryoff}', [DryoffController::class, 'adminUpdate']);
                    Route::delete('dryoffs/{dryoff}', [DryoffController::class, 'adminDestroy']);

                    // Transfers
                    Route::get('transfers', [TransferController::class, 'adminIndex']);
                    Route::post('transfers', [TransferController::class, 'adminStore']);
                    Route::get('transfers/{transfer}', [TransferController::class, 'adminShow']);
                    Route::put('transfers/{transfer}', [TransferController::class, 'adminUpdate']);
                    Route::delete('transfers/{transfer}', [TransferController::class, 'adminDestroy']);
                });
            });

        /*
        | Sync Routes (Shared - All authenticated roles can sync)
        |--------------------------------------------------------------------------
        | These routes allow all authenticated users (farmers, extension officers,
        | vets, farm invited users) to sync data based on their role permissions.
        */
        Route::prefix('sync')->group(function () {
            // Get all data on app startup based on user role
            Route::get('/splash-sync-all/{userId}', [SyncController::class, 'splashSync']);

            // Send unsynced data to server (farms, livestock, etc.)
            Route::post('/full-post-sync/{userId}', [SyncController::class, 'postSync']);
        });

        /*
        | Role-Based Module Routes
        |--------------------------------------------------------------------------
        */

        // Farmer Routes
        Route::prefix('farmers')->middleware('check.role:'.UserRole::FARMER.','.UserRole::SYSTEM_USER)->group(function () {
            // Extension Officer Farm Invite Routes
            Route::prefix('extension-officer-invites')->group(function () {
                Route::get('/search', [ExtensionOfficerFarmInviteController::class, 'searchByEmail']);
                Route::post('/', [ExtensionOfficerFarmInviteController::class, 'store']);
            });
        });

        // Extension Officer Routes
        Route::prefix('extension-officers')->middleware('check.role:'.UserRole::EXTENSION_OFFICER.','.UserRole::SYSTEM_USER)->group(function () {
            // Extension officer-specific routes can be added here
        });

        // Veterinarian Routes
        Route::prefix('vets')->middleware('check.role:'.UserRole::VET.','.UserRole::SYSTEM_USER)->group(function () {
            // Vet-specific routes can be added here
        });

        /*
        | Birth Events & Aborted Pregnancies Routes
        |--------------------------------------------------------------------------
        */
        Route::prefix('logs')->group(function () {
            Route::apiResource('birth-events', BirthEventController::class);
            Route::apiResource('aborted-pregnancies', AbortedPregnancyController::class);
        });

        /*
        | Reference Data Routes
        |--------------------------------------------------------------------------
        */
        Route::prefix('reference')->group(function () {
            Route::get('stages', [StageController::class, 'index']);
            Route::get('stages/by-livestock-type/{livestockTypeId}', [StageController::class, 'getByLivestockType']);
            Route::get('birth-types', [BirthTypeController::class, 'fetchAll']);
            Route::get('birth-types/by-livestock-type/{livestockTypeId}', [BirthTypeController::class, 'getByLivestockType']);
            Route::get('birth-problems', [BirthProblemController::class, 'fetchAll']);
            Route::get('birth-problems/by-livestock-type/{livestockTypeId}', [BirthProblemController::class, 'getByLivestockType']);
            Route::get('reproductive-problems', [ReproductiveProblemController::class, 'fetchAll']);

            // Backward compatibility routes (deprecated)
            Route::get('calving-types/by-livestock-type/{livestockTypeId}', [CalvingTypeController::class, 'getByLivestockType']);
            Route::get('calving-problems/by-livestock-type/{livestockTypeId}', [CalvingProblemController::class, 'getByLivestockType']);
        });

    }); // End v1 prefix

}); // End auth:sanctum middleware
