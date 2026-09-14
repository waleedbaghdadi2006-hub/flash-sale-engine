<?php

namespace Tests\Feature;

use App\Services\FlashSaleStock;
use App\Services\PurchaseIdempotencyGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class Phase4SmokeTest extends TestCase
{
    private const TEST_ITEM_ID = 900050;

    protected function tearDown(): void
    {
        try {
            Redis::connection('stock')->del('flashsale:' . self::TEST_ITEM_ID . ':stock');
        } catch (\Throwable) {
            // Redis-backed assertions may intentionally be unavailable in some environments.
        }

        Cache::forget($this->idempotencyKey());

        parent::tearDown();
    }

    public function test_phase4_purchase_protection_smoke_suite(): void
    {
        $this->assertPurchaseRouteIsProtected();
        $this->assertPurchaseRateLimiterPolicy();
        $this->assertIdempotencyGuardWorks();
        $this->assertRedisStockGateWorks();
    }

    private function assertPurchaseRouteIsProtected(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) =>
                $route->uri() === 'flash-sales/{flashSale}/purchase'
                && in_array('POST', $route->methods(), true)
            );

        $this->assertNotNull($route, 'Flash-sale purchase route was not registered.');
        $this->assertContains('auth:api', $route->middleware());
        $this->assertContains('throttle:flash_sale_purchase', $route->middleware());
        $this->assertContains('flash_sale.active', $route->middleware());
    }

    private function assertPurchaseRateLimiterPolicy(): void
    {
        $limiter = RateLimiter::limiter('flash_sale_purchase');

        $this->assertIsCallable($limiter);

        $request = Request::create('/api/flash-sales/42/purchase', 'POST');
        $request->setUserResolver(fn () => new class {
            public function getAuthIdentifier(): int
            {
                return 77;
            }
        });
        $request->setRouteResolver(function () use ($request) {
            $route = new \Illuminate\Routing\Route(
                'POST',
                '/api/flash-sales/{flashSale}/purchase',
                [],
            );
            $route->bind($request);
            $route->setParameter('flashSale', 42);

            return $route;
        });

        $limit = $limiter($request);

        $this->assertSame(5, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
    }

    private function assertIdempotencyGuardWorks(): void
    {
        $guard = new PurchaseIdempotencyGuard();
        $key = $this->idempotencyKey();

        $args = [
            'userId' => 77,
            'flashSaleItemId' => self::TEST_ITEM_ID,
            'quantity' => 1,
            'shippingAddressId' => 501,
            'billingAddressId' => null,
        ];

        $this->assertTrue($guard->tryAcquire(...$args));
        $this->assertFalse($guard->tryAcquire(...$args));

        $guard->release(...$args);

        $this->assertTrue($guard->tryAcquire(...$args));

        // Keep the smoke test cleanup explicit so a failed assertion doesn't
        // leave a duplicate-purchase key behind for the next run.
        Cache::forget($key);
    }

    private function assertRedisStockGateWorks(): void
    {
        $stock = new FlashSaleStock();
        $key = 'flashsale:' . self::TEST_ITEM_ID . ':stock';

        // This test intentionally requires the real stock Redis connection:
        // atomic Lua reservation is the behavior we need to verify.
        Redis::connection('stock')->del($key);
        $stock->seed(self::TEST_ITEM_ID, 2);

        $this->assertSame(
            FlashSaleStock::RESERVED,
            $stock->tryReserve(self::TEST_ITEM_ID, 1),
        );

        $this->assertSame(
            1,
            (int) Redis::connection('stock')->get($key),
        );

        $this->assertSame(
            FlashSaleStock::RESERVED,
            $stock->tryReserve(self::TEST_ITEM_ID, 1),
        );

        $this->assertSame(
            FlashSaleStock::SOLD_OUT,
            $stock->tryReserve(self::TEST_ITEM_ID, 1),
        );

        $stock->release(self::TEST_ITEM_ID, 1);

        $this->assertSame(
            1,
            (int) Redis::connection('stock')->get($key),
        );
    }

    private function idempotencyKey(): string
    {
        $fingerprint = hash('sha256', json_encode([
            'user_id' => 77,
            'flash_sale_item_id' => self::TEST_ITEM_ID,
            'quantity' => 1,
            'shipping_address_id' => 501,
            'billing_address_id' => null,
        ], JSON_THROW_ON_ERROR));

        return 'flash_sale_purchase:idempotency:' . $fingerprint;
    }
}
