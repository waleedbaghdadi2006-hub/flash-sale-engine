# Purchase Workflow

The customer-facing purchase workflow is centralized in `App\\Services\\PurchaseService`.

## One workflow, two stock strategies

Both regular and flash-sale buying follow:

`request -> validate checkout -> reserve stock -> calculate price -> create order -> pending payment`

The only intentional difference is the reservation strategy:

- Regular product: reserve from `inventory` using the version-guarded `Inventory::tryReserve()` path.
- Flash-sale product: reserve from `flash_sale_items` while locking the flash-sale row so concurrent queue workers see authoritative remaining stock.

## Regular product: Buy Now

`POST /cart/buy-now`

`CartController::buyNow()` -> `PurchaseService::purchaseRegular()` -> shared order pipeline.

The cart is not modified. The selected product and quantity are converted to one purchase line, normal inventory is reserved, the base product price is used, and a `pending` order is created.

## Regular product: Cart checkout

`POST /orders`

`OrderController::store()` -> `PurchaseService::purchaseFromCart()` -> `purchaseRegular()` -> shared order pipeline.

The user's cart is locked, its items become purchase lines, normal inventory is reserved, coupons are evaluated under a row lock, the order is created, and the cart is cleared after successful order creation.

## Flash-sale product

`POST /flash-sales/{flashSale}/purchase`

`FlashSaleController::purchase()` first consults `App\Services\FlashSaleStock`, an atomic Redis counter (`flashsale:{id}:stock`) that sits in front of the database as a fast gatekeeper:

- **Sold out** — the Lua reservation script sees insufficient stock and returns instantly; the request is rejected with a 409 and MySQL is never touched.
- **Reserved** — the script wins the decrement; the request proceeds to the queue.
- **Unavailable** (counter not yet seeded and lazy-seeding failed, or Redis itself is unreachable) — falls back to the pre-Redis behavior: a cheap `remainingStock()` check, then queue it and let the database lock decide.

Only requests that clear this gate create a purchase reference and dispatch `ProcessFlashSalePurchase`, along with a flag recording whether Redis actually reserved a unit for this attempt.

The job calls `PurchaseService::purchaseFlashSale()`, which enters the same shared order pipeline. The only specialized step is reserving `flash_sale_items.quantity_sold` rather than `inventory.quantity_available` — this database-side reservation remains the permanent, transactional source of truth regardless of what Redis said. If it fails after Redis already reserved a unit (crash recovery, manual DB edits, Redis/DB drift), the job hands the unit back via `FlashSaleStock::release()` so the failure doesn't permanently shrink the advertised stock.

The Redis gate can be disabled instantly via the `flash_sale.redis_stock_enabled` config flag (`FLASH_SALE_REDIS_STOCK_ENABLED` env var) without any code changes, falling back to the original cheap-check-then-queue path.

The client polls `GET /flash-sales/purchases/{reference}/status` to learn whether the queued attempt completed or failed.

## Payment

All created orders start as `pending`.

For local development, `POST /orders/{order}/payments` calls `PaymentService::confirmLocal()`.

The server derives the amount from the stored order total, generates the local transaction id, records a successful payment, and changes the order to `confirmed`.

No real payment gateway is implemented yet. A production integration should use provider checkout/intents and signed webhooks rather than trusting client input.

## Cancellation

`POST /orders/{id}/cancel`

`OrderController::cancel()` -> `OrderService::cancel()`.

- Regular order: releases quantity back to `inventory` and decreases `quantity_reserved`.
- Flash-sale order: releases quantity from `flash_sale_items.quantity_sold`, and also calls `FlashSaleStock::release()` to return the unit to the live Redis counter (skipped once the sale has ended, since the key is simply left to expire).

## Why the API entry points differ

The API endpoints differ because the UI actions are different and flash-sale traffic needs asynchronous processing. The business workflow does not: all paths converge on `PurchaseService` and the same order creation/pricing flow.
