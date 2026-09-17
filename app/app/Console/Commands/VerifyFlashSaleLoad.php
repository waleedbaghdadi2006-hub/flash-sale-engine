<?php

namespace App\Console\Commands;

use App\Models\FlashSaleItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Phase 7 verification command for staging/game-day load tests.
 *
 * The database remains authoritative: quantity_sold is compared with the
 * units represented by non-cancelled flash-sale order items. When the Redis
 * stock gate is enabled, the command also reports the live Redis counter so
 * operators can spot a counter/DB mismatch before cleanup.
 */
class VerifyFlashSaleLoad extends Command
{
    protected $signature = 'flash-sale:verify-load
        {flashSaleItemId : Flash-sale item to verify}
        {expectedUnits : Number of units the load test was supposed to sell}';

    protected $description = 'Verify flash-sale load-test results against DB and Redis stock state.';

    public function handle(): int
    {
        $itemId = (int) $this->argument('flashSaleItemId');
        $expectedUnits = (int) $this->argument('expectedUnits');

        $item = FlashSaleItem::query()->find($itemId);

        if (!$item) {
            $this->error("Flash-sale item #{$itemId} was not found.");

            return self::FAILURE;
        }

        $orderUnits = (int) DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.flash_sale_id', $item->flash_sale_id)
            ->where('order_items.product_id', $item->product_id)
            ->where('orders.status', '!=', 'cancelled')
            ->sum('order_items.quantity');

        $this->table(
            ['Check', 'Expected', 'Actual', 'Status'],
            [
                ['expected load units', (string) $expectedUnits, (string) $item->quantity_sold, $item->quantity_sold === $expectedUnits ? 'PASS' : 'FAIL'],
                ['order-item units', (string) $item->quantity_sold, (string) $orderUnits, $orderUnits === $item->quantity_sold ? 'PASS' : 'FAIL'],
                ['remaining DB stock', '-', (string) $item->remainingStock(), 'INFO'],
            ],
        );

        try {
            $redisValue = Redis::connection('stock')->get("flashsale:{$itemId}:stock");

            if ($redisValue === null || $redisValue === false) {
                $this->line('Redis stock counter: not present (already cleaned up or Redis gate not seeded).');
            } else {
                $redisRemaining = (int) $redisValue;
                $expectedRemaining = $item->quantity_limit - $item->quantity_sold;
                $status = $redisRemaining === $expectedRemaining ? 'PASS' : 'FAIL';
                $this->line("Redis stock counter: {$redisRemaining} (expected {$expectedRemaining}) — {$status}");

                if ($status === 'FAIL') {
                    return self::FAILURE;
                }
            }
        } catch (Throwable $e) {
            $this->warn('Redis stock verification unavailable: ' . $e->getMessage());
        }

        return $item->quantity_sold === $expectedUnits && $orderUnits === $item->quantity_sold
            ? self::SUCCESS
            : self::FAILURE;
    }
}
