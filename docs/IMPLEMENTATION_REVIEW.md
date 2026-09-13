# Flash Sale Engine — Implementation Review

## Scope

This pass applies the findings from the supplied code review and keeps payment intentionally incomplete for local development. No real money movement is implemented.

## Fixes implemented

### 1. Payment trust boundary — fixed for local development

`PaymentController::store()` no longer accepts a client-controlled payment amount, result/status, failure reason, or provider transaction id.

For `local`/`testing` only:

1. The client calls `POST /orders/{order}/payments`.
2. `PaymentService::confirmLocal()` reads the order total from the database.
3. A `local_*` transaction id is generated server-side.
4. The payment is recorded as `succeeded` and the order moves from `pending` to `confirmed`.

Outside `local`/`testing`, this local endpoint is disabled. `PaymentWebhookController` is also disabled outside local/test until a real provider signature check is installed.

### 2. Flash-sale optimistic-lock retries — fixed

`OrderService::createFromFlashSalePurchase()` now retries the `FlashSaleItem` optimistic reservation up to five times. A stale-version collision refreshes the row and retries instead of immediately reporting the item as unavailable.

### 3. Coupon usage race — fixed

Coupons are now loaded with `lockForUpdate()` inside the checkout transaction before checking `max_uses` and incrementing `times_used`.

### 4. Flash-sale cancellation — fixed

Flash-sale orders now persist `flash_sale_id`. Cancellation detects these orders and decrements `flash_sale_items.quantity_sold` under row lock. Normal product orders continue to restore `inventory.quantity_available` and now also decrement `inventory.quantity_reserved`.

### 5. Login enumeration — fixed

Unknown emails and incorrect passwords both use the generic `Invalid credentials.` validation message.

### 6. Rate limiting — implemented

Named limiters were added:

- `auth`: 10 requests/minute per IP + email combination.
- `api`: 120 requests/minute per authenticated user, or per IP for unauthenticated API calls.

Auth endpoints and API groups now use the appropriate throttling middleware.

### 7. Raw token logging — restricted

Email verification and password-reset tokens are logged only in the `local` environment for Postman/development convenience.

### 8. Inventory reservation semantics — fixed

The normal checkout path now calls `Inventory::tryReserve()`, so both `quantity_available` and `quantity_reserved` stay consistent with the model's intended semantics.

### 9. Money arithmetic — improved

Order and payment money comparisons/calculations now use integer minor units (cents) internally rather than float arithmetic, while existing decimal database columns remain unchanged.

### 10. Force-delete authorization — fixed

Permanent product deletion with `?force=true` is now restricted to `admin`. Staff may still use the normal soft delete.

### 11. Flash-sale status ownership — fixed

Purchase status is now authenticated and the cached purchase reference stores the owning user id. A different user receives a not-found response instead of another user's order data.

### 12. Search wildcard behavior — fixed

Product and admin coupon searches escape `%` and `_` wildcard characters so a literal search term cannot unintentionally broaden `LIKE` matching.

## Validation performed

- PHP syntax check passed for all 59 PHP files under `app/app`, `app/routes`, and `app/bootstrap`.
- The Laravel test suite could not be executed in this sandbox because the uploaded project has no `vendor/` directory and Composer is not installed in the environment.

## User workflow review

### A. Register / login

`POST /auth/register`

`AuthController::register()` creates a customer account, hashes the password, writes an audit event, and creates an email-verification token. A local-only log entry exposes the raw verification token for development testing. No JWT is issued until verification.

`POST /auth/verify-email`

The raw token is hashed and matched against the stored token hash. On success the user's `email_verified_at` is set and the token is consumed.

`POST /auth/login`

`AuthController::login()` finds the user, checks lockout state, verifies the password, requires email verification, clears successful-login lockout state, issues a JWT and refresh-token-backed session, and writes an audit event.

`POST /auth/refresh`

The refresh token is hashed and matched to an unexpired `UserSession`; the old session is deleted and a new refresh token/JWT pair is issued.

`POST /auth/logout`

Deletes the supplied refresh session and invalidates the JWT, then writes an audit event.

`POST /auth/forgot-password` -> `POST /auth/reset-password`

The reset flow uses hashed single-use tokens. Raw reset tokens are logged only locally.

### B. Product browsing

`GET /products` and `GET /products/{idOrSlug}` are public. `ProductController::index()` supports category, active-state, search, price range, and sorting filters. The normal response includes category, images, and inventory relationships.

Admin/staff product mutations are protected by `auth:api` + `role:admin,staff`. Permanent deletion is now admin-only when `force=true`.

### C. Add to cart

`POST /cart/items`

`CartController::addItem()` authenticates the user, resolves/creates their cart, checks the product and available inventory, and merges an existing cart line if necessary. The current product price is stored in `unit_price_snapshot`.

`PATCH /cart/items/{item}` updates quantity within the user's own cart. `DELETE /cart/items/{item}` removes the line. `DELETE /cart` empties the cart.

Important: adding to cart does **not** reserve stock. Stock is authoritative at checkout.

### D. Standard Buy Now button

A normal product Buy Now action should call:

