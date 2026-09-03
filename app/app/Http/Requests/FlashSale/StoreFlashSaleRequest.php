<?php

namespace App\Http\Requests\FlashSale;

use App\Models\FlashSale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFlashSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Restrict to staff/admin. Adjust to your policy/gate of choice.
        return $this->user()?->role === 'admin' || $this->user()?->role === 'staff';
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'status' => ['sometimes', Rule::in([
                FlashSale::STATUS_PENDING,
                FlashSale::STATUS_ACTIVE,
                FlashSale::STATUS_ENDED,
                FlashSale::STATUS_CANCELLED,
            ])],

            // Optional: seed the sale with items in the same request.
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.product_id' => ['required_with:items', 'integer', 'exists:products,id'],
            'items.*.sale_price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.quantity_limit' => ['required_with:items', 'integer', 'min:1'],
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $items = $this->input('items', []);
            $productIds = array_column($items, 'product_id');

            if (count($productIds) !== count(array_unique($productIds))) {
                $validator->errors()->add('items', 'Each product may only appear once per flash sale.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'ends_at.after' => 'The sale end time must be after the start time.',
        ];
    }
}
