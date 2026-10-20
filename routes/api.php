<?php

use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\CashierController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Controllers\Api\V1\VoucherController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — SkyRental (RentCore) Multi-Tenant SaaS Platform
|--------------------------------------------------------------------------
|
| Versioned RESTful API endpoints for Flutter mobile clients & web portals.
|
*/

Route::prefix('v1')->group(function () {

    // --- Authentication (Public) ---
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/google', [AuthController::class, 'googleSignIn']);
    Route::post('/auth/google-sign-in', [AuthController::class, 'googleSignIn']);

    // --- Protected Routes (Sanctum Authenticated) ---
    Route::middleware('auth:sanctum')->group(function () {

        // Session & Profile
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Business Onboarding & Organization Management (Non-tenant scoped)
        Route::get('/businesses', [BusinessController::class, 'index']);
        Route::post('/businesses', [BusinessController::class, 'store']);
        Route::get('/businesses/{id}', [BusinessController::class, 'show']);

        // --- Tenant Scoped Operations (Guarded by TenantContextMiddleware) ---
        Route::middleware('tenant.context')->group(function () {

            // Business & Branch Settings
            Route::put('/businesses/{id}', [BusinessController::class, 'update']);
            Route::get('/businesses/{id}/branches', [BusinessController::class, 'branches']);
            Route::get('/businesses/{id}/members', [BusinessController::class, 'members']);
            Route::post('/businesses/{id}/members', [BusinessController::class, 'addMember']);
            Route::post('/businesses/{id}/branches', [BusinessController::class, 'addBranch']);

            // Dashboard KPI & Overdue Alerts
            Route::get('/dashboard/kpi', [DashboardController::class, 'kpi']);

            // Categories & Taxonomy
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

            // Inter-Affiliate Stock Transfers
            Route::get('/transfers', [TransferController::class, 'index']);
            Route::post('/transfers', [TransferController::class, 'store']);
            Route::get('/transfers/destinations', [TransferController::class, 'destinationAffiliates']);
            Route::get('/transfers/recipients', [TransferController::class, 'recipients']);
            Route::post('/transfers/{id}/approve', [TransferController::class, 'approve']);
            Route::post('/transfers/{id}/reject', [TransferController::class, 'reject']);
            Route::post('/transfers/{id}/receive', [TransferController::class, 'receive']);
            Route::post('/transfers/{id}/cancel', [TransferController::class, 'cancel']);

            // Vouchers & Thermal Printing
            Route::get('/vouchers', [VoucherController::class, 'index']);
            Route::post('/vouchers', [VoucherController::class, 'store']);
            Route::get('/vouchers/{id}', [VoucherController::class, 'show'])->whereNumber('id');
            Route::put('/vouchers/{id}', [VoucherController::class, 'update'])->whereNumber('id');
            Route::patch('/vouchers/{id}/toggle-status', [VoucherController::class, 'toggleStatus'])->whereNumber('id');
            Route::delete('/vouchers/{id}', [VoucherController::class, 'destroy'])->whereNumber('id');
            Route::post('/vouchers/validate', [VoucherController::class, 'validateVoucher']);
            Route::get('/vouchers/{code}/print-slip', [VoucherController::class, 'printSlip']);

            // Bookings & Direct Counter Rentals
            Route::get('/bookings', [BookingController::class, 'index']);
            Route::post('/bookings', [BookingController::class, 'store']);
            Route::get('/bookings/{id}', [BookingController::class, 'show']);
            Route::post('/bookings/{id}/extend', [BookingController::class, 'extend']);
            Route::post('/bookings/{id}/return', [BookingController::class, 'processReturn']);
            Route::get('/bookings/{id}/available-replacements', [BookingController::class, 'availableReplacements']);
            Route::post('/bookings/{id}/exchange', [BookingController::class, 'exchange']);

            // Customers & Identity Verification
            Route::get('/customers', [CustomerController::class, 'index']);
            Route::get('/customers/lookup', [CustomerController::class, 'lookup']);
            Route::post('/customers', [CustomerController::class, 'store']);
            Route::post('/customers/{id}/identity-document', [CustomerController::class, 'uploadIdentityDocument']);
            Route::put('/customers/{id}/blacklist', [CustomerController::class, 'toggleBlacklist']);

            // Cashier & Financials
            Route::get('/cashier/closing-summary', [CashierController::class, 'closingSummary']);
            Route::get('/cashier/payment-methods', [CashierController::class, 'paymentMethods']);
            Route::post('/cashier/pay', [CashierController::class, 'processPayment']);
            Route::post('/cashier/transactions/{id}/void', [CashierController::class, 'voidTransaction']);
            Route::get('/cashier/transactions/{id}/receipt', [CashierController::class, 'receipt']);

            // Branch Operating Expenses
            Route::get('/cashier/expenses', [ExpenseController::class, 'index']);
            Route::post('/cashier/expenses', [ExpenseController::class, 'store']);
            Route::post('/cashier/expenses/{id}/approve', [ExpenseController::class, 'approve']);

            // Cash Drawer Shifts
            Route::get('/cashier/shift/current', [ShiftController::class, 'currentShift']);
            Route::post('/cashier/shift/open', [ShiftController::class, 'openShift']);
            Route::post('/cashier/shift/close', [ShiftController::class, 'closeShift']);
        });
    });
});
