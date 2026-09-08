<?php

namespace App\Http\Controllers;

use App\Http\Requests\Payment\ConfirmPaymentRequest;
use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $paymentService)
    {
    }

    /**
     * POST /orders/{order}/payments
     *
     * Records a local development payment simulation for an order the
     * authenticated user owns. The server derives the amount and transaction
     * id; no client-supplied payment result is trusted. Replace this endpoint
     * with a real provider checkout + signed webhook before deployment.
     * Scoped to the current user the same way OrderController's
     * show()/cancel() are, so one customer can't pay for another's order.
     */
    public function store(ConfirmPaymentRequest $request, int $order): JsonResponse
    {
        $order = Order::where('id', $order)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $data = $request->validated();

        try {
            $payment = $this->paymentService->confirmLocal(
                order: $order,
                provider: $data['provider'] ?? 'local_mock',
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($payment, 201);
    }

    /**
     * GET /orders/{order}/payments
     *
     * Payment history for an order the authenticated user owns.
     */
    public function index(Request $request, int $order): JsonResponse
    {
        $order = Order::where('id', $order)
            ->where('user_id', $request->user()->id)
            ->with('payments')
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json($order->payments);
    }
}