<?php

namespace App\Console\Commands;

use App\Models\FlashSale;
use App\Services\FlashSaleStock;
use Illuminate\Console\Command;

/**
 * TTL discipline for the Redis stock counters (REDIS_WORKPLAN.md Phase 6):
 * a counter deliberately carries no TTL while its sale is live — an expiry
 * firing mid-sale would silently resurrect "sold out" units as available,
 * which is a far worse failure mode than a key that lingers a few minutes
 * too long. Instead, counters are cleaned up explicitly once the sale is
 * over.
 *
 * This sweeps every flash sale whose `ends_at` has passed but whose
 * `status` column hasn't caught up yet, forgets each item's Redis counter
 * (and its broadcast-throttle key) via FlashSaleStock::forget(), and flips
 * `status` to 'ended' so the same sale is never swept — or reported on —
 * twice.
 *
 * EnsureFlashSaleIsActive already gates purchase routes on the real clock
 * rather than trusting `status` (see its docblock), so this command is
 * purely Redis/bookkeeping hygiene: it never changes which requests get
 * accepted, only when stale counters get cleared and `status` catches up.
 *
 * Scheduled in routes/console.php.
 */
class CleanupFlashSaleStock extends Command
{
    protected $signature = 'flash-sale:cleanup-stock';

    protected $description = 'Forget Redis stock counters for ended flash sales and mark them ended.';

    public function handle(FlashSaleStock $flashSaleStock): int
    {
        $flashSales = FlashSale::query()
            ->where('ends_at', '<=', now())
            ->where('status', '!=', FlashSale::STATUS_ENDED)
            ->with('items:id,flash_sale_id')
            ->get();

        if ($flashSales->isEmpty()) {
            $this->info('No ended flash sales to clean up.');

            return self::SUCCESS;
        }

        $itemCount = 0;

        foreach ($flashSales as $flashSale) {
            foreach ($flashSale->items as $item) {
                $flashSaleStock->forget($item->id);
                $itemCount++;
            }

            $flashSale->update(['status' => FlashSale::STATUS_ENDED]);

            $this->line("Flash sale #{$flashSale->id} ({$flashSale->title}): cleared {$flashSale->items->count()} counter(s), marked ended.");
        }

        $this->info(sprintf(
            'Cleaned up %d stock counter(s) across %d flash sale(s).',
            $itemCount,
            $flashSales->count(),
        ));

        return self::SUCCESS;
    }
}
