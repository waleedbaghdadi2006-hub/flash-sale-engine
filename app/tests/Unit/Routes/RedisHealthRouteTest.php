<?php

namespace Tests\Unit\Routes;

use Tests\TestCase;

class RedisHealthRouteTest extends TestCase
{
    public function test_admin_redis_health_route_is_registered_and_admin_gated(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'admin/redis/health' && in_array('GET', $route->methods(), true));

        $this->assertNotNull($route, 'GET /admin/redis/health was not registered.');
        $this->assertContains('auth:api', $route->middleware());
        $this->assertContains('role:admin', $route->middleware());
    }
}
