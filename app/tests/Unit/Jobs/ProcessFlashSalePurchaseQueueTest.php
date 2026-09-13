<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessFlashSalePurchase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * REDIS_WORKPLAN.md Phase 3: the flash-sale purchase job must land on its
 * own `flash-sale` queue rather than Laravel's `default` queue. That's
 * what lets config/horizon.php's `supervisor-flash-sale` scale it up fast
 * and independently of whatever else eventually runs on `default` — a
 * flash-sale burst can never starve unrelated jobs, and vice versa.
 *
 * No Redis, DB, or the Horizon package itself is needed here: onQueue()
 * just sets a public property on the job (Illuminate\Bus\Queueable), and
 * Queue::fake() intercepts dispatch() before anything touches a real
 * queue connection.
 */
class ProcessFlashSalePurchaseQueueTest extends TestCase
{
    private function makeJob(bool $reservedViaRedis = false): ProcessFlashSalePurchase
    {
        return new ProcessFlashSalePurchase(
            referenceId: 'test-reference-id',
            userId: 1,
            flashSaleItemId: 1,
            quantity: 1,
            shippingAddressId: 1,
            billingAddressId: null,
            reservedViaRedis: $reservedViaRedis,
        );
    }

    public function test_job_is_constructed_onto_the_flash_sale_queue(): void
    {
        $job = $this->makeJob();

        $this->assertSame('flash-sale', $job->queue);
    }

    public function test_queue_assignment_is_unaffected_by_the_redis_reservation_flag(): void
    {
        // onQueue('flash-sale') runs unconditionally in the constructor —
        // it must not accidentally depend on reservedViaRedis (e.g. only
        // being set on the Redis-gatekeeper path) or a request that fell
        // back to the DB-only path (Redis disabled/unavailable) would
        // silently end up on the wrong queue.
        $this->assertSame('flash-sale', $this->makeJob(reservedViaRedis: false)->queue);
        $this->assertSame('flash-sale', $this->makeJob(reservedViaRedis: true)->queue);
    }

    public function test_dispatching_the_job_pushes_it_onto_the_flash_sale_queue(): void
    {
        Queue::fake();

        ProcessFlashSalePurchase::dispatch(
            referenceId: 'test-reference-id',
            userId: 1,
            flashSaleItemId: 1,
            quantity: 1,
            shippingAddressId: 1,
            billingAddressId: null,
        );

        Queue::assertPushedOn('flash-sale', ProcessFlashSalePurchase::class);
    }

    public function test_dispatching_the_job_does_not_land_on_the_default_queue(): void
    {
        Queue::fake();

        ProcessFlashSalePurchase::dispatch(
            referenceId: 'test-reference-id',
            userId: 1,
            flashSaleItemId: 1,
            quantity: 1,
            shippingAddressId: 1,
            billingAddressId: null,
        );

        Queue::assertNotPushedOn('default', ProcessFlashSalePurchase::class);
    }
}
