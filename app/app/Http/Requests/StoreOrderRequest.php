<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
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
