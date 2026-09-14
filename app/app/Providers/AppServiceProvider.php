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

            return Limit::perMinute(5)->by((string) $userId . '|' . (string) $flashSaleId);
        });
    }
}