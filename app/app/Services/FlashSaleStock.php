<?php

namespace App\Services;

use App\Events\StockUpdated;
use App\Models\FlashSaleItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Redis-backed atomic stock counters for flash-sale items.
 *
 * This is a fast gatekeeper in front of the existing DB-authoritative
 * reservation (`FlashSaleItem::tryReserve()` via
 * `PurchaseService::reserveFlashSaleLine()`) — NOT a replacement for it.
 * Redis absorbs the burst and rejects "sold out" requests in microseconds
 * via an atomic Lua script, so MySQL is never touched for the ~99% of
 * flash-sale requests that are clean wins or clean sold-out rejections.
 *
 * The DB row lock / optimistic version check remains the transactional
 * source of truth (defense in depth against crashes, manual DB edits, or
 * Redis/DB drift). Under normal operation it will never actually contend,
 * because Redis has already deduplicated down to exactly `quantity_limit`
 * winners.
 *
 * Every public method fails closed: if Redis is unreachable or has never
 * been seeded for an item, callers get back a signal that means "this
 * isn't authoritative," never a false RESERVED or SOLD_OUT.
 */
class FlashSaleStock
{
    /** Lua script result: counter not seeded yet for this item. */
    public const MISS = -1;

    /** Lua script result: seeded, but not enough stock remaining. */
    public const SOLD_OUT = 0;

    /** Lua script result: reservation succeeded. */
    public const RESERVED = 1;

    /**
     * reserve() sentinel: Redis itself is unreachable, or was still a MISS
     * after we tried to seed it. Distinct from SOLD_OUT/RESERVED so callers
     * can never mistake "we don't know" for an authoritative answer.
     */
    public const UNAVAILABLE = -2;

    /**
     * Atomically checks and decrements the counter in a single round trip
     * so concurrent buyers can never both read "stock available" and both
     * decrement past zero.
     */
    private const RESERVE_SCRIPT = <<<'LUA'
        local stock = tonumber(redis.call('GET', KEYS[1]))
        if stock == nil then return -1 end      -- not seeded: caller falls back to DB path
        if stock < tonumber(ARGV[1]) then return 0 end   -- sold out
        redis.call('DECRBY', KEYS[1], ARGV[1])
        return 1
    LUA;

    /**
     * Only increments if the counter already exists. Guards against a
     * release() (cancellation, or a failed DB reservation after Redis said
     * yes) silently creating a brand-new, wrong-value counter for an item
     * whose reservation never actually went through Redis in the first
     * place (e.g. it was served by the UNAVAILABLE fallback path).
     */
    private const RELEASE_SCRIPT = <<<'LUA'
        if redis.call('EXISTS', KEYS[1]) == 1 then
            redis.call('INCRBY', KEYS[1], ARGV[1])
        end
        return 1
    LUA;

