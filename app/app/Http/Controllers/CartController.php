<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientStockException;
use App\Http\Requests\Cart\AddCartItemRequest;
use App\Http\Requests\Cart\BuyNowRequest;
use App\Http\Requests\Cart\UpdateCartItemRequest;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    public function __construct(private readonly OrderService $orderService)
    {
    }

    /**
     * GET /cart
     */
    public function show(Request $request): JsonResponse
    {
        $cart = $this->currentCart($request, createIfMissing: false);

        if (!$cart) {
            return response()->json(['id' => null, 'items' => [], 'subtotal' => '0.00']);
        }

        $cart->load(['items.product.inventory']);

        return response()->json($this->formatCart($cart));
    }

    /**
     * POST /cart/items
     * Body: { product_id, quantity }
     */
    public function addItem(AddCartItemRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $cart = DB::transaction(function () use ($request, $data) {
                $cart = $this->currentCart($request, createIfMissing: true);
                $this->upsertCartItem($cart, (int) $data['product_id'], (int) $data['quantity']);

                return $cart;
            });
        } catch (InsufficientStockException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $cart->load(['items.product.inventory']);

        return response()->json($this->formatCart($cart), 201);
    }

    /**
     * PATCH /cart/items/{item}
     * Body: { quantity }
     */
    public function updateItem(UpdateCartItemRequest $request, int $item): JsonResponse
    {
        $cart = $this->currentCart($request, createIfMissing: false);

        if (!$cart) {
            return response()->json(['message' => 'Cart not found.'], 404);
        }

        $cartItem = CartItem::where('id', $item)
            ->where('cart_id', $cart->id)
            ->with('product.inventory')
            ->first();

        if (!$cartItem) {
            return response()->json(['message' => 'Cart item not found.'], 404);
        }

        $quantity = (int) $request->validated('quantity');
        $inventory = $cartItem->product->inventory;

        if ($inventory && $quantity > $inventory->quantity_available) {
            return response()->json([
                'message' => "Only {$inventory->quantity_available} unit(s) of this product are available.",
            ], 409);
        }

        $cartItem->update(['quantity' => $quantity]);

        $cart->load(['items.product.inventory']);

        return response()->json($this->formatCart($cart));
    }

    /**
     * DELETE /cart/items/{item}
     */
    public function removeItem(Request $request, int $item): JsonResponse
    {
        $cart = $this->currentCart($request, createIfMissing: false);

        if (!$cart) {
            return response()->json(['message' => 'Cart not found.'], 404);
        }

        $deleted = CartItem::where('id', $item)->where('cart_id', $cart->id)->delete();

        if (!$deleted) {
            return response()->json(['message' => 'Cart item not found.'], 404);
        }

        return response()->json(null, 204);
    }

    /**
     * DELETE /cart
     */
    public function clear(Request $request): JsonResponse
    {
        $cart = $this->currentCart($request, createIfMissing: false);

        $cart?->items()->delete();

        return response()->json(['message' => 'Cart cleared.']);
    }

   

    /**
     * Adds a product to a cart, merging quantity into any existing line for
     * that product — mirrors the uq_cart_items_cart_product constraint
     * (one row per product per cart). Snapshots the product's current
     * price on every add/merge.
     *
     * @throws InsufficientStockException
     * @throws \RuntimeException if the product isn't purchasable at all
     */
    private function upsertCartItem(Cart $cart, int $productId, int $quantity): CartItem
    {
        $product = Product::where('id', $productId)->where('is_active', true)->first();

        if (!$product) {
            throw new \RuntimeException('Product not found or unavailable.');
        }

        $inventory = $product->inventory;

        $existing = CartItem::where('cart_id', $cart->id)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        $newQuantity = $quantity + ($existing?->quantity ?? 0);

        if ($inventory && $newQuantity > $inventory->quantity_available) {
            throw new InsufficientStockException(
                "Only {$inventory->quantity_available} unit(s) of \"{$product->name}\" are available."
            );
        }

        if ($existing) {
            $existing->update([
                'quantity' => $newQuantity,
                'unit_price_snapshot' => $product->base_price,
            ]);

            return $existing;
        }

        return CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $productId,
            'quantity' => $quantity,
            'unit_price_snapshot' => $product->base_price,
        ]);
    }

    /**
     * Every route on this controller sits behind auth:api, so carts are
     * always resolved by user_id. (The schema also supports guest carts via
     * `guest_token` — wire that up behind a separate guest-session
     * middleware the same way if anonymous checkout is needed later.)
     */
    private function currentCart(Request $request, bool $createIfMissing): ?Cart
    {
        $userId = $request->user()->id;

        $cart = Cart::where('user_id', $userId)->first();

        if (!$cart && $createIfMissing) {
            $cart = Cart::create(['user_id' => $userId]);
        }

        return $cart;
    }

    private function formatCart(Cart $cart): array
    {
        $subtotal = $cart->items->sum(
            fn (CartItem $item) => $item->quantity * (float) $item->product->base_price
        );

        return [
            'id' => $cart->id,
            'items' => $cart->items,
            'subtotal' => number_format($subtotal, 2, '.', ''),
        ];
    }
}
