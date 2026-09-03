<?php

namespace App\Http\Requests\FlashSale;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseFlashSaleItemRequest extends FormRequest
{
    /** A reasonable per-order cap to keep the sale fair. Adjust as needed. */
    public const MAX_QUANTITY_PER_PURCHASE = 5;

    public function authorize(): bool
    {
        // Any authenticated customer may attempt a purchase.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $flashSale = $this->route('flash_sale') ?? $this->route('flashSale');

        return [
            'product_id' => [
                'required',
                'integer',
                Rule::exists('flash_sale_items', 'product_id')
                    ->where('flash_sale_id', $flashSale?->id),
            ],
            'quantity' => ['required', 'integer', 'min:1', 'max:' . self::MAX_QUANTITY_PER_PURCHASE],
            'shipping_address_id' => ['required', 'integer', 'exists:addresses,id'],
            'billing_address_id' => ['nullable', 'integer', 'exists:addresses,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.exists' => 'This product is not part of the flash sale.',
            'quantity.max' => 'You may purchase at most ' . self::MAX_QUANTITY_PER_PURCHASE . ' units of this item per order.',
        ];
    }
}
