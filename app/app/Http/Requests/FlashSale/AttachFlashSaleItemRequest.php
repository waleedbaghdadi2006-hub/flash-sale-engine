<?php

namespace App\Http\Requests\FlashSale;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachFlashSaleItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin' || $this->user()?->role === 'staff';
    }

    public function rules(): array
    {
        $flashSale = $this->route('flash_sale') ?? $this->route('flashSale');

        return [
            'product_id' => [
                'required',
                'integer',
                'exists:products,id',
                Rule::unique('flash_sale_items', 'product_id')
                    ->where('flash_sale_id', $flashSale?->id),
            ],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'quantity_limit' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.unique' => 'This product is already part of the flash sale.',
        ];
    }
}
