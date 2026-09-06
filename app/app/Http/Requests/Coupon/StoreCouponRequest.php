<?php

namespace App\Http\Requests\Coupon;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCouponRequest extends FormRequest
{
    /**
     * Route should also sit behind an admin-only route middleware (see the
     * routes snippet) — this is a second, explicit check so the request
     * fails clearly even if the route grouping ever changes.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('coupons', 'code')],
            'description' => ['nullable', 'string', 'max:255'],
            'discount_type' => ['required', Rule::in(['percentage', 'fixed_amount'])],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The schema note is explicit that "discount_value > 100 when
     * discount_type = 'percentage'" is not enforced by the DB — enforce it
     * here instead.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (
                $this->input('discount_type') === 'percentage'
                && $this->filled('discount_value')
                && (float) $this->input('discount_value') > 100
            ) {
                $validator->errors()->add('discount_value', 'A percentage discount cannot exceed 100.');
            }
        });
    }
}