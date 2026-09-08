<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives asynchronous payment confirmations from a real gateway (Stripe,
 * PayPal, etc.) and pushes them through the same PaymentService::confirm()
 * used by the synchronous, client-confirmed flow in PaymentController.
 *
 * This endpoint is unauthenticated (auth:api doesn't apply — the gateway
 * has no user session), so it must NOT be trusted on payload contents
 * alone. Before wiring in a real provider:
 *
 *   1. Verify the request signature (e.g. Stripe-Signature header +
 *      webhook signing secret) BEFORE touching the payload at all.
 *      verifySignature() below is a stub — replace it with the gateway
 *      SDK's real verification call.
 *   2. Map the gateway's event/payload shape to the
 *      order_id/provider/provider_transaction_id/amount/status fields
 *      PaymentService expects — the mapping below assumes a generic
 *      normalized payload; a real provider's payload will look different.
 *   3. Make the handler idempotent against redelivery (most gateways
 *      retry webhooks). PaymentService::confirm() already rejects a
 *      duplicate provider_transaction_id at the DB layer (unique
 *      constraint) — catch that here and return 200 anyway, since from
 *      the gateway's point of view a duplicate delivery isn't a failure.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(private readonly PaymentService $paymentService)
    {
    }

    /**
     * POST /webhooks/payments/{provider}
     */
    public function handle(Request $request, string $provider): JsonResponse
    {
        if (!$this->verifySignature($request, $provider)) {
            Log::warning('Rejected payment webhook with invalid signature.', ['provider' => $provider]);

            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $payload = $request->validate([
            'order_id' => ['required', 'integer'],
            'provider_transaction_id' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'status' => ['required', 'in:succeeded,failed'],
            'failure_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $order = Order::find($payload['order_id']);

        if (!$order) {
            // Ack with 200 anyway — a 4xx/5xx here just causes the gateway
            // to keep retrying a webhook that will never resolve.
            Log::warning('Payment webhook for unknown order.', $payload);

            return response()->json(['message' => 'Order not found, acknowledged.']);
        }

        try {
            $this->paymentService->confirmVerified(
                order: $order,
                provider: $provider,
                providerTransactionId: $payload['provider_transaction_id'],
                amount: (string) $payload['amount'],
                status: $payload['status'],
                failureReason: $payload['failure_reason'] ?? null,
            );
        } catch (\RuntimeException $e) {
            // Already-processed transactions, stale order status, or an
            // amount mismatch land here. Log for investigation but still
            // ack with 200 so the gateway stops retrying — none of these
            // are fixed by a redelivery.
            Log::warning('Payment webhook could not be applied.', [
                'order_id' => $order->id,
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['message' => 'Webhook processed.']);
    }

    /**
     * Local/test guard only. Replace this with the provider SDK's real
     * signature verification before enabling webhooks in production.
     */
    private function verifySignature(Request $request, string $provider): bool
    {
        // Local/test only: this endpoint remains disabled until a real
        // provider signature check is installed before deployment.
        return app()->environment(['local', 'testing']);
    }
}