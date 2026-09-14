<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Short-lived idempotency guard for flash-sale purchase attempts.
 *
 * The key is derived from the authenticated user and the complete purchase
 * intent. Cache::add() is Laravel's atomic "add only if absent" operation,
 * backed by SETNX when Redis is the active cache store.
 *
 * This guard is deliberately separate from stock reservation: a duplicate
 * request should never consume a second Redis stock unit, and a sold-out
 * request must release its idempotency slot so a later legitimate attempt
 * receives the real sold-out response rather than "already processing".
 */
class PurchaseIdempotencyGuard
{
    public function tryAcquire(
        int $userId,
        int $flashSaleItemId,
        int $quantity,
        int $shippingAddressId,
        ?int $billingAddressId,
    ): bool {
        $key = $this->key(
            $userId,
            $flashSaleItemId,
            $quantity,
            $shippingAddressId,
            $billingAddressId,
        );

        try {
            return Cache::add(
                $key,
                ['created_at' => now()->toIso8601String()],
                now()->addSeconds((int) config('flash_sale.purchase_idempotency_ttl_seconds', 10)),
            );
        } catch (Throwable $e) {
            // Idempotency is a hardening layer, not the authoritative stock
            // control. If cache is temporarily unavailable, do not block a
            // purchase that can still be protected by Redis stock + DB lock.
            Log::warning('PurchaseIdempotencyGuard: cache unavailable; allowing purchase attempt.', [
                'user_id' => $userId,
                'flash_sale_item_id' => $flashSaleItemId,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    public function release(
        int $userId,
        int $flashSaleItemId,
        int $quantity,
        int $shippingAddressId,
        ?int $billingAddressId,
    ): void {
        try {
            Cache::forget($this->key(
                $userId,
                $flashSaleItemId,
                $quantity,
                $shippingAddressId,
                $billingAddressId,
            ));
        } catch (Throwable $e) {
            Log::warning('PurchaseIdempotencyGuard: failed to release cache key.', [
                'user_id' => $userId,
                'flash_sale_item_id' => $flashSaleItemId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function key(
        int $userId,
        int $flashSaleItemId,
        int $quantity,
        int $shippingAddressId,
        ?int $billingAddressId,
    ): string {
        $fingerprint = hash('sha256', json_encode([
            'user_id' => $userId,
            'flash_sale_item_id' => $flashSaleItemId,
            'quantity' => $quantity,
            'shipping_address_id' => $shippingAddressId,
            'billing_address_id' => $billingAddressId,
        ], JSON_THROW_ON_ERROR));

        return "flash_sale_purchase:idempotency:{$fingerprint}";
    }
}
