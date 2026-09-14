<?php

namespace Tests\Unit\Routes;

use Tests\TestCase;

class FlashSalePurchaseRouteTest extends TestCase
{
    public function test_purchase_route_has_dedicated_rate_limit_and_active_sale_middleware(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'flash-sales/{flashSale}/purchase' && in_array('POST', $route->methods(), true));

        $this->assertNotNull($route);
        $this->assertContains('throttle:flash_sale_purchase', $route->middleware());
        $this->assertContains('flash_sale.active', $route->middleware());
    }
}
