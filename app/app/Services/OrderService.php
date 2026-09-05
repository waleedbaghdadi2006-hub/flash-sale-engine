<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\OptimisticLockConflictException;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\FlashSaleItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    /**
     * How many times to retry an attempt after losing an optimistic-lock
     * race, before giving up and telling the customer to try again.
     * Flash-sale traffic is exactly the case this exists for.
     */
    private const MAX_LOCK_RETRIES = 5;

    /**
     * Checkout the user's current cart into an order.
     *
     * Every line item's stock is decremented under an optimistic lock
     * (inventory.version) inside a single DB transaction. If any line
     * loses its lock race, the whole attempt is retried from scratch
     * (bounded) rather than partially applied.
     *
     * @throws InsufficientStockException
     */
    public function createFromCart(
        User $user,
        int $shippingAddressId,
        ?int $billingAddressId,
        ?string $couponCode = null,
    ): Order {
        $cart = Cart::query()->where('user_id', $user->id)->with('items.product')->first();

        if (!$cart || $cart->items->isEmpty()) {
            throw new \RuntimeException('Your cart is empty.');
        }

        for ($attempt = 0; $attempt < self::MAX_LOCK_RETRIES; $attempt++) {
            try {
                return DB::transaction(function () use ($cart, $user, $shippingAddressId, $billingAddressId, $couponCode) {
                    $subtotal = '0.00';
                    $lineData = [];

                    foreach ($cart->items as $cartItem) {
                        $product = Product::query()->find($cartItem->product_id);
                        $inventory = $product?->inventory;

                        if (!$product || !$inventory) {
                            throw new InsufficientStockException("'{$cartItem->product_id}' is no longer available.");
                        }
                        if ($inventory->quantity_available < $cartItem->quantity) {
                            throw new InsufficientStockException("'{$product->name}' doesn't have enough stock.");
                        }

                        $this->decrementInventoryOrConflict($inventory, $cartItem->quantity);

                        $lineTotal = bcmul((string) $cartItem->unit_price_snapshot, (string) $cartItem->quantity, 2);
                        $subtotal = bcadd($subtotal, $lineTotal, 2);

                        $lineData[] = [
                            'product_id' => $product->id,
                            'product_name_snapshot' => $product->name,
                            'quantity' => $cartItem->quantity,
                            'unit_price' => $cartItem->unit_price_snapshot,
                        ];
                    }

                    [$discount, $coupon] = $this->applyCoupon($couponCode, $subtotal);

                    // Shipping/tax calculation is intentionally left as a flat
                    // placeholder here — plug in real rate/tax logic later.
                    $shippingAmount = '0.00';
                    $taxAmount = '0.00';
                    $total = bcsub(bcadd(bcadd($subtotal, $shippingAmount, 2), $taxAmount, 2), $discount, 2);

                    $order = Order::create([
                        'order_number' => $this->generateOrderNumber(),
                        'user_id' => $user->id,
                        'coupon_id' => $coupon?->id,
                        'shipping_address_id' => $shippingAddressId,
                        'billing_address_id' => $billingAddressId ?? $shippingAddressId,
                        'subtotal' => $subtotal,
                        'discount_amount' => $discount,
                        'shipping_amount' => $shippingAmount,
                        'tax_amount' => $taxAmount,
                        'total_price' => max(0, (float) $total),
                        'currency' => 'USD',
                        'status' => 'pending',
                    ]);

                    foreach ($lineData as $line) {
                        $order->items()->create($line);
                    }

                    if ($coupon) {
                        $coupon->increment('times_used');
                    }

                    $cart->items()->delete();

                    return $order->load('items');
                });
            } catch (OptimisticLockConflictException) {
                usleep(random_int(10_000, 50_000));
                continue;
            }
        }

        throw new InsufficientStockException('Could not reserve stock due to high demand. Please try again.');
    }

    /**
     * Create an order for a single flash-sale purchase.
     *
     * Guards two independent caps with optimistic locks in the same
     * transaction: the flash sale's own quantity_limit (flash_sale_items
     * .version) and the product's real stock (inventory.version). Either
     * one losing its race rolls back the whole transaction and the caller
     * retries.
     *
     * @throws InsufficientStockException
     */
    public function createFromFlashSalePurchase(
        User $user,
        FlashSaleItem $flashSaleItem,
        int $quantity,
        int $shippingAddressId,
        ?int $billingAddressId = null,
    ): Order {
        for ($attempt = 0; $attempt < self::MAX_LOCK_RETRIES; $attempt++) {
            try {
                return DB::transaction(function () use ($user, $flashSaleItem, $quantity, $shippingAddressId, $billingAddressId) {
                    // Re-fetch inside the transaction so we're checking the
                    // latest version, not a possibly-stale copy passed in.
                    $item = FlashSaleItem::query()->find($flashSaleItem->id);
                    $product = Product::query()->find($item->product_id);
                    $inventory = $product?->inventory;

                    if (!$item || !$product || !$inventory) {
                        throw new InsufficientStockException('This item is no longer available.');
                    }
                    if ($item->quantity_sold + $quantity > $item->quantity_limit) {
                        throw new InsufficientStockException('This item is sold out.');
                    }
                    if ($inventory->quantity_available < $quantity) {
                        throw new InsufficientStockException('This item is sold out.');
                    }

                    $itemLocked = FlashSaleItem::query()
                        ->where('id', $item->id)
                        ->where('version', $item->version)
                        ->update([
                            'quantity_sold' => $item->quantity_sold + $quantity,
                            'version' => $item->version + 1,
                        ]);

                    if (!$itemLocked) {
                        throw new OptimisticLockConflictException();
                    }

                    $this->decrementInventoryOrConflict($inventory, $quantity);

                    $unitPrice = $item->sale_price;
                    $subtotal = bcmul((string) $unitPrice, (string) $quantity, 2);

                    $order = Order::create([
                        'order_number' => $this->generateOrderNumber(),
                        'user_id' => $user->id,
                        'flash_sale_id' => $item->flash_sale_id,
                        'shipping_address_id' => $shippingAddressId,
                        'billing_address_id' => $billingAddressId ?? $shippingAddressId,
                        'subtotal' => $subtotal,
                        'discount_amount' => 0,
                        'shipping_amount' => 0,
                        'tax_amount' => 0,
                        'total_price' => $subtotal,
                        'currency' => $product->currency,
                        'status' => 'pending',
                    ]);

                    $order->items()->create([
                        'product_id' => $product->id,
                        'product_name_snapshot' => $product->name,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                    ]);

                    return $order->load('items');
                });
            } catch (OptimisticLockConflictException) {
                usleep(random_int(10_000, 50_000));
                continue;
            }
        }

        throw new InsufficientStockException('Could not reserve stock due to high demand. Please try again.');
    }

    /**
     * Cancel a pending/confirmed order and release the stock it reserved.
     * Flash-sale quantity_sold is intentionally NOT decremented — once a
     * unit has been allocated to a sale it stays counted against the cap,
     * matching how most flash-sale promos are run (no re-selling a
     * cancelled slot back into the same event).
     */
    public function cancel(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            foreach ($order->items()->with('product.inventory')->get() as $item) {
                $inventory = $item->product?->inventory;
                if ($inventory) {
                    $inventory->increment('quantity_available', $item->quantity);
                    $inventory->increment('version');
                }
            }

            $order->update(['status' => 'cancelled']);

            return $order;
        });
    }

    /**
     * Decrement inventory.quantity_available under an optimistic lock.
     *
     * @throws OptimisticLockConflictException if another request updated
     *         the row first (version mismatch).
     */
    private function decrementInventoryOrConflict(\App\Models\Inventory $inventory, int $quantity): void
    {
        $updated = DB::table('inventory')
            ->where('id', $inventory->id)
            ->where('version', $inventory->version)
            ->update([
                'quantity_available' => $inventory->quantity_available - $quantity,
                'version' => $inventory->version + 1,
                'updated_at' => now(),
            ]);

        if (!$updated) {
            throw new OptimisticLockConflictException();
        }
    }

    /**
     * Validate a coupon code and return [discountAmount, Coupon|null].
     * Silently ignores an invalid/expired/inapplicable code rather than
     * failing checkout — surface a warning to the user upstream if you'd
     * rather it be a hard error.
     */
    private function applyCoupon(?string $code, string $subtotal): array
    {
        if (!$code) {
            return ['0.00', null];
        }

        $coupon = Coupon::query()->where('code', $code)->where('is_active', true)->first();

        if (!$coupon) {
            return ['0.00', null];
        }
        if ($coupon->starts_at && now()->lt($coupon->starts_at)) {
            return ['0.00', null];
        }
        if ($coupon->expires_at && now()->gt($coupon->expires_at)) {
            return ['0.00', null];
        }
        if ($coupon->max_uses !== null && $coupon->times_used >= $coupon->max_uses) {
            return ['0.00', null];
        }
        if (bccomp($subtotal, (string) $coupon->min_order_amount, 2) < 0) {
            return ['0.00', null];
        }

        $discount = $coupon->discount_type === 'percentage'
            ? bcmul($subtotal, bcdiv((string) $coupon->discount_value, '100', 4), 2)
            : (string) $coupon->discount_value;

        // Never discount more than the subtotal itself.
        if (bccomp($discount, $subtotal, 2) > 0) {
            $discount = $subtotal;
        }

        return [$discount, $coupon];
    }

    private function generateOrderNumber(): string
    {
        return 'ORD-' . strtoupper(Str::random(10));
    }
}