    /**
     * Seed the counter for a flash-sale item. NX so a re-run, a cold
     * cache, or a second concurrent seeder never stomps a counter that's
     * already live and has already absorbed reservations.
     */
    public function seed(int $flashSaleItemId, int $remaining): void
    {
        $key = $this->key($flashSaleItemId);

        try {
            $result = Redis::connection('stock')->set(
                $key,
                $remaining,
                null,
                null,
                'NX'
            );

            logger()->info('FlashSaleStock seed', [
                'key' => $key,
                'remaining' => $remaining,
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            logger()->error('FlashSaleStock seed failed', [
                'key' => $key,
                'remaining' => $remaining,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
    /**
     * Raw primitive: -1 miss (not seeded), 0 sold out, 1 reserved.
     *
     * Throws if Redis is unreachable — most callers want fail-closed
     * behavior instead, so they should call reserve() rather than this
     * directly.
     */
    public function tryReserve(int $flashSaleItemId, int $quantity): int
    {
        return (int) Redis::connection('stock')->eval(
            self::RESERVE_SCRIPT,
            1,
            $this->key($flashSaleItemId),
            $quantity,
        );
    }

    /**
     * High-level entry point for the purchase path. Wraps tryReserve()
     * with the glue every caller needs:
     *
     *  - Lazy seeding: a MISS means the counter has never been primed for
     *    this item (no scheduled "sale went active" listener has seeded it
     *    yet), so seed it from the caller-supplied authoritative remaining
     *    stock and retry exactly once.
     *  - Fail closed on any Redis error: a Redis hiccup must never look
     *    like SOLD_OUT (would wrongly reject a real buyer) or RESERVED
     *    (would skip the DB gate on a live sale). Callers should treat
     *    UNAVAILABLE exactly like the pre-Redis behavior — fall back to
     *    the DB-authoritative path and let its lock decide.
     */
    public function reserve(FlashSaleItem $flashSaleItem, int $quantity): int
    {
        try {
            $result = $this->tryReserve($flashSaleItem->id, $quantity);

            if ($result !== self::MISS) {
                return $result;
            }

            // Cold counter — seed it from the authoritative DB value and
            // give the atomic path one more chance before falling back.
            $this->seed($flashSaleItem->id, $flashSaleItem->remainingStock());

            $result = $this->tryReserve($flashSaleItem->id, $quantity);

            return $result === self::MISS ? self::UNAVAILABLE : $result;
        } catch (Throwable $e) {
            $this->logFailure('reserve', $flashSaleItem->id, $e);

            return self::UNAVAILABLE;
        }
    }

    /**
     * Return stock to the live counter. Used for:
     *  - cancellations, so a cancelled order's units become purchasable again;
     *  - a DB-side reservation that failed after Redis already said yes,
     *    so a transient failure doesn't permanently shrink the advertised
     *    stock.
     *
     * Best-effort: if Redis is down the DB remains the source of truth.
     * A dropped release leaves the Redis counter under-reporting stock
     * (safe — it can only cause an over-eager "sold out", never
     * overselling) until it's corrected; operationally, deleting the key
     * lets it re-seed from the DB on the next request.
     */
    public function release(int $flashSaleItemId, int $quantity): void
    {
        try {
            Redis::connection('stock')->eval(
                self::RELEASE_SCRIPT,
                1,
                $this->key($flashSaleItemId),
                $quantity,
            );
        } catch (Throwable $e) {
            $this->logFailure('release', $flashSaleItemId, $e);
        }
    }

    /**
     * Broadcast a `StockUpdated` event for this item, per Phase 5.
     *
     * Never allowed to affect the purchase it's called from: every failure
     * mode here (broadcasting disabled, Redis unreachable, Reverb down) is
     * caught and logged rather than thrown, and callers must not
     * (and don't) wrap this in anything that could turn a broadcast
     * failure into a purchase failure.
     *
     * `'reserved'` events are throttled via `flash_sale.stock_broadcast_every`
     * — a hot item can reserve hundreds of times a second and nobody needs
     * a client-side counter to redraw on every single one. `'released'`
     * events (cancellations, a DB reservation that failed after Redis said
     * yes) always broadcast: they're rare, and a suppressed correction
     * would leave connected clients showing less stock than truly remains.
     */
    public function broadcastUpdated(FlashSaleItem $flashSaleItem, string $reason = 'reserved'): void
    {
        if (!config('flash_sale.stock_broadcast_enabled')) {
            return;
        }

        try {
            if ($reason === 'reserved' && !$this->shouldBroadcastReservation($flashSaleItem->id)) {
                return;
            }

            event(new StockUpdated(
                flashSaleId: $flashSaleItem->flash_sale_id,
                flashSaleItemId: $flashSaleItem->id,
                remainingStock: $this->remainingStockFor($flashSaleItem),
                reason: $reason,
            ));
        } catch (Throwable $e) {
            Log::warning('FlashSaleStock: broadcastUpdated failed; live stock updates degraded, purchase unaffected.', [
                'flash_sale_item_id' => $flashSaleItem->id,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete this item's Redis stock counter and its broadcast-throttle
     * key. Called explicitly once a sale has ended (see
     * `flash-sale:cleanup-stock`) — counters intentionally carry no TTL
     * while a sale is live, so this is the only way they go away.
     * Best-effort: a failure here just leaves a harmless stale key behind.
     */
    public function forget(int $flashSaleItemId): void
    {
        try {
            Redis::connection('stock')->del([
                $this->key($flashSaleItemId),
                $this->broadcastThrottleKey($flashSaleItemId),
            ]);
        } catch (Throwable $e) {
            $this->logFailure('forget', $flashSaleItemId, $e);
        }
    }

    /**
     * Every Nth 'reserved' event broadcasts, per
     * `flash_sale.stock_broadcast_every`. Throwing here (e.g. Redis is
     * down) is intentional — it's caught by broadcastUpdated()'s try/catch
     * exactly like every other failure mode in this class.
     */
    private function shouldBroadcastReservation(int $flashSaleItemId): bool
    {
        $every = max(1, (int) config('flash_sale.stock_broadcast_every', 1));

        $count = Redis::connection('stock')->incr($this->broadcastThrottleKey($flashSaleItemId));

        return $count % $every === 0;
    }

    /**
     * Best-effort "what's actually left" for the broadcast payload: prefer
     * the live Redis counter (what buyers are actually racing against),
     * falling back to the DB-authoritative value only when Redis has
     * nothing for this item (disabled, unavailable, or never seeded — e.g.
     * a 'released' correction from a cancellation on a sale that never
     * used the Redis gatekeeper).
     */
    private function remainingStockFor(FlashSaleItem $flashSaleItem): int
    {
        $value = Redis::connection('stock')->get($this->key($flashSaleItem->id));

        if ($value !== null && $value !== false) {
            return (int) $value;
        }

        $fresh = $flashSaleItem->exists ? $flashSaleItem->fresh() : null;

        return ($fresh ?? $flashSaleItem)->remainingStock();
    }

    private function broadcastThrottleKey(int $flashSaleItemId): string
    {
        return "flashsale:{$flashSaleItemId}:broadcast_throttle";
    }

    private function key(int $flashSaleItemId): string
    {
        return "flashsale:{$flashSaleItemId}:stock";
    }

    private function logFailure(string $operation, int $flashSaleItemId, Throwable $e): void
    {
        Log::warning("FlashSaleStock: Redis {$operation} failed; falling back to the DB-authoritative path.", [
            'flash_sale_item_id' => $flashSaleItemId,
            'error' => $e->getMessage(),
        ]);
    }
}
