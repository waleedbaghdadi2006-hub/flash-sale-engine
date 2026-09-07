<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\User;
use App\Models\FlashSaleItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    /**
     * Converts an explicit list of line items into an order.
     *
     * This is the single place order creation actually happens.[cite: 1]
     *   - createFromCart()               resolves items from the user's
     *                                     cart, calls this, then clears
     *                                     the cart.[cite: 1]
     *   - CartController::buyNow()       calls this directly with just the
     *                                     one product/quantity the user
     *                                     selected — it never touches the
     *                                     cart at all.[cite: 1]
     *   - FlashSaleController::purchase() intentionally does NOT go through
     *     here — flash sale stock is reserved via FlashSaleItem's own
     *     optimistic lock in a queued job, since it has its own quantity cap
     *     separate from `inventory`.[cite: 1]
     *
     * @param array<int, array{product_id:int, quantity:int}> $items
     *
     * @throws InsufficientStockException when stock can't cover the items[cite: 1]
     * @throws \RuntimeException for any other checkout-blocking condition[cite: 1]
     *         (no items, bad address, bad/expired coupon, etc.)[cite: 1]
     */
    public function createFromItems(
        User $user,
        array $items,
        int $shippingAddressId,
        ?int $billingAddressId = null,
        ?string $couponCode = null,
    ): Order {
        return DB::transaction(function () use ($user, $items, $shippingAddressId, $billingAddressId, $couponCode) {
            if (empty($items)) {
                throw new \RuntimeException('No items to purchase.');
            }

            $shippingAddress = Address::where('id', $shippingAddressId)
                ->where('user_id', $user->id)
                ->first();

            if (!$shippingAddress) {
                throw new \RuntimeException('Invalid shipping address.');
            }

            $billingAddress = null;
            if ($billingAddressId !== null) {
                $billingAddress = Address::where('id', $billingAddressId)
                    ->where('user_id', $user->id)
                    ->first();

                if (!$billingAddress) {
                    throw new \RuntimeException('Invalid billing address.');
                }
            }

            $subtotal = 0.0;
            $orderItemsData = [];

            foreach ($items as $item) {
                $productId = (int) $item['product_id'];
                $quantity = (int) $item['quantity'];

                if ($quantity < 1) {
                    throw new \RuntimeException('Item quantity must be at least 1.');
                }

                $product = \App\Models\Product::where('id', $productId)
                    ->lockForUpdate()
                    ->first();

                if (!$product || !$product->is_active || $product->trashed()) {
                    throw new InsufficientStockException(
                        "A selected product (ID {$productId}) is no longer available."
                    );
                }

                $inventory = Inventory::where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();

                $available = $inventory->quantity_available ?? 0;

                if (!$inventory || $available < $quantity) {
                    throw new InsufficientStockException(
                        "Insufficient stock for \"{$product->name}\" (only {$available} available)."
                    );
                }

                $updated = DB::table('inventory')
                    ->where('id', $inventory->id)
                    ->where('version', $inventory->version)
                    ->update([
                        'quantity_available' => $inventory->quantity_available - $quantity,
                        'version' => $inventory->version + 1,
                        'updated_at' => now(),
                    ]);

                if (!$updated) {
                    throw new InsufficientStockException(
                        "Stock for \"{$product->name}\" changed while checking out — please try again."
                    );
                }

                $unitPrice = (float) $product->base_price;
                $subtotal += $unitPrice * $quantity;

                $orderItemsData[] = [
                    'product_id' => $product->id,
                    'product_name_snapshot' => $product->name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                ];
            }

            $coupon = null;
            $discountAmount = 0.0;

            if ($couponCode !== null && $couponCode !== '') {
                $coupon = Coupon::where('code', $couponCode)
                    ->where('is_active', true)
                    ->first();

                if (!$coupon) {
                    throw new \RuntimeException('Invalid coupon code.');
                }
                if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
                    throw new \RuntimeException('This coupon is not active yet.');
                }
                if ($coupon->expires_at && $coupon->expires_at->isPast()) {
                    throw new \RuntimeException('This coupon has expired.');
                }
                if ($coupon->max_uses !== null && $coupon->times_used >= $coupon->max_uses) {
                    throw new \RuntimeException('This coupon has reached its usage limit.');
                }
                if ($subtotal < (float) $coupon->min_order_amount) {
                    throw new \RuntimeException(
                        "This coupon requires a minimum order of {$coupon->min_order_amount}."
                    );
                }

                $discountAmount = $coupon->discount_type === 'percentage'
                    ? $subtotal * ((float) $coupon->discount_value / 100)
                    : (float) $coupon->discount_value;

                $discountAmount = min($discountAmount, $subtotal);
            }

            $shippingAmount = 0.0;
            $taxAmount = 0.0;
            $totalPrice = $subtotal - $discountAmount + $shippingAmount + $taxAmount;

            $order = Order::create([
                'order_number' => $this->generateOrderNumber(),
                'user_id' => $user->id,
                'coupon_id' => $coupon?->id,
                'shipping_address_id' => $shippingAddress->id,
                'billing_address_id' => ($billingAddress ?? $shippingAddress)->id,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'shipping_amount' => $shippingAmount,
                'tax_amount' => $taxAmount,
                'total_price' => $totalPrice,
                'currency' => 'USD',
                'status' => 'pending',
            ]);

            foreach ($orderItemsData as $itemData) {
                $order->items()->create($itemData);
            }

            if ($coupon) {
                $coupon->increment('times_used');
            }

            return $order->load('items');
        });
    }

    /**
     * Creates an order from a flash sale purchase using a lock-and-verify pattern.
     * Bypasses standard inventory in favor of FlashSaleItem's reservation system.
     */
    public function createFromFlashSalePurchase(
        User $user,
        FlashSaleItem $flashSaleItem,
        int $quantity,
        int $shippingAddressId,
        ?int $billingAddressId = null
    ): Order {
        return DB::transaction(function () use ($user, $flashSaleItem, $quantity, $shippingAddressId, $billingAddressId) {
            if ($quantity < 1) {
                throw new \RuntimeException('Item quantity must be at least 1.');
            }

            $shippingAddress = Address::where('id', $shippingAddressId)
                ->where('user_id', $user->id)
                ->first();

            if (!$shippingAddress) {
                throw new \RuntimeException('Invalid shipping address.');
            }

            $billingAddress = null;
            if ($billingAddressId !== null) {
                $billingAddress = Address::where('id', $billingAddressId)
                    ->where('user_id', $user->id)
                    ->first();

                if (!$billingAddress) {
                    throw new \RuntimeException('Invalid billing address.');
                }
            }

            // Lock and Verify: Attempt to reserve stock via the FlashSaleItem
            if (!$flashSaleItem->tryReserve($quantity)) {
                throw new InsufficientStockException(
                    "Requested quantity for this flash sale item is no longer available."
                );
            }

            $unitPrice = (float) $flashSaleItem->sale_price; // Assumes a price attribute exists on FlashSaleItem
            $subtotal = $unitPrice * $quantity;
            $shippingAmount = 0.0;
            $taxAmount = 0.0;
            $totalPrice = $subtotal + $shippingAmount + $taxAmount;

            $order = Order::create([
                'order_number' => $this->generateOrderNumber(),
                'user_id' => $user->id,
                'coupon_id' => null, // Flash sales generally do not accept coupons
                'shipping_address_id' => $shippingAddress->id,
                'billing_address_id' => ($billingAddress ?? $shippingAddress)->id,
                'subtotal' => $subtotal,
                'discount_amount' => 0.0,
                'shipping_amount' => $shippingAmount,
                'tax_amount' => $taxAmount,
                'total_price' => $totalPrice,
                'currency' => 'USD',
                'status' => 'pending',
            ]);

            $order->items()->create([
                'product_id' => $flashSaleItem->product_id,
                'product_name_snapshot' => $flashSaleItem->product->name ?? 'Flash Sale Item',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ]);

            return $order->load('items');
        });
    }

    /**
     * Converts the user's current cart into an order.[cite: 1]
     *
     * Thin wrapper around createFromItems(): resolves the cart's line
     * items, delegates the actual order creation, then empties the cart
     * on success.[cite: 1]
     *
     * @throws InsufficientStockException when stock can't cover the cart[cite: 1]
     * @throws \RuntimeException for any other checkout-blocking condition[cite: 1]
     *         (empty cart, bad address, bad/expired coupon, etc.)[cite: 1]
     */
    public function createFromCart(
        User $user,
        int $shippingAddressId,
        ?int $billingAddressId = null,
        ?string $couponCode = null,
    ): Order {
        return DB::transaction(function () use ($user, $shippingAddressId, $billingAddressId, $couponCode) {
            $cart = Cart::where('user_id', $user->id)
                ->with('items')
                ->lockForUpdate()
                ->first();

            if (!$cart || $cart->items->isEmpty()) {
                throw new \RuntimeException('Your cart is empty.');
            }

            $items = $cart->items->map(fn ($cartItem) => [
                'product_id' => $cartItem->product_id,
                'quantity' => $cartItem->quantity,
            ])->all();

            $order = $this->createFromItems(
                user: $user,
                items: $items,
                shippingAddressId: $shippingAddressId,
                billingAddressId: $billingAddressId,
                couponCode: $couponCode,
            );

            CartItem::where('cart_id', $cart->id)->delete();

            return $order;
        });
    }

    /**
     * Cancels a self-cancellable order and returns its reserved stock.[cite: 1]
     */
    public function cancel(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $order = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            foreach ($order->items()->lockForUpdate()->get() as $item) {
                $inventory = Inventory::where('product_id', $item->product_id)
                    ->lockForUpdate()
                    ->first();

                if ($inventory) {
                    DB::table('inventory')
                        ->where('id', $inventory->id)
                        ->update([
                            'quantity_available' => $inventory->quantity_available + $item->quantity,
                            'version' => $inventory->version + 1,
                            'updated_at' => now(),
                        ]);
                }
            }

            $order->update(['status' => 'cancelled']);

            return $order;
        });
    }

    private function generateOrderNumber(): string
    {
        return 'ORD-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
    }
}