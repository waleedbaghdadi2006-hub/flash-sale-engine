# Phase 6 Implementation — Redis Operational Hygiene

## Bug fixed first

Before any Phase 6 work, `FlashSaleStock::broadcastUpdated()` did not
exist, even though it was already called from four places
(`FlashSaleController::purchase()`'s success and dispatch-failure paths,
`ProcessFlashSalePurchase::releaseRedisReservation()`, and
`OrderService::cancel()`). With `flash_sale.redis_stock_enabled` at its
default (`true`), every successful flash-sale purchase threw a fatal
`Error` and 500'd. This is now implemented on `FlashSaleStock` — see its
docblock and `tests/Unit/Services/FlashSaleStockTest.php`.

## Delivered

- **TTL discipline** — `FlashSaleStock::forget()` deletes a stock counter
  and its broadcast-throttle key. A new `flash-sale:cleanup-stock` command
  sweeps flash sales whose `ends_at` has passed but whose `status` hasn't
  caught up, forgets every item's Redis keys, and flips `status` to
  `ended` so the same sale is never re-swept. Scheduled every 5 minutes in
  `routes/console.php`, alongside `horizon:snapshot`.

  `EnsureFlashSaleIsActive` already gates purchase routes on the real
  clock rather than trusting `status` (see its docblock), so this command
  is purely Redis/bookkeeping hygiene — it never changes which requests
  get accepted, only when stale counters get cleared and `status` catches
  up. It's also why the command is safe to run frequently and doesn't need
  its own idempotency guard: rows already marked `ended` no longer match
  its `WHERE` clause.

- **Eviction policy** — `docker-compose.yml`'s `redis` service now passes
  `--maxmemory ${REDIS_MAXMEMORY:-256mb} --maxmemory-policy
  ${REDIS_MAXMEMORY_POLICY:-noeviction}` explicitly, instead of relying on
  Redis's default (which is `noeviction` in recent versions, but making it
  explicit and env-overridable means a deliberate policy change — or a
  bigger instance — is one env var, not an archaeology exercise). See
  `.env.example` at the repo root.

- **Monitoring** — a new `RedisDiagnostics` service reports memory vs
  maxmemory, connected clients, ops/sec, keyspace hit/miss rate, rejected
  connections, and a per-logical-DB key count (queue/general, cache,
  flash-sale stock — see `config/database.php`'s `redis` array). Exposed
  two ways:
  - `php artisan redis:diagnostics` (add `--json` for machine-readable
    output) — useful during a game-day load test or mid-incident without
    dashboard access.
  - `GET /admin/redis/health`, gated by the same `role:admin` middleware
    as the coupon admin routes. Returns `200` with `status: "unavailable"`
    (not a `500`) when Redis itself can't be reached, since "is Redis
    down" is exactly the question this endpoint exists to answer.

## Still manual (by design)

- **Persistence restart test** — AOF (`--appendonly yes`) has been on
  since before this phase; actually killing and restarting the `redis`
  container in staging and confirming queued jobs and in-flight stock
  counters survive it is an operational exercise, not something to encode
  as an automated test. See `docs/REDIS_OPERATIONS.md`'s runbook section.
- **Connection tuning** (`REDIS_PERSISTENT`, PHP-FPM pool size vs Redis
  `maxclients`) is documented as comments in `app/.env.example` and in
  `docs/REDIS_OPERATIONS.md` rather than defaulted on, since the right
  setting depends on real request volume you don't have until you're
  running under load.

## Testing

- `tests/Unit/Services/FlashSaleStockTest.php` — extended with real-Redis
  coverage for `broadcastUpdated()` (disabled flag, throttling of
  `'reserved'` vs. always-broadcasting `'released'`, reading remaining
  stock from Redis with a DB fallback, failing silently when Redis is
  unreachable) and `forget()`.
- `tests/Unit/Services/RedisDiagnosticsTest.php` — real-Redis coverage of
  the summary shape, a known key showing up in its logical DB's count, and
  the `unavailable` fallback when Redis can't be reached.
- `tests/Unit/Routes/RedisHealthRouteTest.php` — confirms
  `GET /admin/redis/health` is registered with `auth:api` + `role:admin`.
- `tests/Feature/CleanupFlashSaleStockCommandTest.php` — **requires
  MySQL**, and skips itself with an explanatory message otherwise. This
  project's migrations run raw `ALTER TABLE ... ADD CONSTRAINT ... CHECK
  (...)` statements, which is MySQL-only DDL that SQLite's grammar can't
  execute, so a real `flash_sales`/`flash_sale_items` schema can only be
  migrated against MySQL. Run it explicitly:
  ```bash
  docker compose exec app php artisan test --filter=CleanupFlashSaleStockCommandTest
  ```

## Validation 

This sandbox has no Composer/vendor and no `php` binary (same constraint
noted in `docs/PHASE5_IMPLEMENTATION.md`), so none of the above could be
executed here — only read carefully against the existing code and test
patterns already in the repo. Run the full suite for real via:

```bash 
docker compose exec app php artisan test
docker compose exec app php artisan test --filter=CleanupFlashSaleStockCommandTest
```
