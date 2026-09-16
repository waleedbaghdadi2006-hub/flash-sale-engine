# Redis Operations Runbook

Operational reference for the Redis instance backing cache, sessions, the
queue, and flash-sale stock counters. See `REDIS_WORKPLAN.md` for the
design rationale; this doc is the "what do I actually do" companion.

## Logical databases

| DB | Env var | Used for | Safe to `FLUSHDB`? |
|---|---|---|---|
| 0 | `REDIS_DB` | Queue (Horizon) + general cache facade calls | **No** — drops in-flight jobs |
| 1 | `REDIS_CACHE_DB` | Application cache (`CACHE_STORE=redis`), sessions | Yes — will just cost some cache misses |
| 2 | `REDIS_STOCK_DB` | Flash-sale atomic stock counters (`App\Services\FlashSaleStock`) | **No** — drops live sale state |

Point `redis-cli -n <db>` at the specific DB you're investigating rather
than the whole instance — that's the entire reason these are split. Key
naming inside DB 2: `flashsale:{id}:stock` (the counter) and
`flashsale:{id}:broadcast_throttle` (Phase 5's broadcast-rate counter).

## Checking health

```bash
# Human-readable summary: memory, clients, ops/sec, hit rate, per-DB key counts
docker compose exec app php artisan redis:diagnostics

# Machine-readable, same data
docker compose exec app php artisan redis:diagnostics --json
```

Or as an admin over HTTP: `GET /admin/redis/health` (requires a JWT for a
`role=admin` user). Returns `200` with `"status": "unavailable"` — not a
`500` — if Redis itself can't be reached.

Metrics worth alerting on, before pressure rather than after:

- `memory.used_pct_of_max` approaching 100 — with `maxmemory-policy
  noeviction`, hitting the ceiling means Redis starts rejecting writes
  outright (`OOM command not allowed`), which will surface as failed
  purchases and failed job dispatches, not a graceful degradation.
- `clients.rejected_connections` > 0 — means something is already hitting
  `maxclients`.
- `ops.hit_rate_pct` dropping sharply on the cache DB — could mean a cold
  cache after a deploy, or a cache-key bug.

If you already run Prometheus, wire up `redis_exporter` for continuous
monitoring instead of polling `redis:diagnostics` by hand; use the command
for spot checks and game-day load tests.

## TTL reference

| Key pattern | TTL | Why |
|---|---|---|
| `flashsale:{id}:stock` | **None** during an active sale | An expiry firing mid-sale would silently resurrect "sold out" units as purchasable — worse than a key that lingers. Cleaned up explicitly by `flash-sale:cleanup-stock` once the sale ends. |
| `flashsale:{id}:broadcast_throttle` | **None** during an active sale | Same reasoning; cleaned up alongside the stock key by `forget()`. |
| `flash_sale_purchase:{reference}` (purchase-status cache) | 15 minutes | Client only needs to poll for a bounded window after submitting; matches `FlashSaleController`/`ProcessFlashSalePurchase`'s existing `now()->addMinutes(15)`. |
| Idempotency guard key (`user_id + flash_sale_item_id + request hash`) | `flash_sale.purchase_idempotency_ttl_seconds` (default 10s) | Must outlive worst-case client retry/double-tap timing, but expire promptly so a genuinely new purchase isn't blocked by a stale lock. |

Any *new* lock/idempotency-style key you add later needs a safety TTL
longer than worst-case processing time — a crashed worker must not be able
to strand a key forever.

## Cleaning up ended sales

`flash-sale:cleanup-stock` runs automatically every 5 minutes (see
`routes/console.php`). To run it manually — e.g. right after a sale you
know just ended, without waiting for the schedule:

```bash
docker compose exec app php artisan flash-sale:cleanup-stock
```

It's idempotent: a sale already marked `status = 'ended'` is skipped, so
running it twice in a row (or a thousand times) is harmless.

## Persistence / restart test

AOF (`--appendonly yes`) has been on since Phase 1's audit. Actually prove
it works before you need it to, in staging:

1. Start a flash sale (or seed a stock counter and enqueue a few jobs
   manually) so DB 0 and DB 2 both have real state.
2. `docker compose restart redis` (or `docker compose kill redis && docker
   compose up -d redis` for a harder test).
3. Confirm via `redis:diagnostics` (or `redis-cli -n 2 get
   flashsale:{id}:stock`) that the stock counter survived.
4. Confirm via the Horizon dashboard, or `redis-cli -n 0 llen
   queues:flash-sale`, that queued/in-flight jobs survived.

If either doesn't survive, check that `appendonly.aof` (or the
multi-part AOF directory in Redis 7) is actually on the `redis_data`
volume and not lost on container recreation — a bind-mount or named
volume misconfiguration is the usual culprit, not AOF itself.

## Connections

- `REDIS_CLIENT=phpredis` is already the client (compiled into
  `app/Dockerfile` via `pecl install redis`).
- `REDIS_PERSISTENT` (see `config/database.php`'s `options.persistent`,
  and the comment in `app/.env.example`) reuses one connection per
  PHP-FPM worker instead of reconnecting every request. Turn it on once
  connection setup shows up in profiling under real load — not by
  default, since a persistent connection per worker means your PHP-FPM
  pool size effectively becomes Redis's `connected_clients` count. Check
  that against Redis's `maxclients` (`redis:diagnostics` reports
  `clients.connected`) before flipping it on, and again after, watching
  for `rejected_connections`.
- `REDIS_MAX_RETRIES` / `REDIS_BACKOFF_*` (see `config/database.php`) are
  already set with sane defaults (decorrelated-jitter backoff) for
  transient connection blips — no action needed unless you're seeing
  retry-storm behavior in logs, in which case widen `REDIS_BACKOFF_CAP`
  before disabling retries.

## Incident quick reference

| Symptom | Likely DB | First check |
|---|---|---|
| Purchases 500ing | 2 (stock) or app logs | `redis:diagnostics`; check `FlashSaleStock`'s warning-level logs for "falling back to the DB-authoritative path" |
| Jobs not processing | 0 (queue) | Horizon dashboard; `clients.connected` / `rejected_connections` |
| Slow page loads, cache seems cold | 1 (cache) | `ops.hit_rate_pct`; confirm `CACHE_STORE=redis` actually deployed |
| `OOM command not allowed` in logs | any, but check 0 and 2 first | `memory.used_pct_of_max`; raise `REDIS_MAXMEMORY` or investigate what's growing unbounded |
| Live stock counter frozen on frontend | N/A (Reverb/broadcast, not Redis itself) | Confirm `flash_sale.stock_broadcast_enabled`; Phase 5's broadcast failures are logged, not thrown, so check `FlashSaleStock`'s warning-level logs for "broadcastUpdated failed" |
