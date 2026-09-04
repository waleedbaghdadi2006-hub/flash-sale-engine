<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\FlashSaleController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    Route::middleware('auth:api')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::prefix('products')->group(function () {
    Route::get('/', [ProductController::class, 'index']);
    Route::get('/{idOrSlug}', [ProductController::class, 'show']);

    // Mutating routes restricted to staff/admin accounts only.
    Route::middleware(['auth:api', 'role:admin,staff'])->group(function () {
        Route::post('/', [ProductController::class, 'store']);
        Route::post('/{id}/restore', [ProductController::class, 'restore']);
        Route::match(['put', 'patch'], '/{id}', [ProductController::class, 'update']);
        Route::delete('/{id}', [ProductController::class, 'destroy']);
    });
});

Route::prefix('flash-sales')->group(function () {
    // Public reads
    Route::get('/', [FlashSaleController::class, 'index']);
    Route::get('/{flashSale}', [FlashSaleController::class, 'show']);

    // Poll purchase outcome — must be registered before the purchase route
    // below if you ever nest it under {flashSale}; kept flat here since
    // purchaseStatus() only needs the reference, not the sale.
    Route::get('/purchases/{reference}/status', [FlashSaleController::class, 'purchaseStatus'])
        ->name('flash-sales.purchases.status');

    // Mutating routes restricted to staff/admin accounts only.
    Route::middleware(['auth:api', 'role:admin,staff'])->group(function () {
        Route::post('/', [FlashSaleController::class, 'store']);
        Route::match(['put', 'patch'], '/{flashSale}', [FlashSaleController::class, 'update']);
        Route::delete('/{flashSale}', [FlashSaleController::class, 'destroy']);

        Route::post('/{flashSale}/items', [FlashSaleController::class, 'addItem']);
        Route::delete('/{flashSale}/items/{item}', [FlashSaleController::class, 'removeItem']);
    });

    // Customer-facing purchase attempt — requires auth (purchase() calls
    // $request->user()) plus the flash_sale.active middleware your
    // controller's docblock calls out as a hard requirement.
    Route::middleware(['auth:api', 'flash_sale.active'])->group(function () {
        Route::post('/{flashSale}/purchase', [FlashSaleController::class, 'purchase']);
    });
});