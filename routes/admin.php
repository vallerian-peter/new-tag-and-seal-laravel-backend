<?php

use App\Enums\UserRole;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\AnimalResourceController;
use App\Http\Controllers\Admin\BlueprintResourceController;
use App\Http\Controllers\Admin\CommerceResourceController;
use App\Http\Controllers\Admin\DairyLogisticsController;
use App\Http\Controllers\Admin\DairyResourceController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EventResourceController;
use App\Http\Controllers\Admin\FinanceResourceController;
use App\Http\Controllers\Admin\LocationResourceController;
use App\Http\Controllers\Admin\PeopleResourceController;
use App\Http\Controllers\Admin\ReferenceResourceController;
use App\Http\Controllers\Admin\UserAccountController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/admin')
    ->middleware(['auth:sanctum', 'check.role:'.UserRole::SYSTEM_USER])
    ->group(function (): void {
        Route::get('/dashboard', DashboardController::class);
        Route::get('/analytics', AnalyticsController::class);

        Route::get('/users/options', [UserAccountController::class, 'options']);
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

        Route::get('/commerce/{resource}/options', [CommerceResourceController::class, 'options']);
        Route::get('/commerce/{resource}', [CommerceResourceController::class, 'index']);
        Route::get('/commerce/{resource}/{record}', [CommerceResourceController::class, 'show']);

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
        Route::get('/logistics/factories/{uuid}/inventory', [DairyLogisticsController::class, 'getFactoryInventory']);
        Route::post('/logistics/factories/{uuid}/approve', [DairyLogisticsController::class, 'approveFactory']);
        Route::post('/logistics/factories/{uuid}/reject', [DairyLogisticsController::class, 'rejectFactory']);
        Route::post('/logistics/factories/{uuid}/tanks', [DairyLogisticsController::class, 'addFactoryTank']);
        Route::get('/logistics/factories/{uuid}/tanks', [DairyLogisticsController::class, 'listFactoryTanks']);
        Route::post('/logistics/factories/{factoryUuid}/receive', [DairyLogisticsController::class, 'receiveFactoryDelivery']);

        Route::get('/logistics/trips', [DairyLogisticsController::class, 'listTrips']);
        Route::post('/logistics/trips', [DairyLogisticsController::class, 'storeTrip']);
        Route::get('/logistics/trips/{uuid}', [DairyLogisticsController::class, 'getTrip']);
        Route::post('/logistics/trips/{uuid}/routes', [DairyLogisticsController::class, 'addRoutesToTrip']);
        Route::post('/logistics/trips/{uuid}/start', [DairyLogisticsController::class, 'startTrip']);
        Route::post('/logistics/trips/{uuid}/arrive', [DairyLogisticsController::class, 'arriveTrip']);
        Route::post('/logistics/trips/{uuid}/complete', [DairyLogisticsController::class, 'completeTrip']);
    });
