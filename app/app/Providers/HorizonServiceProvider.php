<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeMailNotificationsTo('ops@example.com');
        // Horizon::routeSlackNotificationsTo(config('services.slack.webhook_url'), '#flash-sale-ops');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local
     * environments. Access is restricted to the `admin` role — the same
     * `users.role` column that `App\Http\Middleware\EnsureUserHasRole`
     * checks for admin-only API routes (REDIS_WORKPLAN.md Phase 3).
     *
     * The app has no browser/session login (auth.defaults.guard is the
     * JWT-backed `api` guard, per config/auth.php); Horizon injects
     * whichever user the *default* guard resolves for the incoming
     * request, so this works the same way any other `auth('api')`-gated
     * endpoint does. In practice that means an admin opens the dashboard
     * with their JWT attached — either as an `Authorization: Bearer …`
     * header, or, since tymon/jwt-auth's default parser chain also reads
     * a `token` query parameter, by visiting `/horizon?token=<jwt>` from
     * a browser. Treat that URL as sensitive (it carries a bearer
     * credential) and avoid pasting it anywhere it might get logged.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user) {
            return $user !== null && $user->role === 'admin';
        });
    }
}
