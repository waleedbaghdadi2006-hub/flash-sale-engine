<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Database\Eloquent\Relations\Relation;
use App\Models\Product;
use App\Models\Order;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'product' => Product::class,
            'order' => Order::class,
        ]);

        RateLimiter::for('auth', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));

            return Limit::perMinute(10)->by($request->ip() . '|' . $email);
        });

        RateLimiter::for('auth_login', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));

            return $this->configuredLimit('auth_login')->by($request->ip() . '|' . $email);
        });

        RateLimiter::for('auth_register', function (Request $request) {
            return $this->configuredLimit('auth_register')->by($request->ip());
        });

        RateLimiter::for('auth_verification', function (Request $request) {
            return $this->configuredLimit('auth_verification')->by($request->ip());
        });

        RateLimiter::for('auth_forgot_password', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));

            return $this->configuredLimit('auth_forgot_password')->by($request->ip() . '|' . $email);
        });

        RateLimiter::for('auth_reset_password', function (Request $request) {
            return $this->configuredLimit('auth_reset_password')->by($request->ip());
        });

        RateLimiter::for('auth_refresh', function (Request $request) {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return $this->configuredLimit('auth_refresh')->by((string) $key);
        });

        RateLimiter::for('api', function (Request $request) {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(120)->by((string) $key);
        });

        RateLimiter::for('flash_sale_purchase', function (Request $request) {
            $userId = $request->user()?->getAuthIdentifier() ?? 'guest';
            $flashSale = $request->route('flashSale') ?? $request->route('flash_sale');
            $flashSaleId = is_object($flashSale) && method_exists($flashSale, 'getKey')
                ? $flashSale->getKey()
                : (string) $flashSale;

            return $this->configuredLimit('flash_sale_purchase')
                ->by((string) $userId . '|' . (string) $flashSaleId);
        });

        RateLimiter::for('cart_buy_now', function (Request $request) {
            return $this->configuredLimit('cart_buy_now')->by($this->userKey($request));
        });

        RateLimiter::for('order_create', function (Request $request) {
            return $this->configuredLimit('order_create')->by($this->userKey($request));
        });

        RateLimiter::for('payment_create', function (Request $request) {
            return $this->configuredLimit('payment_create')->by($this->userKey($request));
        });

        RateLimiter::for('payment_webhook', function (Request $request) {
            return $this->configuredLimit('payment_webhook')
                ->by($request->ip() . '|' . (string) $request->route('provider'));
        });
    }

    private function configuredLimit(string $name): Limit
    {
        $config = config('rate_limiting.' . $name);

        return new Limit(
            maxAttempts: (int) $config['max_attempts'],
            decaySeconds: (int) $config['decay_seconds'],
        );
    }

    private function userKey(Request $request): string
    {
        return (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());
    }
}