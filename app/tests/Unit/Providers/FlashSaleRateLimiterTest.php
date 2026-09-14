<?php

namespace Tests\Unit\Providers;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class FlashSaleRateLimiterTest extends TestCase
{
    public function test_flash_sale_purchase_limiter_is_registered(): void
    {
        $limiter = RateLimiter::limiter('flash_sale_purchase');

        $this->assertIsCallable($limiter);

        $request = Request::create('/flash-sales/42/purchase', 'POST');
        $request->setUserResolver(fn () => new class {
            public function getAuthIdentifier(): int { return 77; }
        });
        $request->setRouteResolver(function () use ($request) {
            $route = new \Illuminate\Routing\Route('POST', '/flash-sales/{flashSale}/purchase', []);
            $route->bind($request);
            $route->setParameter('flashSale', 42);
            return $route;
        });

        $limit = $limiter($request);

        $this->assertSame(5, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
    }
}
