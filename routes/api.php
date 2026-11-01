<?php

use App\Enums\UserRole;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BirthProblem\BirthProblemController;
use App\Http\Controllers\BirthType\BirthTypeController;
use App\Http\Controllers\Dynamic\DynamicFileController;
use App\Http\Controllers\Dynamic\DynamicPlatformController;
use App\Http\Controllers\Dynamic\ReportController;
use App\Http\Controllers\Dynamic\SyncFeedController;
use App\Http\Controllers\ExtensionOfficerFarmInvite\ExtensionOfficerFarmInviteController;
use App\Http\Controllers\LivestockMarkingType\LivestockMarkingTypeController;
use App\Http\Controllers\Logs\AbortedPregnancy\AbortedPregnancyController;
use App\Http\Controllers\Logs\Birth\BirthEventController;
use App\Http\Controllers\ReproductiveProblem\ReproductiveProblemController;
use App\Http\Controllers\Stage\StageController;
use App\Http\Controllers\Sync\SyncController;
use App\Http\Controllers\TailDockingMethod\TailDockingMethodController;
use App\Http\Controllers\TeethClippingMethod\TeethClippingMethodController;
use App\Http\Controllers\UserController;
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
    Route::middleware('app.version')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/extension-officer/login', [AuthController::class, 'extensionOfficerLogin']);
    });

    // Forgot Password Routes
    Route::post('/forgot-password/send-otp', [AuthController::class, 'sendOtp']);
    Route::post('/forgot-password/verify-otp', [AuthController::class, 'verifyOtp']);
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
        | Backend user-management APIs for authenticated system users.
        | The Filament Portal manages users through the shared database and does
        | not call these mobile/backend routes.
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

        Route::prefix('config')->group(function () {
            Route::get('/manifest', [DynamicPlatformController::class, 'manifest']);
        });

        Route::prefix('dynamic')->group(function () {
            Route::get('/tombstones', [DynamicPlatformController::class, 'tombstones']);
            Route::get('/subjects', [DynamicPlatformController::class, 'subjects']);
            Route::post('/subjects', [DynamicPlatformController::class, 'registerSubject']);
            Route::post('/subjects/{subjectUuid}', [DynamicPlatformController::class, 'updateSubject']);
            Route::get('/subjects/{subjectUuid}/events', [DynamicPlatformController::class, 'events']);
            Route::post('/subjects/{subjectUuid}/events', [DynamicPlatformController::class, 'submitEvent']);
            Route::post('/events/{eventUuid}/void', [DynamicPlatformController::class, 'voidEvent']);
            Route::post('/files', [DynamicFileController::class, 'store']);
            Route::get('/files/{uuid}', [DynamicFileController::class, 'show']);
        });

        Route::get('/sync/changes', [SyncFeedController::class, 'changes']);

        Route::prefix('reports')->group(function () {
            Route::get('/animals', [ReportController::class, 'animals']);
            Route::get('/events', [ReportController::class, 'events']);
            Route::get('/reproduction', [ReportController::class, 'reproduction']);
            Route::get('/production', [ReportController::class, 'production']);
            Route::get('/health', [ReportController::class, 'health']);
        });

        Route::prefix('reference')->group(function () {
            Route::get('teeth-clipping-methods', [TeethClippingMethodController::class, 'fetchAll']);
            Route::get('tail-docking-methods', [TailDockingMethodController::class, 'fetchAll']);
            Route::get('livestock-marking-types', [LivestockMarkingTypeController::class, 'fetchAll']);
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
            Route::apiResource('birth-events', BirthEventController::class)->only(['index', 'store']);
            Route::apiResource('aborted-pregnancies', AbortedPregnancyController::class)->only(['index', 'store']);
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
        });
    }); // End v1 prefix
}); // End auth:sanctum middleware
