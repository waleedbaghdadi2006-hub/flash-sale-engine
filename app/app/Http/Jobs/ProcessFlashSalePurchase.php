<?php

namespace App\Jobs;

use App\Exceptions\FlashSaleSoldOutException;
use App\Models\FlashSaleItem;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProcessFlashSalePurchase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** How many times to retry the optimistic-lock reservation on version conflicts. */
    private const MAX_LOCK_ATTEMPTS = 5;

    public int $tries = 3;

    public function __construct(
        public readonly string $referenceId,
        public readonly int $userId,
        public readonly int $flashSaleItemId,
        public readonly int $quantity,
        public readonly int $shippingAddressId,
        public readonly ?int $billingAddressId,
    ) {
    }

    public function handle(): void
    {
        $this->markStatus('processing');

        try {
            $order = $this->reserveAndCreateOrder();

            $this->markStatus('completed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);
        } catch (FlashSaleSoldOutException $e) {
            $this->markStatus('failed', ['reason' => 'sold_out', 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Flash sale purchase failed', [
                'reference_id' => $this->referenceId,
                'error' => $e->getMessage(),
            ]);

            $this->markStatus('failed', ['reason' => 'error', 'message' => 'Something went wrong processing your order.']);

            throw $e;
        }
    }

    /**
     * Retries the optimistic-lock reservation against both the flash sale
     * item and the underlying inventory row, then creates the order in a
     * single transaction once both reservations succeed.
     *
     * @throws FlashSaleSoldOutException
     */
    private function reserveAndCreateOrder(): Order
    {
        for ($attempt = 1; $attempt <= self::MAX_LOCK_ATTEMPTS; $attempt++) {
            $flashSaleItem = FlashSaleItem::findOrFail($this->flashSaleItemId);

            if ($flashSaleItem->remainingStock() < $this->quantity) {
                throw new FlashSaleSoldOutException();
            }

            $inventory = Inventory::where('product_id', $flashSaleItem->product_id)->firstOrFail();

            if ($inventory->quantity_available < $this->quantity) {
                throw new FlashSaleSoldOutException('This item is out of stock.');
            }

            if (! $flashSaleItem->tryReserve($this->quantity)) {
                // Another process updated the version first — reload and retry.
                usleep(random_int(10_000, 50_000));
                continue;
            }

            if (! $inventory->tryReserve($this->quantity)) {
                // Roll back the flash sale item reservation we just took,
                // then retry the whole pair from a fresh read.
                $this->releaseFlashSaleItemReservation($flashSaleItem->id, $this->quantity);
                usleep(random_int(10_000, 50_000));
                continue;
            }

            return $this->createOrder($flashSaleItem->fresh());
        }

        throw new FlashSaleSoldOutException('Could not reserve stock due to high demand — please try again.');
    }

    private function releaseFlashSaleItemReservation(int $flashSaleItemId, int $quantity): void
    {
        DB::table('flash_sale_items')
            ->where('id', $flashSaleItemId)
            ->update([
                'quantity_sold' => DB::raw("quantity_sold - {$quantity}"),
                'version' => DB::raw('version + 1'),
            ]);
    }

    private function createOrder(FlashSaleItem $flashSaleItem): Order
    {
        return DB::transaction(function () use ($flashSaleItem) {
            $unitPrice = $flashSaleItem->sale_price;
            $subtotal = round($unitPrice * $this->quantity, 2);

            $order = Order::create([
                'order_number' => 'FS-' . strtoupper(Str::random(10)),
                'user_id' => $this->userId,
                'flash_sale_id' => $flashSaleItem->flash_sale_id,
                'shipping_address_id' => $this->shippingAddressId,
                'billing_address_id' => $this->billingAddressId ?? $this->shippingAddressId,
                'subtotal' => $subtotal,
                'discount_amount' => 0,
                'shipping_amount' => 0,
                'tax_amount' => 0,
                'total_price' => $subtotal,
                'status' => 'pending',
            ]);

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $flashSaleItem->product_id,
                'product_name_snapshot' => $flashSaleItem->product->name,
                'quantity' => $this->quantity,
                'unit_price' => $unitPrice,
            ]);

            return $order;
        });
    }

    private function markStatus(string $status, array $extra = []): void
    {
        Cache::put(
            "flash_sale_purchase:{$this->referenceId}",
            array_merge(['status' => $status], $extra),
            now()->addMinutes(15)
        );
    }

    public function failed(Throwable $exception): void
    {
        $this->markStatus('failed', ['reason' => 'error', 'message' => 'Something went wrong processing your order.']);
    }
}
