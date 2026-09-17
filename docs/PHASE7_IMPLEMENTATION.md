# Phase 7 — Testing & Rollout

Phase 7 validates the Redis flash-sale path under real concurrency and provides a reversible production rollout sequence.

## 1. Local smoke test

Start the stack:

```bash
docker compose up -d --build
```

Then verify application, Redis, Horizon, scheduler and Reverb are healthy:

```bash
docker compose ps
```

Run the normal test suite:

```bash
docker compose exec app php artisan test
```

## 2. Real Redis concurrency test

`tests/Feature/FlashSaleStockConcurrencyTest.php` starts separate PHP processes that all call the real Redis Lua reservation path. It seeds 25 units and fires 100 concurrent reservations; exactly 25 must return `1` and the other 75 must return `0`.

Run it with:

```bash
docker compose exec app php artisan test --filter=FlashSaleStockConcurrencyTest
```

This test is intentionally not a Redis mock. It exercises the atomic operation against the actual `stock` Redis connection.

## 3. Staging purchase load test

The `load-tests/flash-sale-purchase.js` script uses k6 and the real HTTP purchase endpoint. It supports a large pool of distinct staging users so the application's per-user purchase limiter does not artificially turn a thousands-of-users test into a single-user rate-limit test.

Create a local, uncommitted token file from `tokens.example.json`:

```bash
cp load-tests/tokens.example.json load-tests/tokens.json
```

Populate it only with **staging test-user bearer tokens**. Never use production credentials.

Example run:

```bash
k6 run \
  -e BASE_URL=https://staging.example.com \
  -e FLASH_SALE_ID=123 \
  -e PRODUCT_ID=456 \
  -e SHIPPING_ADDRESS_ID=789 \
  -e TOKENS_FILE=./load-tests/tokens.json \
  -e ARRIVAL_RATE=1000 \
  -e MAX_VUS=2000 \
  load-tests/flash-sale-purchase.js
```

The HTTP test checks that requests produce expected application outcomes (`202` accepted, or `409` for sold-out/duplicate protection) and that no `5xx` responses occur. It deliberately does **not** claim that HTTP responses alone prove order creation.

## 4. Proving the seeded quantity was actually sold

After the load test drains the sale, verify all three layers:

1. **Database** — the authoritative number of completed flash-sale orders / sold units equals the seeded quantity.
2. **Horizon** — the `flash-sale` queue has processed the expected accepted jobs, with no unexplained failed jobs and acceptable queue wait time.
3. **Redis** — the stock counter is consistent with the DB result before cleanup.

Use the admin Redis health endpoint or the operational diagnostics documented in `docs/REDIS_OPERATIONS.md` for Redis-side checks. Do not treat Redis alone as the source of truth; the DB remains authoritative.

## 5. Automated load-result verification

After the Horizon queue has drained, verify the authoritative DB result with:

```bash
docker compose exec app php artisan flash-sale:verify-load <FLASH_SALE_ITEM_ID> <EXPECTED_UNITS>
```

The command compares `flash_sale_items.quantity_sold` with the expected units and with the units represented by non-cancelled flash-sale order items. If the Redis stock key is still live, it also checks that its remaining value matches the DB-authoritative remaining stock. A mismatch returns a non-zero exit code so it can be used in CI/game-day scripts.

## 5. Rollout sequence

Each step is independently reversible:

### Step A — Phase 1

Deploy Redis-backed cache/session/queue configuration. Keep `FLASH_SALE_REDIS_STOCK_ENABLED=false` for the first production observation window.

Smoke-test login, cart and checkout.

### Step B — Phase 3

Enable Horizon and the dedicated `flash-sale` queue. Observe queue wait time, throughput and failures during a synthetic or low-risk sale.

### Step C — Phase 2

Set `FLASH_SALE_REDIS_STOCK_ENABLED=true` for the target sale. Confirm the Redis stock key is seeded correctly before traffic starts.

Emergency rollback is a config change back to `false`; the DB-authoritative reservation path remains in place.

### Step D — Phase 4

Enable purchase rate limiting and idempotency protection. Confirm legitimate multi-user traffic is not being collapsed by shared credentials or test clients.

### Step E — Phase 5

Enable live stock broadcasts. Reverb failure must degrade to stale/no live stock display, never to a failed purchase.

## 6. Go/no-go checks

Proceed only when the staging run demonstrates:

- exact reservation count under concurrency;
- no overselling in the DB;
- no unexplained `5xx` responses;
- Horizon processes accepted purchase jobs and exposes failures;
- Redis AOF survives a restart test;
- Redis memory remains below the configured operating threshold;
- rollback to `FLASH_SALE_REDIS_STOCK_ENABLED=false` returns purchases to the DB-only reservation path.

## 7. Rollback

For an active incident:

```bash
# Set in the deployment environment:
FLASH_SALE_REDIS_STOCK_ENABLED=false
```

Then clear/rebuild Laravel configuration as appropriate for the deployment:

```bash
php artisan config:clear
# or restart the application after updating a cached configuration
```

Do not delete `FlashSaleItem::tryReserve()` or the DB reservation path. It is the permanent correctness fallback.