`POST /cart/buy-now`

`CartController::buyNow()` does not touch the cart. It sends the selected product + quantity + shipping/billing address + optional coupon directly to `OrderService::createFromItems()`.

Inside one transaction:

1. The user's addresses are verified.
2. The product row is locked.
3. Its inventory row is locked.
4. `Inventory::tryReserve()` decrements `quantity_available` and increments `quantity_reserved`.
5. Price totals are calculated using integer cents.
6. An order is created with status `pending`.
7. Order-item snapshots are created.
8. A coupon, if present, is usage-locked and `times_used` is incremented.

The response is the new pending order. **Payment does not happen automatically here.**

### E. Cart Checkout button

The standard checkout button calls:

`POST /orders`

`OrderController::store()` delegates to `OrderService::createFromCart()`.

`createFromCart()` locks the user's cart, converts its lines into the same `createFromItems()` path described above, and deletes the cart lines only after successful order creation. If stock/coupon/address validation fails, the transaction rolls back and the cart stays intact.

### F. Local Pay button

After a normal or flash-sale order exists, local development can call:

`POST /orders/{order}/payments`

Only a `provider` value of `local_mock` is accepted. The amount, success status, and transaction id are generated/derived by the server.

`PaymentService` locks the order. If it is still `pending`, it creates a successful payment using the order's exact stored total and changes the order to `confirmed`.

A second payment attempt on an already-confirmed order is rejected.

### G. Flash-sale Buy button

The flash-sale purchase button calls:

`POST /flash-sales/{flashSale}/purchase`

The request must be authenticated and pass `flash_sale.active` middleware. The controller verifies that the selected product belongs to the sale and performs a quick non-authoritative stock check.

It then:

1. Generates a UUID reference.
2. Stores a `pending` status plus the owner user id in cache.
3. Dispatches `ProcessFlashSalePurchase`.
4. Returns HTTP 202 with `reference_id` and a status URL.

The queue job loads the user and flash-sale item, retries optimistic reservation collisions up to five times, and if reservation succeeds creates a pending order with `flash_sale_id` set. It writes the final result back to the reference cache.

The client polls:

`GET /flash-sales/purchases/{reference}/status`

The endpoint now requires authentication and compares the cached owner id to `auth()->id()` before returning the result.

The flash-sale order is still `pending` after reservation. The application must then use the local payment endpoint to move it to `confirmed`.

### H. Cancel order

`POST /orders/{id}/cancel`

Customers may cancel `pending` and `confirmed` orders before shipment.

For normal orders, inventory is returned to `quantity_available`, `quantity_reserved` is reduced, and the inventory version advances.

For flash-sale orders, `quantity_sold` is reduced on the corresponding `flash_sale_items` row and its version advances. This is the important distinction between normal inventory and flash-sale inventory.

## Current architecture by responsibility

| Responsibility | Main component | What it handles |
|---|---|---|
| Authentication | `AuthController` | Register, verify, login, refresh, logout, password reset, audit calls |
| Auth throttling | `AppServiceProvider` + route middleware | Rate limiting for auth/API traffic |
| Roles | `EnsureUserHasRole` | Admin/staff/customer authorization at route level |
| Flash-sale timing | `EnsureFlashSaleIsActive` | Start/end/cancel checks using the real clock |
| Products | `ProductController` | Browsing + admin/staff CRUD |
| Cart | `CartController` | User cart lifecycle; no stock reservation at add-to-cart time |
| Order creation | `OrderService` | Transactions, addresses, inventory reservation, coupons, totals, order items |
| Standard stock | `Inventory` | Versioned available/reserved quantities |
| Flash-sale stock | `FlashSaleItem` + `OrderService` | Versioned quantity cap and retrying optimistic reservation |
| Async flash-sale processing | `ProcessFlashSalePurchase` | Queue execution and purchase-status cache |
| Orders | `OrderController` | History, detail, checkout, cancellation |
| Local payments | `PaymentController` + `PaymentService` | Development-only payment simulation |
| Real payment integration scaffold | `PaymentWebhookController` | Placeholder for a future signed gateway webhook |
| Addresses | `AddressController` | Customer shipping/billing address CRUD |
| Coupons | `Admin\\CouponController` | Admin coupon management |
| Data integrity | Migrations / DB constraints | FKs, uniqueness, quantity/status constraints |

## Remaining deployment work

The local workflow is intentionally not the production payment workflow. Before real deployment, replace the local payment simulation with a provider checkout/payment-intent flow, implement actual webhook signature verification, make webhook processing idempotent, and verify the provider amount/currency server-side.

Also review reservation expiration: a failed payment currently leaves the order `pending` and its reserved stock remains held until the order is cancelled. A production system normally needs a timeout/release mechanism.

For flash-sale scale, use the production Redis infrastructure for queue/cache rather than relying on the local database-backed queue/cache defaults, and load-test the queue/DB contention path with concurrent buyers.

Other useful production hardening items are request idempotency for double-click/retry safety, guest-cart support if needed, and deciding whether flash-sale purchases should be limited per user across multiple orders rather than only by `MAX_QUANTITY_PER_PURCHASE` per order.
