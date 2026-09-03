<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Adjust this if you have policies/gates for product updates
     * (e.g. return $this->user()->can('update', $this->route('product')); ).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Uses the {id} route parameter to ignore the current product
     * when checking slug/sku uniqueness.
     */
    public function rules(): array
    {
        $productId = $this->route('id');

        return [
            'category_id' => ['nullable', 'exists:categories,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes', 'string', 'max:280',
                Rule::unique('products', 'slug')->ignore($productId),
            ],
            'description' => ['nullable', 'string'],
            'base_price' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'sku' => [
                'sometimes', 'string', 'max:100',
                Rule::unique('products', 'sku')->ignore($productId),
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Get custom messages for validator errors (optional — add as needed).
     */
    public function messages(): array
    {
        return [];
    }
}
