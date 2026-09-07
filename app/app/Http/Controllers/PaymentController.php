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
     * Records a payment attempt for an order the authenticated user owns —
     * either the confirmation of a client-side gateway charge, or a mocked
     * success/failure in dev — and flips the order to `confirmed` on
     * success. Scoped to the current user the same way OrderController's
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
            $payment = $this->paymentService->confirm(
                order: $order,
                provider: $data['provider'],
                providerTransactionId: $data['provider_transaction_id'],
                amount: (float) $data['amount'],
                status: $data['status'] ?? 'succeeded',
                failureReason: $data['failure_reason'] ?? null,
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