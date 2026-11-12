<?php

use App\Enums\UserRole;
use App\Http\Controllers\Admin\AccountProfileController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\AnimalResourceController;
use App\Http\Controllers\Admin\BlueprintResourceController;
use App\Http\Controllers\Admin\CareerResourceController;
use App\Http\Controllers\Admin\CommerceManagementController;
use App\Http\Controllers\Admin\CommerceResourceController;
use App\Http\Controllers\Admin\CooperativeRegistryController;
use App\Http\Controllers\Admin\DairyLogisticsController;
use App\Http\Controllers\Admin\DairyResourceController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EventResourceController;
use App\Http\Controllers\Admin\FactoryMemberController;
use App\Http\Controllers\Admin\FinanceResourceController;
use App\Http\Controllers\Admin\LocationResourceController;
use App\Http\Controllers\Admin\OtpVerificationController;
use App\Http\Controllers\Admin\PeopleResourceController;
use App\Http\Controllers\Admin\PlatformPermissionController;
use App\Http\Controllers\Admin\ReferenceResourceController;
use App\Http\Controllers\Admin\SecurityResourceController;
use App\Http\Controllers\Admin\TransporterFleetRegistryController;
use App\Http\Controllers\Admin\TransporterRegistryController;
use App\Http\Controllers\Admin\UserAccountController;
use App\Http\Controllers\Dairy\CooperativeUserMembershipController;
use App\Http\Controllers\Dairy\FactoryReceiptController;
use App\Http\Middleware\RequireAdminPermission;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/admin')
    ->middleware(['auth:sanctum', 'check.role:'.UserRole::SYSTEM_USER, RequireAdminPermission::class])
    ->group(function (): void {
        Route::get('/dashboard', DashboardController::class);
        Route::get('/analytics', AnalyticsController::class);

        // Self-service uses the authenticated account, without account-management permissions.
        Route::prefix('account')->withoutMiddleware(RequireAdminPermission::class)->group(function (): void {
            Route::get('/profile', [AccountProfileController::class, 'show']);
            Route::patch('/profile', [AccountProfileController::class, 'update'])->middleware('throttle:20,1');
            Route::get('/deletion-check', [AccountProfileController::class, 'deletionCheck']);
            Route::delete('/profile', [AccountProfileController::class, 'destroy'])->middleware('throttle:5,1');
        });

        Route::get('/otp-verifications', [OtpVerificationController::class, 'index']);
        Route::post('/otp-verifications', [OtpVerificationController::class, 'store'])->middleware('throttle:10,1');
        Route::delete('/otp-verifications/{source}/{record}', [OtpVerificationController::class, 'destroy'])->whereIn('source', ['phone', 'legacy'])->whereNumber('record');

        Route::get('/security/upgrade-runs', [SecurityResourceController::class, 'upgradeRuns']);
        Route::get('/security/ledger-migration', [SecurityResourceController::class, 'ledgerMigration']);
        Route::post('/security/ledger-migration/change-authority', [SecurityResourceController::class, 'changeAuthority']);

        Route::get('/users/options', [UserAccountController::class, 'options']);
        Route::get('/platform-permissions', [PlatformPermissionController::class, 'index']);
        Route::put('/platform-permissions/{user}', [PlatformPermissionController::class, 'update']);
        Route::get('/users', [UserAccountController::class, 'index']);
        Route::post('/users', [UserAccountController::class, 'store']);
        Route::get('/users/{user}', [UserAccountController::class, 'show']);
        Route::patch('/users/{user}', [UserAccountController::class, 'update']);
        Route::delete('/users/{user}', [UserAccountController::class, 'destroy']);

        Route::get('/people/{resource}/options', [PeopleResourceController::class, 'options']);
        Route::get('/people/{resource}', [PeopleResourceController::class, 'index']);
        Route::post('/people/{resource}', [PeopleResourceController::class, 'store']);
        Route::get('/people/{resource}/{record}', [PeopleResourceController::class, 'show']);
        Route::patch('/people/{resource}/{record}', [PeopleResourceController::class, 'update']);
        Route::post('/people/{resource}/{record}/restore', [PeopleResourceController::class, 'restore']);
        Route::delete('/people/{resource}/{record}', [PeopleResourceController::class, 'destroy']);

        Route::get('/locations/{resource}/options', [LocationResourceController::class, 'options']);
        Route::get('/locations/{resource}', [LocationResourceController::class, 'index']);
        Route::post('/locations/{resource}', [LocationResourceController::class, 'store']);
        Route::get('/locations/{resource}/{record}', [LocationResourceController::class, 'show']);
        Route::patch('/locations/{resource}/{record}', [LocationResourceController::class, 'update']);
        Route::delete('/locations/{resource}/{record}', [LocationResourceController::class, 'destroy']);

        Route::get('/animals/{resource}/options', [AnimalResourceController::class, 'options']);
        Route::get('/animals/{resource}', [AnimalResourceController::class, 'index']);
        Route::post('/animals/{resource}', [AnimalResourceController::class, 'store']);
        Route::get('/animals/{resource}/{record}', [AnimalResourceController::class, 'show']);
        Route::patch('/animals/{resource}/{record}', [AnimalResourceController::class, 'update']);
        Route::post('/animals/{resource}/{record}/restore', [AnimalResourceController::class, 'restore']);
        Route::delete('/animals/{resource}/{record}', [AnimalResourceController::class, 'destroy']);

        Route::get('/reference/{resource}/options', [ReferenceResourceController::class, 'options']);
        Route::get('/reference/{resource}', [ReferenceResourceController::class, 'index']);
        Route::post('/reference/{resource}', [ReferenceResourceController::class, 'store']);
        Route::get('/reference/{resource}/{record}', [ReferenceResourceController::class, 'show']);
        Route::patch('/reference/{resource}/{record}', [ReferenceResourceController::class, 'update']);
        Route::delete('/reference/{resource}/{record}', [ReferenceResourceController::class, 'destroy']);

        Route::get('/events/{resource}/options', [EventResourceController::class, 'options']);
        Route::get('/events/{resource}', [EventResourceController::class, 'index']);
        Route::post('/events/{resource}', [EventResourceController::class, 'store']);
        Route::get('/events/{resource}/{record}', [EventResourceController::class, 'show']);
        Route::patch('/events/{resource}/{record}', [EventResourceController::class, 'update']);
        Route::post('/events/{resource}/{record}/restore', [EventResourceController::class, 'restore']);
        Route::delete('/events/{resource}/{record}', [EventResourceController::class, 'destroy']);

        Route::get('/finance/{resource}/options', [FinanceResourceController::class, 'options']);
        Route::get('/finance/{resource}', [FinanceResourceController::class, 'index']);
        Route::post('/finance/{resource}', [FinanceResourceController::class, 'store']);
        Route::get('/finance/{resource}/{record}', [FinanceResourceController::class, 'show']);
        Route::patch('/finance/{resource}/{record}', [FinanceResourceController::class, 'update']);
        Route::delete('/finance/{resource}/{record}', [FinanceResourceController::class, 'destroy']);

        Route::get('/commerce/form/people', [CommerceManagementController::class, 'people']);
        Route::get('/commerce/form/farms/{farm}/livestock', [CommerceManagementController::class, 'farmLivestock']);
        Route::get('/commerce/form/holdable-livestock', [CommerceManagementController::class, 'holdableLivestock']);
        Route::get('/commerce/form/open-listings', [CommerceManagementController::class, 'openListings']);
        Route::post('/commerce/listings', [CommerceManagementController::class, 'storeListing']);
        Route::post('/commerce/orders', [CommerceManagementController::class, 'storeOrder']);
        Route::post('/commerce/reservations', [CommerceManagementController::class, 'storeHold']);
        Route::post('/commerce/reservations/{reservation}/release', [CommerceManagementController::class, 'releaseHold']);
        Route::get('/commerce/{resource}/options', [CommerceResourceController::class, 'options']);
        Route::get('/commerce/{resource}', [CommerceResourceController::class, 'index']);
        Route::get('/commerce/{resource}/{record}', [CommerceResourceController::class, 'show']);
        Route::post('/commerce/orders/{order}/resolve-dispute', [CommerceResourceController::class, 'resolveOrderDispute']);

        Route::get('/career/overview', [CareerResourceController::class, 'overview']);
        Route::post('/career/{resource}', [CareerResourceController::class, 'store']);
        Route::get('/career/{resource}/options', [CareerResourceController::class, 'options']);
        Route::get('/career/{resource}', [CareerResourceController::class, 'index']);
        Route::get('/career/{resource}/{record}', [CareerResourceController::class, 'show']);
        Route::patch('/career/{resource}/{record}', [CareerResourceController::class, 'update']);
        Route::delete('/career/{resource}/{record}', [CareerResourceController::class, 'destroy']);

        Route::get('/dairy/cooperatives/approvals', [CooperativeUserMembershipController::class, 'pending']);
        Route::post('/dairy/cooperatives/approvals/{uuid}/review', [CooperativeUserMembershipController::class, 'review']);
        Route::get('/dairy/cooperatives/options', [CooperativeRegistryController::class, 'options']);
        Route::get('/dairy/cooperatives/locations', [CooperativeRegistryController::class, 'locations']);
        Route::get('/dairy/cooperatives/{type}', [CooperativeRegistryController::class, 'index']);
        Route::post('/dairy/cooperatives/{type}', [CooperativeRegistryController::class, 'store']);
        Route::get('/dairy/cooperatives/{type}/{uuid}', [CooperativeRegistryController::class, 'show']);
        Route::patch('/dairy/cooperatives/{type}/{uuid}', [CooperativeRegistryController::class, 'update']);
        Route::get('/dairy/factories/{factory}/handovers', [FactoryReceiptController::class, 'portalIndex']);
        Route::post('/dairy/factories/{factory}/handovers/{uuid}/decision', [FactoryReceiptController::class, 'portalDecide']);
        Route::get('/dairy/factories/{uuid}/members', [FactoryMemberController::class, 'index']);
        Route::post('/dairy/factories/{uuid}/members', [FactoryMemberController::class, 'store']);
        Route::patch('/dairy/factories/{uuid}/members/{memberUuid}', [FactoryMemberController::class, 'update']);
        Route::delete('/dairy/factories/{uuid}/members/{memberUuid}', [FactoryMemberController::class, 'destroy']);
        Route::get('/dairy/transport/companies', [TransporterRegistryController::class, 'index']);
        Route::get('/dairy/transport/companies/{uuid}', [TransporterRegistryController::class, 'show']);
        Route::patch('/dairy/transport/companies/{uuid}', [TransporterRegistryController::class, 'update']);
        Route::delete('/dairy/transport/companies/{uuid}', [TransporterRegistryController::class, 'destroy']);
        Route::post('/dairy/transport/companies/{uuid}/restore', [TransporterRegistryController::class, 'restore']);
        Route::post('/dairy/transport/companies/{uuid}/approve', [TransporterRegistryController::class, 'approve']);
        Route::post('/dairy/transport/companies/{uuid}/reject', [TransporterRegistryController::class, 'reject']);
        Route::get('/dairy/transport/vehicles', [TransporterFleetRegistryController::class, 'vehicles']);
        Route::post('/dairy/transport/vehicles', [TransporterFleetRegistryController::class, 'storeVehicle']);
        Route::patch('/dairy/transport/vehicles/{uuid}', [TransporterFleetRegistryController::class, 'updateVehicle']);
        Route::delete('/dairy/transport/vehicles/{uuid}', [TransporterFleetRegistryController::class, 'archiveVehicle']);
        Route::post('/dairy/transport/vehicles/{uuid}/restore', [TransporterFleetRegistryController::class, 'restoreVehicle']);
        Route::get('/dairy/transport/drivers', [TransporterFleetRegistryController::class, 'drivers']);
        Route::post('/dairy/transport/drivers', [TransporterFleetRegistryController::class, 'storeDriver']);
        Route::patch('/dairy/transport/drivers/{uuid}', [TransporterFleetRegistryController::class, 'updateDriver']);
        Route::delete('/dairy/transport/drivers/{uuid}', [TransporterFleetRegistryController::class, 'archiveDriver']);
        Route::post('/dairy/transport/drivers/{uuid}/restore', [TransporterFleetRegistryController::class, 'restoreDriver']);
        Route::get('/dairy/{resource}/options', [DairyResourceController::class, 'options']);
        Route::get('/dairy/{resource}', [DairyResourceController::class, 'index']);
        Route::get('/dairy/{resource}/{record}', [DairyResourceController::class, 'show']);

        // Blueprint Engine Routes
        Route::get('/blueprints/{resource}/options', [BlueprintResourceController::class, 'options']);
        Route::get('/blueprints/{resource}', [BlueprintResourceController::class, 'index']);
        Route::post('/blueprints/{resource}', [BlueprintResourceController::class, 'store']);
        Route::get('/blueprints/{resource}/{record}', [BlueprintResourceController::class, 'show']);
        Route::get('/blueprints/{resource}/{record}/preview', [BlueprintResourceController::class, 'preview']);
        Route::patch('/blueprints/{resource}/{record}', [BlueprintResourceController::class, 'update']);
        Route::post('/blueprints/{resource}/{record}/clone', [BlueprintResourceController::class, 'clone']);
        Route::post('/blueprints/{resource}/{record}/publish', [BlueprintResourceController::class, 'publish']);
        Route::delete('/blueprints/{resource}/{record}', [BlueprintResourceController::class, 'destroy']);

        // Dairy Logistics & Tanker Movement Routes
        Route::get('/logistics/transporters', [DairyLogisticsController::class, 'listTransporters']);
        Route::post('/logistics/transporters', [DairyLogisticsController::class, 'storeTransporter']);
        Route::get('/logistics/transporters/{uuid}', [DairyLogisticsController::class, 'getTransporter']);
        Route::post('/logistics/transporters/{uuid}/approve', [DairyLogisticsController::class, 'approveTransporter']);
        Route::post('/logistics/transporters/{uuid}/reject', [DairyLogisticsController::class, 'rejectTransporter']);
        Route::post('/logistics/transporters/{uuid}/vehicles', [DairyLogisticsController::class, 'addVehicle']);
        Route::post('/logistics/transporters/{uuid}/drivers', [DairyLogisticsController::class, 'addDriver']);
        Route::get('/logistics/transporters/{uuid}/trips', [DairyLogisticsController::class, 'listTransporterTrips']);

        Route::get('/logistics/factories', [DairyLogisticsController::class, 'listFactories']);
        Route::post('/logistics/factories', [DairyLogisticsController::class, 'storeFactory']);
        Route::get('/logistics/factories/{uuid}', [DairyLogisticsController::class, 'getFactory']);
        Route::get('/logistics/factories/{uuid}/receipts', [DairyLogisticsController::class, 'getFactoryReceipts']);
        Route::post('/logistics/factories/{uuid}/approve', [DairyLogisticsController::class, 'approveFactory']);
        Route::post('/logistics/factories/{uuid}/reject', [DairyLogisticsController::class, 'rejectFactory']);
        Route::post('/logistics/factories/{factoryUuid}/receive', [DairyLogisticsController::class, 'receiveFactoryDelivery']);

        Route::get('/logistics/trips', [DairyLogisticsController::class, 'listTrips']);
        Route::post('/logistics/trips', [DairyLogisticsController::class, 'storeTrip']);
        Route::get('/logistics/trips/{uuid}', [DairyLogisticsController::class, 'getTrip']);
        Route::post('/logistics/trips/{uuid}/routes', [DairyLogisticsController::class, 'addRoutesToTrip']);
        Route::post('/logistics/trips/{uuid}/start', [DairyLogisticsController::class, 'startTrip']);
        Route::post('/logistics/trips/{uuid}/arrive', [DairyLogisticsController::class, 'arriveTrip']);
        Route::post('/logistics/trips/{uuid}/complete', [DairyLogisticsController::class, 'completeTrip']);
    });
