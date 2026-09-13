<?php

use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\AddressController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\FlashSaleController;
use App\Http\Controllers\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);

Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    Route::middleware(['auth:api', 'throttle:api'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::prefix('products')->middleware('throttle:api')->group(function () {
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

Route::prefix('flash-sales')->middleware('throttle:api')->group(function () {
    // Public reads
    Route::get('/', [FlashSaleController::class, 'index']);
    Route::get('/{flashSale}', [FlashSaleController::class, 'show']);

    // Poll purchase outcome — must be registered before the purchase route
    // below if you ever nest it under {flashSale}; kept flat here since
    // purchaseStatus() only needs the reference, not the sale.
    Route::get('/purchases/{reference}/status', [FlashSaleController::class, 'purchaseStatus'])->middleware('auth:api')
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

// Cart, checkout, addresses, and payments are all customer-facing and
// always operate on the authenticated user's own data.
Route::middleware(['auth:api', 'throttle:api'])->group(function () {
    Route::prefix('cart')->group(function () {
        Route::get('/', [CartController::class, 'show']);
        Route::delete('/', [CartController::class, 'clear']);
        Route::post('/buy-now', [CartController::class, 'buyNow']);

        Route::post('/items', [CartController::class, 'addItem']);
        Route::patch('/items/{item}', [CartController::class, 'updateItem']);
        Route::delete('/items/{item}', [CartController::class, 'removeItem']);
    });

    Route::prefix('orders')->group(function () {
        Route::get('/', [OrderController::class, 'index']);
        Route::get('/{id}', [OrderController::class, 'show']);
        Route::post('/', [OrderController::class, 'store']);
        Route::post('/{id}/cancel', [OrderController::class, 'cancel']);

        Route::get('/{order}/payments', [PaymentController::class, 'index']);
        Route::post('/{order}/payments', [PaymentController::class, 'store']);
    });

    Route::prefix('addresses')->group(function () {
        Route::get('/', [AddressController::class, 'index']);
        Route::post('/', [AddressController::class, 'store']);
        Route::match(['put', 'patch'], '/{id}', [AddressController::class, 'update']);
        Route::delete('/{id}', [AddressController::class, 'destroy']);
    });
});

// Admin-only coupon management.
Route::prefix('admin/coupons')->middleware(['auth:api', 'role:admin', 'throttle:api'])->group(function () {
    Route::get('/', [CouponController::class, 'index']);
    Route::get('/{id}', [CouponController::class, 'show']);
    Route::post('/', [CouponController::class, 'store']);
    Route::match(['put', 'patch'], '/{id}', [CouponController::class, 'update']);
    Route::delete('/{id}', [CouponController::class, 'destroy']);
    Route::post('/{id}/toggle', [CouponController::class, 'toggle']);

});

// Async payment-gateway confirmations. Deliberately outside auth:api because
// real gateways call this directly. Signature verification is intentionally
// disabled outside local/test until a real provider integration is installed.
Route::post('/webhooks/payments/{provider}', [PaymentWebhookController::class, 'handle'])->middleware('throttle:api');