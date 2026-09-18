<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redis-backed flash-sale stock counter
    |--------------------------------------------------------------------------
    |
    | When enabled, FlashSaleController::purchase() consults the atomic
    | Redis counter (App\Services\FlashSaleStock) before dispatching the
    | purchase job, so "sold out" requests are rejected in microseconds
    | without ever touching MySQL, and only winners get queued.
    |
    | The DB row lock in FlashSaleItem::tryReserve() (via
    | PurchaseService::reserveFlashSaleLine()) remains the permanent,
    | transactional source of truth regardless of this flag — it is never
    | removed. This setting only controls whether the fast Redis gatekeeper
    | sits in front of it.
    |
    | Flip to false to instantly fall back to the pre-Redis behavior (a
    | cheap remainingStock() check, then let the job's DB lock decide) —
    | no deploy required beyond an env var change and a config cache clear.
    | Intended for a staged production rollout (see REDIS_WORKPLAN.md
    | Phase 7) and as an emergency kill switch mid-sale.
    |
    */
    'redis_stock_enabled' => env('FLASH_SALE_REDIS_STOCK_ENABLED', true),

    'purchase_idempotency_ttl_seconds' => env('FLASH_SALE_PURCHASE_IDEMPOTENCY_TTL_SECONDS', 10),

    'purchase_rate_limit_enabled' => env('FLASH_SALE_PURCHASE_RATE_LIMIT_ENABLED', true),

    'stock_broadcast_enabled' => env('FLASH_SALE_STOCK_BROADCAST_ENABLED', true),
    'stock_broadcast_every' => max(1, (int) env('FLASH_SALE_STOCK_BROADCAST_EVERY', 1)),

];
