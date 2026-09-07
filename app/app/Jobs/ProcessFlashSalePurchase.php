<?php

namespace App\Jobs;

use App\Exceptions\InsufficientStockException;
use App\Models\FlashSaleItem;
use App\Models\User;
use App\Services\OrderService;
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
     * Retries are handled internally by OrderService's own optimistic-lock
     * retry loop, so the job itself only ever gets one shot — re-queuing
     * the whole job on failure would double-charge the retry budget and
     * make the reference status flap between pending/failed.
     */
    public int $tries = 1;

    public function __construct(
        public readonly string $referenceId,
        public readonly int $userId,
        public readonly int $flashSaleItemId,
        public readonly int $quantity,
        public readonly int $shippingAddressId,
        public readonly ?int $billingAddressId,
    ) {
    }

    public function handle(OrderService $orderService): void
    {
        $cacheKey = "flash_sale_purchase:{$this->referenceId}";

        try {
            $user = User::findOrFail($this->userId);
            $flashSaleItem = FlashSaleItem::findOrFail($this->flashSaleItemId);

            $order = $orderService->createFromFlashSalePurchase(
                user: $user,
                flashSaleItem: $flashSaleItem,
                quantity: $this->quantity,
                shippingAddressId: $this->shippingAddressId,
                billingAddressId: $this->billingAddressId,
            );

            Cache::put($cacheKey, [
                'status' => 'completed',
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'total_price' => $order->total_price,
            ], now()->addMinutes(15));
        } catch (InsufficientStockException $e) {
            // Expected outcome under real flash-sale contention — not an error.
            Cache::put($cacheKey, [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ], now()->addMinutes(15));
        } catch (Throwable $e) {
            Log::error('Flash sale purchase job failed unexpectedly', [
                'reference_id' => $this->referenceId,
                'flash_sale_item_id' => $this->flashSaleItemId,
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);

            Cache::put($cacheKey, [
                'status' => 'failed',
                'message' => 'Something went wrong processing your purchase. Please try again.',
            ], now()->addMinutes(15));
        }
    }
}
