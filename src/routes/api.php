<?php

use App\Enums\Ability;
use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->controller(AuthController::class)->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('register', 'register')->name('register')->middleware('throttle:register');
        Route::post('login', 'login')->name('login');
    });

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('logout', 'logout')->name('logout');
        Route::get('me', 'me')->name('me');
    });
});

Route::middleware('throttle:public')->prefix('plans')->name('plans.')->controller(PlanController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('{slug}', 'show')->name('show')->where('slug', '[a-z0-9-]{1,255}');
});

Route::middleware(['auth:sanctum', 'throttle:api'])->prefix('admin/plans')->name('admin.plans.')->controller(PlanController::class)->group(function () {
    Route::post('/', 'store')->name('store')->can(Ability::ManagePlans->value);
    Route::patch('{id}', 'update')->name('update')->can(Ability::ManagePlans->value);
    Route::delete('{id}', 'destroy')->name('destroy')->can(Ability::ManagePlans->value);
});

Route::middleware(['auth:sanctum', 'throttle:api'])->prefix('admin')->name('admin.')->controller(AdminController::class)->group(function () {
    Route::get('tenants', 'tenants')->name('tenants.index')->can(Ability::ManageTenants->value);
    Route::patch('tenants/{id}', 'updateTenant')->name('tenants.update')->can(Ability::ManageTenants->value);
    Route::get('analytics', 'analytics')->name('analytics')->can(Ability::ViewPlatformAnalytics->value);
});

Route::middleware(['auth:sanctum', 'throttle:api', 'tenant'])->group(function () {
    Route::prefix('tenant')->name('tenant.')->controller(TenantController::class)->group(function () {
        Route::get('/', 'show')->name('show')->can(Ability::ViewTenant->value);
        Route::patch('/', 'update')->name('update')->can(Ability::UpdateTenant->value);
    });

    Route::prefix('customers')->name('customers.')->controller(CustomerController::class)->group(function () {
        Route::get('/', 'index')->name('index')->can(Ability::ViewCustomers->value);
        Route::post('/', 'store')->name('store')->can(Ability::CreateCustomers->value);
        Route::get('{id}', 'show')->name('show')->can(Ability::ViewCustomers->value);
        Route::patch('{id}', 'update')->name('update')->can(Ability::UpdateCustomers->value);
        Route::delete('{id}', 'destroy')->name('destroy')->can(Ability::DeleteCustomers->value);
    });

    Route::prefix('users')->name('users.')->controller(UserController::class)->group(function () {
        Route::get('/', 'index')->name('index')->can(Ability::ViewUsers->value);
        Route::post('/', 'store')->name('store')->can(Ability::CreateUsers->value);
        Route::get('{id}', 'show')->name('show')->can(Ability::ViewUsers->value);
        Route::patch('{id}', 'update')->name('update')->can(Ability::UpdateUsers->value);
        Route::delete('{id}', 'destroy')->name('destroy')->can(Ability::DeleteUsers->value);
    });

    Route::get('dashboard/analytics', [DashboardController::class, 'analytics'])->name('dashboard.analytics')->can(Ability::ViewDashboard->value);

    Route::prefix('subscription')->name('subscription.')->controller(SubscriptionController::class)->group(function () {
        Route::get('/', 'show')->name('show')->can(Ability::ViewSubscription->value);
        Route::post('/', 'store')->name('store')->can(Ability::ManageSubscription->value);
        Route::patch('/', 'update')->name('update')->can(Ability::ManageSubscription->value);
        Route::delete('/', 'destroy')->name('destroy')->can(Ability::ManageSubscription->value);
        Route::get('usage', 'usage')->name('usage')->can(Ability::ViewSubscription->value);
    });
});
