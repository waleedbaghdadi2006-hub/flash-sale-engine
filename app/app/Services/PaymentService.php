<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    /**
     * Development-only payment simulation. The server derives the amount
     * from the order and generates the transaction id/result itself.
     */
    public function confirmLocal(Order $order, string $provider = 'local_mock'): Payment
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new \RuntimeException(
                'Local payments are disabled outside the local/test environment. Configure a real payment provider before deployment.'
            );
        }

        return $this->recordVerifiedPayment(
            order: $order,
            provider: $provider,
            providerTransactionId: 'local_' . Str::uuid(),
            amount: (string) $order->total_price,
            status: 'succeeded',
        );
    }

    /**
     * Applies a payment result that has already been verified by a trusted
     * payment-provider integration (e.g. a signed webhook).
     */
    public function confirmVerified(
        Order $order,
        string $provider,
        string $providerTransactionId,
        string $amount,
        string $status = 'succeeded',
        ?string $failureReason = null,
    ): Payment {
        return $this->recordVerifiedPayment(
            order: $order,
            provider: $provider,
            providerTransactionId: $providerTransactionId,
            amount: $amount,
            status: $status,
            failureReason: $failureReason,
        );
    }

    private function recordVerifiedPayment(
        Order $order,
        string $provider,
        string $providerTransactionId,
        string $amount,
        string $status,
        ?string $failureReason = null,
    ): Payment {
        return DB::transaction(function () use ($order, $provider, $providerTransactionId, $amount, $status, $failureReason) {
            $order = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== 'pending') {
                throw new \RuntimeException(
                    "Order is '{$order->status}' and can no longer accept a payment."
                );
            }

            if (!in_array($status, ['succeeded', 'failed'], true)) {
                throw new \RuntimeException('Unsupported payment status.');
            }

            if ($status === 'succeeded' && $this->moneyToCents($amount) !== $this->moneyToCents((string) $order->total_price)) {
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

            if ($status === 'succeeded') {
                $order->update(['status' => 'confirmed']);
            }

            return $payment;
        });
    }

    private function moneyToCents(string $amount): int
    {
        $amount = trim($amount);
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw new \RuntimeException('Invalid monetary amount.');
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }
}
