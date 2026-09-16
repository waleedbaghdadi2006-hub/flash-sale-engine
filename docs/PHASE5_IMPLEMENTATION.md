# Phase 5 Implementation — Live Flash-Sale Stock Updates

## Delivered

- Added Laravel Reverb as the broadcast driver.
- Added a `StockUpdated` broadcast event on public `flash-sale.{id}` channels.
- Broadcasts after a successful Redis stock reservation.
- Broadcasts a correction when a Redis reservation is later released because queueing/DB reservation failed.
- Broadcasts stock returned by customer cancellation.
- All broadcast publishing is best-effort and wrapped so a Reverb/Redis outage never blocks a purchase.
- Added optional broadcast throttling with `FLASH_SALE_STOCK_BROADCAST_EVERY`; `1` emits every stock change.
- Added Laravel Echo + Pusher JS client setup and a `window.subscribeToFlashSaleStock()` helper.
- Added a dedicated Reverb Docker service and Nginx WebSocket/API proxy routes.

## Client event

Subscribe to `flash-sale.{flashSaleId}` and listen for `.stock.updated`. Payload:

```json
{
  "flash_sale_id": 12,
  "flash_sale_item_id": 34,
  "remaining_stock": 7,
  "reason": "reserved",
  "occurred_at": "2026-09-14T17:00:00Z"
}
```

## Setup

The environment should define unique Reverb credentials in real deployments. The checked-in `.env.example` uses local-only placeholders. After installing dependencies, run:

```bash
composer update laravel/reverb
npm install
npm run build
php artisan config:clear
```

Then start the stack with `docker compose up`. The `reverb` service listens on port 8080 internally, while Nginx proxies WebSocket traffic under `/app/` and Reverb's publish API under `/apps/`.

## Validation

This sandbox does not include Composer, so the Reverb dependency could not be resolved into `composer.lock` here. PHP syntax can still be checked locally after dependencies are installed; the dependency-install step above is intentionally left explicit rather than fabricating a lock file.
