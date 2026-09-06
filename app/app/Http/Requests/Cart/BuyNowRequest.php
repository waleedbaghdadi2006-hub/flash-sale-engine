<?php

namespace App\Http\Requests\Cart;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Buy Now" needs everything a normal add-to-cart needs (product_id,
 * quantity) PLUS everything a normal checkout needs (addresses, coupon),
 * because under the hood it silently does both steps back to back.
 */
class BuyNowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],

            'shipping_address_id' => [
                'required',
                'integer',
                Rule::exists('addresses', 'id')->where('user_id', $userId),
            ],
            'billing_address_id' => [
                'nullable',
                'integer',
                Rule::exists('addresses', 'id')->where('user_id', $userId),
            ],
            'coupon_code' => ['nullable', 'string', 'max:50'],
        ];
    }
}
