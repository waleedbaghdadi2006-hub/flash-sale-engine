<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\FlashSaleItem;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Single application service for all customer purchase entry points.
 *
 * Regular and flash-sale purchases intentionally follow the same workflow:
 * validate checkout -> reserve stock -> price -> create order -> commit.
 * The only specialized step is stock reservation.
 */
class PurchaseService
{
    /** @param array<int, array{product_id:int, quantity:int}> $items */
    public function purchaseRegular(
        User $user,
        array $items,
        int $shippingAddressId,
        ?int $billingAddressId = null,
        ?string $couponCode = null,
    ): Order {
        return DB::transaction(function () use ($user, $items, $shippingAddressId, $billingAddressId, $couponCode) {
            $checkout = $this->validateCheckoutAddresses($user, $shippingAddressId, $billingAddressId);

            $lines = array_map(
                static fn (array $item): array => [
                    'type' => 'regular',
                    'product_id' => (int) $item['product_id'],
                    'quantity' => (int) $item['quantity'],
                ],
                $items,
            );

            return $this->createOrderFromLines(
                user: $user,
                lines: $lines,
                shippingAddress: $checkout['shipping'],
                billingAddress: $checkout['billing'],
                couponCode: $couponCode,
            );
        });
    }

    public function purchaseFromCart(
        User $user,
        int $shippingAddressId,
        ?int $billingAddressId = null,
        ?string $couponCode = null,
    ): Order {
        return DB::transaction(function () use ($user, $shippingAddressId, $billingAddressId, $couponCode) {
            $cart = Cart::query()
                ->where('user_id', $user->id)
                ->with('items')
                ->lockForUpdate()
                ->first();

            if (!$cart || $cart->items->isEmpty()) {
                throw new \RuntimeException('Your cart is empty.');
            }

            $items = $cart->items->map(static fn (CartItem $item): array => [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
            ])->all();

            $order = $this->purchaseRegular(
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

    public function purchaseFlashSale(
        User $user,
        FlashSaleItem $flashSaleItem,
        int $quantity,
        int $shippingAddressId,
        ?int $billingAddressId = null,
    ): Order {
        return DB::transaction(function () use ($user, $flashSaleItem, $quantity, $shippingAddressId, $billingAddressId) {
            $checkout = $this->validateCheckoutAddresses($user, $shippingAddressId, $billingAddressId);

            return $this->createOrderFromLines(
                user: $user,
                lines: [[
                    'type' => 'flash_sale',
                    'flash_sale_item_id' => $flashSaleItem->id,
                    'quantity' => $quantity,
                ]],
                shippingAddress: $checkout['shipping'],
                billingAddress: $checkout['billing'],
                couponCode: null,
            );
        });
    }

    /**
     * Shared purchase pipeline. Regular and flash-sale lines differ only at
     * reservation time; the order, pricing, coupon, and payment boundary are
     * otherwise identical.
     *
     * @param array<int, array<string, int|string>> $lines
     */
    private function createOrderFromLines(
        User $user,
        array $lines,
        Address $shippingAddress,
        ?Address $billingAddress,
        ?string $couponCode,
    ): Order {
        if (empty($lines)) {
            throw new \RuntimeException('No items to purchase.');
        }

        $subtotalCents = 0;
        $orderItemsData = [];
        $flashSaleIds = [];

        foreach ($lines as $line) {
            $quantity = (int) $line['quantity'];
            if ($quantity < 1) {
                throw new \RuntimeException('Item quantity must be at least 1.');
            }

            $reserved = $line['type'] === 'flash_sale'
                ? $this->reserveFlashSaleLine((int) $line['flash_sale_item_id'], $quantity)
                : $this->reserveRegularLine((int) $line['product_id'], $quantity);

            if ($reserved['flash_sale_id'] !== null) {
                $flashSaleIds[$reserved['flash_sale_id']] = true;
            }

            $unitPriceCents = $reserved['unit_price_cents'];
            $subtotalCents += $unitPriceCents * $quantity;

            $orderItemsData[] = [
                'product_id' => $reserved['product_id'],
                'product_name_snapshot' => $reserved['product_name'],
                'quantity' => $quantity,
                'unit_price' => $this->centsToMoney($unitPriceCents),
            ];
        }

        if (count($flashSaleIds) > 1) {
            throw new \RuntimeException('An order cannot combine items from different flash sales.');
        }

        $flashSaleId = array_key_first($flashSaleIds);
        [$coupon, $discountCents] = $this->calculateCouponDiscount(
            couponCode: $couponCode,
            subtotalCents: $subtotalCents,
            allowCoupon: $flashSaleId === null,
        );

        $shippingCents = 0;
        $taxCents = 0;
        $totalCents = $subtotalCents - $discountCents + $shippingCents + $taxCents;

        $order = Order::create([
            'order_number' => $this->generateOrderNumber(),
            'user_id' => $user->id,
            'flash_sale_id' => $flashSaleId,
            'coupon_id' => $coupon?->id,
            'shipping_address_id' => $shippingAddress->id,
            'billing_address_id' => ($billingAddress ?? $shippingAddress)->id,
            'subtotal' => $this->centsToMoney($subtotalCents),
            'discount_amount' => $this->centsToMoney($discountCents),
            'shipping_amount' => $this->centsToMoney($shippingCents),
            'tax_amount' => $this->centsToMoney($taxCents),
            'total_price' => $this->centsToMoney($totalCents),
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
    }

    /** @return array{shipping:Address, billing:?Address} */
    private function validateCheckoutAddresses(
        User $user,
        int $shippingAddressId,
        ?int $billingAddressId,
    ): array {
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

        return ['shipping' => $shippingAddress, 'billing' => $billingAddress];
    }

    /** @return array{product_id:int, product_name:string, unit_price_cents:int, flash_sale_id:null} */
    private function reserveRegularLine(int $productId, int $quantity): array
    {
        $product = Product::query()
            ->whereKey($productId)
            ->lockForUpdate()
            ->first();

        if (!$product || !$product->is_active || $product->trashed()) {
            throw new InsufficientStockException(
                "A selected product (ID {$productId}) is no longer available."
            );
        }

        $inventory = Inventory::query()
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        $available = $inventory?->quantity_available ?? 0;
        if (!$inventory || $available < $quantity) {
            throw new InsufficientStockException(
                "Insufficient stock for \"{$product->name}\" (only {$available} available)."
            );
        }

        if (!$inventory->tryReserve($quantity)) {
            throw new InsufficientStockException(
                "Stock for \"{$product->name}\" changed while checking out — please try again."
            );
        }

        return [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_price_cents' => $this->moneyToCents((string) $product->base_price),
            'flash_sale_id' => null,
        ];
    }

    /** @return array{product_id:int, product_name:string, unit_price_cents:int, flash_sale_id:int} */
    private function reserveFlashSaleLine(int $flashSaleItemId, int $quantity): array
    {
        $flashSaleItem = FlashSaleItem::query()
            ->with(['product', 'flashSale'])
            ->whereKey($flashSaleItemId)
            ->lockForUpdate()
            ->first();

        if (!$flashSaleItem || !$flashSaleItem->flashSale || !$flashSaleItem->flashSale->isCurrentlyActive()) {
            throw new \RuntimeException('This flash sale is no longer active.');
        }

        $product = $flashSaleItem->product;
        if (!$product || !$product->is_active || $product->trashed()) {
            throw new InsufficientStockException('This flash-sale product is no longer available.');
        }

        if ($flashSaleItem->remainingStock() < $quantity) {
            throw new InsufficientStockException(
                'Requested quantity for this flash sale item is no longer available.'
            );
        }

        // The flash-sale item row is locked while the common purchase pipeline
        // runs, so concurrent queue workers see the latest quantity.
        if (!$flashSaleItem->tryReserve($quantity)) {
            throw new InsufficientStockException(
                'This flash-sale item was just purchased by another customer. Please try again.'
            );
        }

        return [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_price_cents' => $this->moneyToCents((string) $flashSaleItem->sale_price),
            'flash_sale_id' => $flashSaleItem->flash_sale_id,
        ];
    }

    /** @return array{0:?Coupon, 1:int} */
    private function calculateCouponDiscount(
        ?string $couponCode,
        int $subtotalCents,
        bool $allowCoupon,
    ): array {
        if ($couponCode === null || $couponCode === '') {
            return [null, 0];
        }

        if (!$allowCoupon) {
            throw new \RuntimeException('Coupons cannot be applied to flash-sale purchases.');
        }

        $coupon = Coupon::query()
            ->where('code', $couponCode)
            ->where('is_active', true)
            ->lockForUpdate()
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

        $minOrderCents = $this->moneyToCents((string) $coupon->min_order_amount);
        if ($subtotalCents < $minOrderCents) {
            throw new \RuntimeException(
                "This coupon requires a minimum order of {$coupon->min_order_amount}."
            );
        }

        if ($coupon->discount_type === 'percentage') {
            $percentageBasisPoints = $this->moneyToCents((string) $coupon->discount_value);
            $discountCents = intdiv(($subtotalCents * $percentageBasisPoints) + 5000, 10000);
        } else {
            $discountCents = $this->moneyToCents((string) $coupon->discount_value);
        }

        return [$coupon, min($discountCents, $subtotalCents)];
    }

    private function moneyToCents(string $amount): int
    {
        $amount = trim($amount);
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw new \RuntimeException('Invalid monetary amount.');
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function centsToMoney(int $cents): string
    {
        return number_format(max(0, $cents) / 100, 2, '.', '');
    }

    private function generateOrderNumber(): string
    {
        return 'ORD-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
    }
}
