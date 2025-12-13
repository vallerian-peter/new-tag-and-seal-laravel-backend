<?php

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Sync\SyncController;
use App\Http\Controllers\Location\LocationController;
use App\Http\Controllers\ExtensionOfficerFarmInvite\ExtensionOfficerFarmInviteController;
use App\Http\Controllers\Stage\StageController;
use App\Http\Controllers\BirthType\BirthTypeController;
use App\Http\Controllers\BirthProblem\BirthProblemController;
use App\Http\Controllers\ReproductiveProblem\ReproductiveProblemController;
use App\Http\Controllers\CalvingType\CalvingTypeController;
use App\Http\Controllers\CalvingProblem\CalvingProblemController;
use App\Http\Controllers\Logs\Birth\BirthEventController;
use App\Http\Controllers\Logs\AbortedPregnancy\AbortedPregnancyController;


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
                'data' => $request->user()
            ]);
        });

        /*
        | User Management Routes (System User Only)
        |--------------------------------------------------------------------------
        */
        Route::prefix('users')->middleware('check.role:' . UserRole::SYSTEM_USER)->group(function () {
            Route::get('/', [UserController::class, 'index']);
            Route::post('/', [UserController::class, 'store']);
            Route::get('/statistics', [UserController::class, 'statistics']);
            Route::get('/{user}', [UserController::class, 'show']);
            Route::put('/{user}', [UserController::class, 'update']);
            Route::delete('/{user}', [UserController::class, 'destroy']);
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
        Route::prefix('farmers')->middleware('check.role:' . UserRole::FARMER . ',' . UserRole::SYSTEM_USER)->group(function () {
            // Extension Officer Farm Invite Routes
            Route::prefix('extension-officer-invites')->group(function () {
                Route::get('/search', [ExtensionOfficerFarmInviteController::class, 'searchByEmail']);
                Route::post('/', [ExtensionOfficerFarmInviteController::class, 'store']);
            });
        });

        // Extension Officer Routes
        Route::prefix('extension-officers')->middleware('check.role:' . UserRole::EXTENSION_OFFICER . ',' . UserRole::SYSTEM_USER)->group(function () {
            // Extension officer-specific routes can be added here
        });

        // Veterinarian Routes
        Route::prefix('vets')->middleware('check.role:' . UserRole::VET . ',' . UserRole::SYSTEM_USER)->group(function () {
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
