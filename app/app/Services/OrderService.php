<?php

namespace App\Services;

use App\Models\FlashSaleItem;
use App\Models\Inventory;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Order lifecycle operations after an order has been created.
 * Purchase creation is centralized in PurchaseService.
 */
class OrderService
{
    public function cancel(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $order = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            foreach ($order->items()->lockForUpdate()->get() as $item) {
                if ($order->flash_sale_id !== null) {
                    $flashSaleItem = FlashSaleItem::where('flash_sale_id', $order->flash_sale_id)
                        ->where('product_id', $item->product_id)
                        ->lockForUpdate()
                        ->first();

                    if ($flashSaleItem) {
                        if ($flashSaleItem->quantity_sold < $item->quantity) {
                            throw new \RuntimeException('Flash sale reservation state is invalid.');
                        }

                        $flashSaleItem->update([
                            'quantity_sold' => $flashSaleItem->quantity_sold - $item->quantity,
                            'version' => $flashSaleItem->version + 1,
                        ]);
                        continue;
                    }
                }

                $inventory = Inventory::where('product_id', $item->product_id)
                    ->lockForUpdate()
                    ->first();

                if ($inventory) {
                    DB::table('inventory')
                        ->where('id', $inventory->id)
                        ->update([
                            'quantity_available' => $inventory->quantity_available + $item->quantity,
                            'quantity_reserved' => max(0, $inventory->quantity_reserved - $item->quantity),
                            'version' => $inventory->version + 1,
                            'updated_at' => now(),
                        ]);
                }
            }

            $order->update(['status' => 'cancelled']);

            return $order;
        });
    }
}
