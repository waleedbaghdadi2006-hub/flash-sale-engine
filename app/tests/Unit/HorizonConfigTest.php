<?php

namespace Tests\Unit;

use App\Jobs\ProcessFlashSalePurchase;
use Tests\TestCase;

/**
 * config/horizon.php is plain data, loaded by Laravel's normal config
 * bootstrapper exactly like config/queue.php — none of this touches
 * Redis, HTTP, or Laravel Horizon's own classes, so unlike
 * HorizonServiceProviderTest it runs even before `composer install` has
 * pulled in `laravel/horizon`.
 *
 * These assertions exist to catch config drift: nothing stops someone
 * from renaming a queue, changing the job's $tries, or editing one
 * supervisor's tuning without updating its counterpart, and none of that
 * would throw an error — it would just silently misbehave under real
 * flash-sale load. See REDIS_WORKPLAN.md Phase 3.
 */
class HorizonConfigTest extends TestCase
{
    public function test_flash_sale_supervisor_targets_only_the_flash_sale_queue(): void
    {
        $supervisor = config('horizon.defaults.supervisor-flash-sale');

        $this->assertSame(['flash-sale'], $supervisor['queue']);
        $this->assertSame('redis', $supervisor['connection']);
    }

    public function test_default_supervisor_targets_only_the_default_queue(): void
    {
        $supervisor = config('horizon.defaults.supervisor-default');

        $this->assertSame(['default'], $supervisor['queue']);
        $this->assertSame('redis', $supervisor['connection']);
    }

    public function test_flash_sale_supervisor_tries_matches_the_jobs_own_tries(): void
    {
        // ProcessFlashSalePurchase::$tries is 1: PurchaseService is the
        // transactional source of truth, and a failed attempt is a
        // terminal, reportable-to-the-client result rather than something
        // to silently retry (see the job's own docblock). If the two
        // values ever drift apart, Horizon would start retrying — or stop
        // matching — the job's actual retry contract without anyone
        // having to touch the job itself.
        $job = new ProcessFlashSalePurchase(
            referenceId: 'x',
            userId: 1,
            flashSaleItemId: 1,
            quantity: 1,
            shippingAddressId: 1,
            billingAddressId: null,
        );

        $this->assertSame(
            $job->tries,
            config('horizon.defaults.supervisor-flash-sale.tries'),
        );
    }

    public function test_flash_sale_supervisor_timeout_is_below_the_redis_queue_retry_after(): void
    {
        // Otherwise a still-running job could get picked up a second time
        // by another worker before Horizon considers the first attempt
        // timed out. See config/queue.php's REDIS_QUEUE_RETRY_AFTER.
        $timeout = config('horizon.defaults.supervisor-flash-sale.timeout');
        $retryAfter = config('queue.connections.redis.retry_after');

        $this->assertLessThan($retryAfter, $timeout);
    }

    public function test_production_scales_flash_sale_harder_and_faster_than_default(): void
    {
        $production = config('horizon.environments.production');

        $this->assertGreaterThan(
            $production['supervisor-default']['maxProcesses'],
            $production['supervisor-flash-sale']['maxProcesses'],
            'Flash-sale bursts need more worker headroom than the default queue.',
        );

        $this->assertGreaterThan(
            $production['supervisor-default']['balanceMaxShift'],
            $production['supervisor-flash-sale']['balanceMaxShift'],
            'Flash-sale should add more processes per balancing cycle than default.',
        );

        $this->assertLessThan(
            $production['supervisor-default']['balanceCooldown'],
            $production['supervisor-flash-sale']['balanceCooldown'],
            'Flash-sale should ramp up on a shorter cooldown than default.',
        );
    }

    public function test_flash_sale_queue_has_a_tighter_long_wait_threshold_than_default(): void
    {
        $waits = config('horizon.waits');

        $this->assertLessThan(
            $waits['redis:default'],
            $waits['redis:flash-sale'],
            'A backed-up flash-sale queue should alert much sooner than the default queue.',
        );
    }

    public function test_horizon_bookkeeping_shares_the_queues_redis_connection(): void
    {
        // horizon.use (where Horizon stores its own supervisor/metrics
        // bookkeeping) and queue.connections.redis.connection (where job
        // payloads actually live) should point at the same logical Redis
        // DB — see REDIS_WORKPLAN.md Phase 6 on the cache/queue/stock
        // DB split.
        $this->assertSame(
            config('queue.connections.redis.connection'),
            config('horizon.use'),
        );
    }
}
