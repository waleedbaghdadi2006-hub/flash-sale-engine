<?php

namespace Tests\Unit\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * App\Providers\HorizonServiceProvider gates the /horizon dashboard to
 * `admin` accounts only (REDIS_WORKPLAN.md Phase 3), reusing the same
 * `users.role` column that App\Http\Middleware\EnsureUserHasRole checks
 * for admin-only API routes.
 *
 * Requires `laravel/horizon` to actually be installed — our provider
 * extends Laravel\Horizon\HorizonApplicationServiceProvider and is
 * registered in bootstrap/providers.php, so its `viewHorizon` gate is
 * only defined once that package's classes are autoloadable. Like
 * FlashSaleStockTest needing a reachable Redis, this needs
 * `composer install` (or `composer require laravel/horizon`) to have run
 * first.
 *
 * These are unsaved User instances — no DB, migrations, or factory
 * involved — because the gate closure only ever reads ->role. Same
 * pattern FlashSaleStockTest uses to build an unsaved FlashSaleItem.
 */
class HorizonServiceProviderTest extends TestCase
{
    public function test_admin_can_view_horizon(): void
    {
        $admin = new User(['role' => 'admin']);

        $this->assertTrue(Gate::forUser($admin)->allows('viewHorizon'));
    }

    public function test_staff_cannot_view_horizon(): void
    {
        $staff = new User(['role' => 'staff']);

        $this->assertFalse(Gate::forUser($staff)->allows('viewHorizon'));
    }

    public function test_customer_cannot_view_horizon(): void
    {
        $customer = new User(['role' => 'customer']);

        $this->assertFalse(Gate::forUser($customer)->allows('viewHorizon'));
    }

    public function test_no_user_cannot_view_horizon(): void
    {
        // Explicitly passing null rather than relying on guest auth
        // resolution — this is what an unauthenticated dashboard request
        // (no/invalid JWT) resolves to, and the gate must not
        // null-reference on it.
        $this->assertFalse(Gate::forUser(null)->allows('viewHorizon'));
    }
}
