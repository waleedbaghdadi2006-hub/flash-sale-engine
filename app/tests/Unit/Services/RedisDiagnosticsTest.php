<?php

namespace Tests\Unit\Services;

use App\Services\RedisDiagnostics;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Hits a real Redis instance rather than mocking `INFO`/`DBSIZE` — the
 * point of this service is to reflect the server's actual reported state,
 * so a mock would only prove "the code calls info()", not that the
 * summary shape holds up against a real reply. See FlashSaleStockTest's
 * docblock for the same reasoning applied to the stock counter itself.
 *
 * Requires a reachable Redis matching REDIS_HOST/REDIS_PORT in the
 * environment running the suite — correct by default when run via
 * `docker compose exec app php artisan test`.
 */
class RedisDiagnosticsTest extends TestCase
{
    public function test_summary_reports_ok_with_the_expected_shape_against_real_redis(): void
    {
        $summary = (new RedisDiagnostics())->summary();

        $this->assertSame('ok', $summary['status']);
        $this->assertArrayHasKey('redis_version', $summary);
        $this->assertArrayHasKey('used_bytes', $summary['memory']);
        $this->assertArrayHasKey('maxmemory_policy', $summary['memory']);
        $this->assertArrayHasKey('connected', $summary['clients']);
        $this->assertArrayHasKey('instantaneous_ops_per_sec', $summary['ops']);

        // All three logical connections from config/database.php's redis
        // array should be represented, each with a non-negative key count.
        foreach (['queue_and_general', 'cache', 'flash_sale_stock'] as $label) {
            $this->assertArrayHasKey($label, $summary['logical_databases']);
            $this->assertArrayHasKey('keys', $summary['logical_databases'][$label]);
            $this->assertGreaterThanOrEqual(0, $summary['logical_databases'][$label]['keys']);
        }
    }

    public function test_summary_counts_a_known_key_in_the_stock_database(): void
    {
        Redis::connection('stock')->set('flashsale:900201:stock', 5);

        try {
            $summary = (new RedisDiagnostics())->summary();

            $this->assertGreaterThanOrEqual(1, $summary['logical_databases']['flash_sale_stock']['keys']);
        } finally {
            Redis::connection('stock')->del('flashsale:900201:stock');
        }
    }

    public function test_summary_reports_unavailable_instead_of_throwing_when_redis_is_unreachable(): void
    {
        Redis::shouldReceive('connection')
            ->with('default')
            ->andThrow(new \RedisException('Connection refused'));

        $summary = (new RedisDiagnostics())->summary();

        $this->assertSame('unavailable', $summary['status']);
        $this->assertArrayHasKey('error', $summary);
    }
}
