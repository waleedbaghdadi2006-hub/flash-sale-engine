<?php

namespace App\Http\Middleware;

use App\Models\FlashSale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFlashSaleIsActive
{
    /**
     * Blocks any route that operates on a flash sale (route-model-bound as
     * `flash_sale` or `flashSale`) unless the sale's status is 'active' AND
     * the current time falls within its starts_at/ends_at window.
     *
     * `status` is a denormalized column (see SCHEMA.md) — nothing transitions
     * it automatically — so this checks the real clock rather than trusting
     * the column alone. Pair this with a scheduled job that flips
     * pending -> active -> ended so `status` stays roughly in sync.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var FlashSale|null $flashSale */
        $flashSale = $request->route('flash_sale') ?? $request->route('flashSale');

        if (! $flashSale instanceof FlashSale) {
            abort(500, 'EnsureFlashSaleIsActive must be applied to a route with a bound FlashSale.');
        }

        if ($flashSale->status === FlashSale::STATUS_CANCELLED) {
            return response()->json([
                'message' => 'This flash sale has been cancelled.',
            ], Response::HTTP_GONE);
        }

        if (now()->lessThan($flashSale->starts_at)) {
            return response()->json([
                'message' => 'This flash sale has not started yet.',
                'starts_at' => $flashSale->starts_at->toIso8601String(),
            ], Response::HTTP_FORBIDDEN);
        }

        if (! $flashSale->isCurrentlyActive()) {
            return response()->json([
                'message' => 'This flash sale is no longer active.',
            ], Response::HTTP_GONE);
        }

        return $next($request);
    }
}
