<?php

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Sync\SyncController;
use App\Http\Controllers\Location\LocationController;

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
        | Role-Based Module Routes
        |--------------------------------------------------------------------------
        */

        // Farmer Routes
        Route::prefix('farmers')->middleware('check.role:' . UserRole::FARMER . ',' . UserRole::SYSTEM_USER)->group(function () {

            /*
            | Sync Routes (Farmer-specific)
            |--------------------------------------------------------------------------
            */
            Route::prefix('sync')->group(function () {
                // Get all data on app startup based on user role
                Route::get('/splash-sync-all/{userId}', [SyncController::class, 'splashSync']);

                // Send unsynced data to server (farms, livestock, etc.)
                Route::post('/full-post-sync/{userId}', [SyncController::class, 'postSync']);
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

    }); // End v1 prefix

}); // End auth:sanctum middleware
