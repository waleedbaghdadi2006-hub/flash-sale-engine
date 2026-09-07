<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Records a payment attempt against an order (either the result of a
 * client-side gateway confirmation, or a mocked payment in dev) and lets
 * PaymentService decide how it moves the order's status.
 */
class ConfirmPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is gated by auth:api; PaymentController re-checks that the
        // order belongs to the authenticated user before this ever runs.
        return true;
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'max:50'],
            'provider_transaction_id' => [
                'required',
                'string',
                'max:255',
                // payments.provider_transaction_id is globally unique in the
                // schema, so a re-submitted/duplicate token must be rejected
                // here rather than surfacing as a raw DB error.
                Rule::unique('payments', 'provider_transaction_id'),
            ],
            'amount' => ['required', 'numeric', 'min:0'],
            'status' => ['sometimes', Rule::in(['succeeded', 'failed'])],
            'failure_reason' => ['nullable', 'string', 'max:255', 'required_if:status,failed'],
        ];
    }
}