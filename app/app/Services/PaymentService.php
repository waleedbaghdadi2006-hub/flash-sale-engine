<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Wraps the payment-gateway confirmation step: writes the Payment row and
 * moves the Order out of `pending` inside a single locked transaction,
 * mirroring the lockForUpdate() style already used in OrderService.
 *
 * In dev/test (or until a real gateway is wired in), the "gateway call" is
 * just trusting the provider/provider_transaction_id/status the client (or
 * PaymentController) already confirmed — see confirm()'s $status param.
 *
 * TODO (production): re-verify the charge server-side against the real
 * gateway (e.g. a Stripe PaymentIntent lookup) instead of trusting the
 * client-supplied status/amount, and consider adding an async
 * PaymentWebhookController for gateways that confirm out-of-band.
 */
class PaymentService
{
    /**
     * @throws \RuntimeException if the order can't currently accept a payment
     *         (already confirmed/cancelled/etc.), or the amount doesn't
     *         match the order total.
     */
    public function confirm(
        Order $order,
        string $provider,
        string $providerTransactionId,
        float $amount,
        string $status = 'succeeded',
        ?string $failureReason = null,
    ): Payment {
        return DB::transaction(function () use ($order, $provider, $providerTransactionId, $amount, $status, $failureReason) {
            $order = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== 'pending') {
                throw new \RuntimeException(
                    "Order is '{$order->status}' and can no longer accept a payment."
                );
            }

            if ($status === 'succeeded' && abs($amount - (float) $order->total_price) > 0.005) {
                throw new \RuntimeException(
                    "Payment amount ({$amount}) does not match the order total ({$order->total_price})."
                );
            }

            $payment = Payment::create([
                'order_id' => $order->id,
                'provider' => $provider,
                'provider_transaction_id' => $providerTransactionId,
                'amount' => $amount,
                'currency' => $order->currency,
                'status' => $status,
                'failure_reason' => $status === 'failed' ? $failureReason : null,
            ]);

            // orders.status is an ENUM of pending/confirmed/shipped/delivered/
            // cancelled/refunded — there's no "payment_failed" order status,
            // so a failed attempt just leaves the order `pending` (its
            // Payment row records the failure) and the user can retry.
            if ($status === 'succeeded') {
                $order->update(['status' => 'confirmed']);
            }

            return $payment;
        });
    }
}