<?php

use App\Enums\UserRole;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PhoneAuthController;
use App\Http\Controllers\BirthProblem\BirthProblemController;
use App\Http\Controllers\BirthType\BirthTypeController;
use App\Http\Controllers\Career\CareerApplicationController;
use App\Http\Controllers\Career\CareerJobController;
use App\Http\Controllers\Career\CareerProfileController;
use App\Http\Controllers\Career\CareerSavedJobController;
use App\Http\Controllers\Career\EmployerAccessController;
use App\Http\Controllers\Career\HiringController;
use App\Http\Controllers\Dairy\CentreStockController;
use App\Http\Controllers\Dairy\CollectionCentreController;
use App\Http\Controllers\Dairy\CooperativeDispatchController;
use App\Http\Controllers\Dairy\CooperativeFarmerController;
use App\Http\Controllers\Dairy\CooperativeIntakeController;
use App\Http\Controllers\Dairy\CooperativeOrganizationController;
use App\Http\Controllers\Dairy\CooperativeSeasonController;
use App\Http\Controllers\Dairy\CooperativeUserMembershipController;
use App\Http\Controllers\Dairy\DriverTripController;
use App\Http\Controllers\Dairy\FactoryReceiptController;
use App\Http\Controllers\Dairy\FarmerDeliveryController;
use App\Http\Controllers\Dairy\FarmerLedgerController;
use App\Http\Controllers\Dairy\MilkRoleMembershipController;
use App\Http\Controllers\Dairy\TransporterFleetController;
use App\Http\Controllers\Dairy\TransporterRegistrationController;
use App\Http\Controllers\Dynamic\DynamicFileController;
use App\Http\Controllers\Dynamic\DynamicPlatformController;
use App\Http\Controllers\Dynamic\ReportController;
use App\Http\Controllers\Dynamic\SyncFeedController;
use App\Http\Controllers\ExtensionOfficerFarmInvite\ExtensionOfficerFarmInviteController;
use App\Http\Controllers\Farm\FarmController;
use App\Http\Controllers\FarmAccessController;
use App\Http\Controllers\FeatureMembershipController;
use App\Http\Controllers\LivestockMarkingType\LivestockMarkingTypeController;
use App\Http\Controllers\Logs\AbortedPregnancy\AbortedPregnancyController;
use App\Http\Controllers\Logs\Birth\BirthEventController;
use App\Http\Controllers\Marketplace\MarketplaceListingController;
use App\Http\Controllers\Marketplace\MarketplaceMessageController;
use App\Http\Controllers\Marketplace\MarketplaceOfferController;
use App\Http\Controllers\Marketplace\MarketplaceOrderController;
use App\Http\Controllers\ReproductiveProblem\ReproductiveProblemController;
use App\Http\Controllers\Stage\StageController;
use App\Http\Controllers\Sync\SyncController;
use App\Http\Controllers\TailDockingMethod\TailDockingMethodController;
use App\Http\Controllers\TeethClippingMethod\TeethClippingMethodController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsureActiveAccount;
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
    Route::prefix('phone')->middleware('throttle:10,1')->group(function () {
        Route::post('/check', [PhoneAuthController::class, 'check']);
        Route::post('/send-otp', [PhoneAuthController::class, 'sendOtp']);
        Route::post('/verify-otp', [PhoneAuthController::class, 'verifyOtp']);
        Route::post('/complete', [PhoneAuthController::class, 'complete']);
        Route::post('/login', [PhoneAuthController::class, 'login']);
    });
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

Route::get('/v2/marketplace/photos/{filename}', [MarketplaceListingController::class, 'photo']);

