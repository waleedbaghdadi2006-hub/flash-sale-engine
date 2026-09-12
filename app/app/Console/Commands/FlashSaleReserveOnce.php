<?php

namespace App\Console\Commands;

use App\Services\FlashSaleStock;
use Illuminate\Console\Command;

/**
 * Test/ops utility: attempts a single Redis stock reservation and prints
 * the raw tryReserve() result (-1 miss, 0 sold out, 1 reserved) to stdout.
 *
 * This exists so a concurrency test can spawn many real, separate OS
 * processes against the same Redis key — proving the Lua script's
 * atomicity under genuine parallelism, not just sequential PHP calls in
 * a single process. See tests/Feature/FlashSaleStockConcurrencyTest.php.
 */
class FlashSaleReserveOnce extends Command
{
    protected $signature = 'flash-sale:reserve-once {flashSaleItemId : ID used only to build the Redis key} {quantity=1}';

    protected $description = 'Attempt one atomic Redis stock reservation and print the raw result (-1 miss, 0 sold out, 1 reserved).';

    public function handle(FlashSaleStock $flashSaleStock): int
    {
        $result = $flashSaleStock->tryReserve(
            (int) $this->argument('flashSaleItemId'),
            (int) $this->argument('quantity'),
        );

        $this->line((string) $result);

        return self::SUCCESS;
    }
}
