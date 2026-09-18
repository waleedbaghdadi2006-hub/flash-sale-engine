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

    public function test_sensitive_endpoint_limiters_have_distinct_policies(): void
    {
        $request = Request::create('/auth/login', 'POST', ['email' => 'user@example.com']);

        $this->assertSame(5, RateLimiter::limiter('auth_login')($request)->maxAttempts);
        $this->assertSame(3, RateLimiter::limiter('auth_register')($request)->maxAttempts);
        $this->assertSame(3, RateLimiter::limiter('auth_forgot_password')($request)->maxAttempts);
        $this->assertSame(5, RateLimiter::limiter('auth_reset_password')($request)->maxAttempts);
        $this->assertSame(10, RateLimiter::limiter('cart_buy_now')($request)->maxAttempts);
        $this->assertSame(10, RateLimiter::limiter('order_create')($request)->maxAttempts);
        $this->assertSame(5, RateLimiter::limiter('payment_create')($request)->maxAttempts);
        $this->assertSame(60, RateLimiter::limiter('payment_webhook')($request)->maxAttempts);
    }
}