/*
|--------------------------------------------------------------------------
| Protected Routes (Authentication Required)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', EnsureActiveAccount::class])->group(function () {
    Route::match(['get', 'post'], '/auth/refresh', [PhoneAuthController::class, 'refresh']);
    Route::get('/v2/farms/{farmUuid}/access', [FarmAccessController::class, 'current']);
    Route::get('/v2/farms/{farmUuid}/members', [FarmAccessController::class, 'index']);
    Route::get('/v2/farms/{farmUuid}/members/search', [FarmAccessController::class, 'search'])->middleware('throttle:10,1');
    Route::put('/v2/farms/{farmUuid}/members/{user}', [FarmAccessController::class, 'update']);
    Route::delete('/v2/farms/{farmUuid}/members/{user}', [FarmAccessController::class, 'revoke']);
    Route::get('/v2/memberships', [FeatureMembershipController::class, 'index']);
    Route::post('/v2/memberships', [FeatureMembershipController::class, 'store']);
    Route::get('/v2/organizations', [FeatureMembershipController::class, 'organizations']);
    Route::post('/v2/organizations', [FeatureMembershipController::class, 'createOrganization']);
    Route::get('/v2/hiring/my-access', [HiringController::class, 'myAccess']);
    Route::get('/v2/hiring/{onboarding}', [HiringController::class, 'show'])->whereNumber('onboarding');
    Route::get('/v2/hiring/options', [HiringController::class, 'options']);
    Route::get('/v2/career/applications/{application}/joining', [HiringController::class, 'showForApplication']);
    Route::post('/v2/career/applications/{application}/joining', [HiringController::class, 'send']);
    Route::put('/v2/hiring/{onboarding}', [HiringController::class, 'update']);
    Route::post('/v2/hiring/{onboarding}/response', [HiringController::class, 'respond']);
    Route::post('/v2/hiring/{onboarding}/activate', [HiringController::class, 'activate']);
    Route::post('/v2/hiring/{onboarding}/end', [HiringController::class, 'end']);
    Route::post('/v2/hiring/{onboarding}/arrival', [HiringController::class, 'arrival']);
    Route::post('/v2/hiring/{onboarding}/reminder', [HiringController::class, 'reminder'])->middleware('throttle:10,1');
    Route::put('/v2/hiring/{onboarding}/permissions', [HiringController::class, 'permissions']);
    Route::get('/v2/farms/{farmUuid}/workers', [HiringController::class, 'workers']);
    Route::post('/v2/farms/{farmUuid}/workers', [HiringController::class, 'register']);
    Route::get('/v2/hiring-notifications', [HiringController::class, 'notifications']);
    Route::post('/v2/hiring-notifications/{notification}/read', [HiringController::class, 'read']);
    Route::get('/v2/career/jobs', [CareerJobController::class, 'index']);
    Route::get('/v2/career/catalog', [CareerJobController::class, 'catalog']);
    Route::get('/v2/career/employer/members', [EmployerAccessController::class, 'index']);
    Route::get('/v2/career/employer/members/search', [EmployerAccessController::class, 'search'])->middleware('throttle:10,1');
    Route::put('/v2/career/employer/members/{user}', [EmployerAccessController::class, 'update']);
    Route::post('/v2/career/jobs', [CareerJobController::class, 'store']);
    Route::post('/v2/career/jobs/{job}/close', [CareerJobController::class, 'close']);
    Route::post('/v2/career/jobs/{job}/apply', [CareerApplicationController::class, 'apply']);
    Route::get('/v2/career/jobs/{job}/applications', [CareerApplicationController::class, 'applications']);
    Route::patch('/v2/career/jobs/{job}/applications/{application}', [CareerApplicationController::class, 'updateApplication']);
    Route::get('/v2/career/applications', [CareerApplicationController::class, 'myApplications']);
    Route::get('/v2/career/saved', [CareerSavedJobController::class, 'saved']);
    Route::put('/v2/career/jobs/{job}/saved', [CareerSavedJobController::class, 'save']);
    Route::delete('/v2/career/jobs/{job}/saved', [CareerSavedJobController::class, 'unsave']);
    Route::get('/v2/career/profile', [CareerProfileController::class, 'profile']);
    Route::post('/v2/career/profile', [CareerProfileController::class, 'updateProfile']);

    /*
    | Marketplace & Commerce Routes (V2)
    |--------------------------------------------------------------------------
    */
    Route::prefix('v2/marketplace')->group(function () {
        Route::get('/seller/livestock', [MarketplaceListingController::class, 'sellerLivestock']);
        Route::get('/listings', [MarketplaceListingController::class, 'listings']);
        Route::post('/listings', [MarketplaceListingController::class, 'createListing']);
        Route::get('/listings/{listing}', [MarketplaceListingController::class, 'showListing']);
        Route::put('/listings/{listing}', [MarketplaceListingController::class, 'updateListing']);
        Route::post('/listings/{listing}/withdraw', [MarketplaceListingController::class, 'withdrawListing']);
        Route::post('/listings/{listing}/relist', [MarketplaceListingController::class, 'relistListing']);

        Route::get('/offers', [MarketplaceOfferController::class, 'offers']);
        Route::post('/offers', [MarketplaceOfferController::class, 'createOffer']);
        Route::post('/offers/{offer}/respond', [MarketplaceOfferController::class, 'respondToOffer']);

        Route::post('/checkout', [MarketplaceOrderController::class, 'checkout']);

        Route::get('/orders', [MarketplaceOrderController::class, 'orders']);
        Route::get('/orders/{order}', [MarketplaceOrderController::class, 'showOrder']);
        Route::post('/orders/{order}/payment-evidence', [MarketplaceOrderController::class, 'submitPaymentEvidence']);
        Route::post('/orders/{order}/verify-payment', [MarketplaceOrderController::class, 'verifyPayment']);
        Route::post('/orders/{order}/handover', [MarketplaceOrderController::class, 'confirmHandover']);
        Route::post('/orders/{order}/cancel', [MarketplaceOrderController::class, 'cancelOrder']);
        Route::post('/orders/{order}/dispute', [MarketplaceOrderController::class, 'disputeOrder']);

        Route::get('/conversations', [MarketplaceMessageController::class, 'conversations']);
        Route::get('/messages', [MarketplaceMessageController::class, 'messages']);
        Route::post('/messages', [MarketplaceMessageController::class, 'send']);
        Route::delete('/messages/{uuid}', [MarketplaceMessageController::class, 'destroy']);
    });

    /*
    | Cooperative / Dairy Aggregation & Collection Centre Routes (V2)
    | Hierarchy: MCU -> AMCOS -> Farmer, Collection Centre (CC)
    |--------------------------------------------------------------------------
    */
    Route::prefix('v2/dairy')->group(function () {
        Route::get('/memberships/transportation', [MilkRoleMembershipController::class, 'transportation']);
        Route::get('/memberships/factories', [MilkRoleMembershipController::class, 'factories']);
        Route::post('/transporter-registration', [TransporterRegistrationController::class, 'store']);
        Route::get('/transporter-registration/mine', [TransporterRegistrationController::class, 'mine']);
        Route::get('/hierarchy', [CooperativeOrganizationController::class, 'hierarchy']);
        Route::post('/organizations', [CooperativeOrganizationController::class, 'saveOrganization']);
        Route::post('/amcos-membership', [CooperativeUserMembershipController::class, 'saveAmcosUserMembership']);
        Route::get('/amcos-membership/mine', [CooperativeUserMembershipController::class, 'myMemberships']);
        Route::get('/amcos-membership/pending', [CooperativeUserMembershipController::class, 'pending']);
        Route::post('/amcos-membership/{uuid}/review', [CooperativeUserMembershipController::class, 'review']);
        Route::post('/centres', [CollectionCentreController::class, 'saveCentre']);
        Route::post('/seasons', [CooperativeSeasonController::class, 'saveSeason']);
        Route::get('/farmers', [CooperativeFarmerController::class, 'listFarmers']);
        Route::post('/farmers/request-otp', [CooperativeFarmerController::class, 'requestFarmerEnrollmentOtp']);
        Route::post('/farmers/enroll', [CooperativeFarmerController::class, 'enrollFarmer']);
        Route::post('/farmers/verify-otp', [CooperativeFarmerController::class, 'verifyFarmerEnrollmentOtp']);
        Route::get('/intakes', [CooperativeIntakeController::class, 'listIntakes']);
        Route::post('/intakes', [CooperativeIntakeController::class, 'recordIntake']);
        Route::post('/intakes/{uuid}/review', [FarmerDeliveryController::class, 'review']);
        Route::post('/deliveries', [FarmerDeliveryController::class, 'declare']);
        Route::post('/centre-stock/movement', [CentreStockController::class, 'recordCentreStockMovement']);
        Route::get('/dispatches', [CooperativeDispatchController::class, 'listDispatches']);
        Route::get('/driver/routes', [DriverTripController::class, 'routes']);
        Route::get('/driver/trips', [DriverTripController::class, 'trips']);
        Route::post('/driver/trips', [DriverTripController::class, 'store']);
        Route::post('/driver/trips/{uuid}/routes', [DriverTripController::class, 'addRoutes']);
        Route::post('/driver/trips/{uuid}/start', [DriverTripController::class, 'start']);
        Route::post('/driver/trips/{uuid}/arrive', [DriverTripController::class, 'arrive']);
        Route::get('/factory/trips', [FactoryReceiptController::class, 'trips']);
        Route::post('/factory/receipts', [FactoryReceiptController::class, 'receive']);
        Route::post('/factory/trips/{uuid}/decision', [FactoryReceiptController::class, 'decide']);
        Route::get('/transporter/fleet', [TransporterFleetController::class, 'fleet']);
        Route::get('/transporter/trips', [TransporterFleetController::class, 'trips']);
        Route::post('/transporter/vehicles', [TransporterFleetController::class, 'storeVehicle']);
        Route::post('/transporter/drivers', [TransporterFleetController::class, 'storeDriver']);
        Route::post('/transporter/drivers/{uuid}/assign', [TransporterFleetController::class, 'assignDriver']);
        Route::post('/transporter/vehicles/{uuid}/edit', [TransporterFleetController::class, 'editVehicle']);
        Route::post('/transporter/drivers/{uuid}/edit', [TransporterFleetController::class, 'editDriver']);
        Route::post('/transporter/vehicles/{uuid}/status', [TransporterFleetController::class, 'vehicleStatus']);
        Route::post('/transporter/drivers/{uuid}/status', [TransporterFleetController::class, 'driverStatus']);
        Route::get('/transporter/trips/pending-approval', [CooperativeDispatchController::class, 'transporterTripsPending']);
        Route::post('/transporter/trips/{uuid}/review', [TransporterFleetController::class, 'reviewTrip']);
        Route::get('/ledger', [FarmerLedgerController::class, 'ledger']);
        Route::post('/ledger/entry', [FarmerLedgerController::class, 'recordLedgerEntry']);
    });

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
        Route::get('/transfer-target-farms', [FarmController::class, 'transferTargets'])
            ->middleware('check.role:'.UserRole::FARMER.','.UserRole::SYSTEM_USER);

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
