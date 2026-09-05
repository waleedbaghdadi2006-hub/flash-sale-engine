<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientStockException;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orderService)
    {
    }

    /**
     * GET /orders
     *
     * The authenticated user's own order history.
     */
    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->with('items')
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json($orders);
    }

    /**
     * GET /orders/{id}
     *
     * Scoped to the authenticated user — one customer can't view another's order.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $order = Order::query()
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->with(['items', 'payments', 'shippingAddress', 'billingAddress'])
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json($order);
    }

    /**
     * POST /orders
     *
     * Standard (non-flash-sale) checkout: turns the current user's cart
     * into an order. Flash-sale purchases go through
     * FlashSaleController::purchase() instead, which reserves stock via
     * the same OrderService but under the queued job.
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        try {
            $order = $this->orderService->createFromCart(
                user: $request->user(),
                shippingAddressId: (int) $request->validated('shipping_address_id'),
                billingAddressId: $request->validated('billing_address_id') !== null
                    ? (int) $request->validated('billing_address_id')
                    : null,
                couponCode: $request->validated('coupon_code'),
            );
        } catch (InsufficientStockException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($order, 201);
    }

    /**
     * POST /orders/{id}/cancel
     *
     * Only orders that haven't shipped yet can be self-service cancelled.
     * Releases reserved stock back to inventory.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $order = Order::query()
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if (!in_array($order->status, ['pending', 'confirmed'], true)) {
            return response()->json([
                'message' => "Orders with status '{$order->status}' can no longer be cancelled.",
            ], 409);
        }

        $order = $this->orderService->cancel($order);

        return response()->json($order->fresh(['items']));
    }
}
