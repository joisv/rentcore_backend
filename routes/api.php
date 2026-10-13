<?php

use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\CashierController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Controllers\Api\V1\VoucherController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — RentCore Electronic Rental Management System
|--------------------------------------------------------------------------
|
| Versioned RESTful API endpoints for Flutter mobile clients & web portals.
|
*/

Route::prefix('v1')->group(function () {

    // --- Authentication (Public) ---
    Route::post('/auth/login', [AuthController::class, 'login']);

    // --- Protected Routes (Sanctum Authenticated) ---
    Route::middleware('auth:sanctum')->group(function () {

        // Session & Profile
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Dashboard KPI & Overdue Alerts
        Route::get('/dashboard/kpi', [DashboardController::class, 'kpi']);

        // Categories & Taxonomy (Module 10 / Multi-Category)
        Route::get('/categories', [CategoryController::class, 'index']);
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::get('/categories/{id}', [CategoryController::class, 'show']);
        Route::put('/categories/{id}', [CategoryController::class, 'update']);
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

        // Rental Products / Asset Models & Configurable Rate Cards
        Route::get('/products', [ProductController::class, 'index']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::get('/products/{id}', [ProductController::class, 'show']);
        Route::put('/products/{id}', [ProductController::class, 'update']);
        Route::put('/products/{id}/rates', [ProductController::class, 'updateRates']);
        Route::post('/pricing/calculate', [ProductController::class, 'calculatePrice']);

        // Assets & Catalog (Read & Write CRUD)
        Route::get('/assets', [AssetController::class, 'index']);
        Route::post('/assets', [AssetController::class, 'store']);
        Route::get('/assets/models', [AssetController::class, 'models']);
        Route::get('/assets/{id}', [AssetController::class, 'show']);
        Route::put('/assets/{id}', [AssetController::class, 'update']);
        Route::get('/assets/{id}/availability', [AssetController::class, 'checkAvailability']);

        // Inter-Affiliate Stock Transfers (Module 9)
        Route::get('/transfers', [TransferController::class, 'index']);
        Route::post('/transfers', [TransferController::class, 'store']);
        Route::get('/transfers/destinations', [TransferController::class, 'destinationAffiliates']);
        Route::post('/transfers/{id}/receive', [TransferController::class, 'receive']);
        Route::post('/transfers/{id}/cancel', [TransferController::class, 'cancel']);

        // Vouchers & Thermal Printing (Super-Admin & Cashier)
        Route::get('/vouchers', [VoucherController::class, 'index']);
        Route::post('/vouchers', [VoucherController::class, 'store']); // Super-Admin guarded
        Route::post('/vouchers/validate', [VoucherController::class, 'validateVoucher']);
        Route::get('/vouchers/{code}/print-slip', [VoucherController::class, 'printSlip']);

        // Bookings & Direct Counter Rentals
        Route::get('/bookings', [BookingController::class, 'index']);
        Route::post('/bookings', [BookingController::class, 'store']);
        Route::get('/bookings/{id}', [BookingController::class, 'show']);
        Route::post('/bookings/{id}/extend', [BookingController::class, 'extend']);
        Route::post('/bookings/{id}/return', [BookingController::class, 'processReturn']);

        // Customers & Identity Verification (Module 3)
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::get('/customers/lookup', [CustomerController::class, 'lookup']);
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::post('/customers/{id}/identity-document', [CustomerController::class, 'uploadIdentityDocument']);
        Route::put('/customers/{id}/blacklist', [CustomerController::class, 'toggleBlacklist']);

        // Cashier & Financials
        Route::get('/cashier/closing-summary', [CashierController::class, 'closingSummary']);
        Route::get('/cashier/payment-methods', [CashierController::class, 'paymentMethods']);
        Route::post('/cashier/pay', [CashierController::class, 'processPayment']);
        Route::get('/cashier/transactions/{id}/receipt', [CashierController::class, 'receipt']);
    });
});
