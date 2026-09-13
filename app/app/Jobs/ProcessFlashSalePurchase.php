<?php

namespace App\Jobs;

use App\Exceptions\InsufficientStockException;
use App\Models\FlashSaleItem;
use App\Models\User;
use App\Services\FlashSaleStock;
use App\Services\PurchaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessFlashSalePurchase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The job is intentionally one-shot. PurchaseService performs the
     * authoritative flash-sale reservation inside the database transaction;
     * a failed attempt is returned to the client as a terminal result for
     * that purchase reference.
     */
    public int $tries = 1;

    public function __construct(
        public readonly string $referenceId,
        public readonly int $userId,
        public readonly int $flashSaleItemId,
        public readonly int $quantity,
        public readonly int $shippingAddressId,
        public readonly ?int $billingAddressId,
        /**
         * Whether FlashSaleController::purchase() already won this unit
         * from the Redis atomic counter (App\Services\FlashSaleStock)
         * before dispatching. When true and the DB-authoritative
         * reservation below fails for any reason, the unit must be handed
         * back via FlashSaleStock::release() — otherwise a transient
         * failure (crash recovery, a version-conflict retry loss, manual
         * DB edits) would permanently shrink the advertised Redis stock
         * even though no order was actually created.
         */
        public readonly bool $reservedViaRedis = false,
    ) {
        // Dedicated queue so flash-sale bursts are scaled (and alerted on)
        // independently of the default queue's supervisor — see
        // config/horizon.php's `supervisor-flash-sale`.
        $this->onQueue('flash-sale');
    }

    public function handle(PurchaseService $purchaseService, FlashSaleStock $flashSaleStock): void
    {
        $cacheKey = "flash_sale_purchase:{$this->referenceId}";

        try {
            $user = User::findOrFail($this->userId);
            $flashSaleItem = FlashSaleItem::findOrFail($this->flashSaleItemId);

            $order = $purchaseService->purchaseFlashSale(
                user: $user,
                flashSaleItem: $flashSaleItem,
                quantity: $this->quantity,
                shippingAddressId: $this->shippingAddressId,
                billingAddressId: $this->billingAddressId,
            );

            Cache::put($cacheKey, [
                'user_id' => $this->userId,
                'status' => 'completed',
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'total_price' => $order->total_price,
            ], now()->addMinutes(15));
        } catch (InsufficientStockException $e) {
            // Expected outcome under real flash-sale contention — not an error.
            $this->releaseRedisReservation($flashSaleStock);

            Cache::put($cacheKey, [
                'user_id' => $this->userId,
                'status' => 'failed',
                'message' => $e->getMessage(),
            ], now()->addMinutes(15));
        } catch (Throwable $e) {
            $this->releaseRedisReservation($flashSaleStock);

            Log::error('Flash sale purchase job failed unexpectedly', [
                'reference_id' => $this->referenceId,
                'flash_sale_item_id' => $this->flashSaleItemId,
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);

            Cache::put($cacheKey, [
                'user_id' => $this->userId,
                'status' => 'failed',
                'message' => 'Something went wrong processing your purchase. Please try again.',
            ], now()->addMinutes(15));
        }
    }

    /**
     * No-op unless this request actually won its unit from Redis first —
     * an UNAVAILABLE/disabled purchase never touched the counter, so
     * releasing here would wrongly inflate stock that was never taken.
     */
    private function releaseRedisReservation(FlashSaleStock $flashSaleStock): void
    {
        if ($this->reservedViaRedis) {
            $flashSaleStock->release($this->flashSaleItemId, $this->quantity);
        }
    }
}
