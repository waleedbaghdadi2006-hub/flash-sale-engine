<?php

namespace App\Http\Requests\Coupon;

use App\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        $couponId = $this->route('id');

        return [
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('coupons', 'code')->ignore($couponId)],
            'description' => ['nullable', 'string', 'max:255'],
            'discount_type' => ['sometimes', 'required', Rule::in(['percentage', 'fixed_amount'])],
            'discount_value' => ['sometimes', 'required', 'numeric', 'min:0'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Resolve the effective discount_type/discount_value (falling back to
     * the existing row for whichever field wasn't sent) before checking the
     * percentage-cap rule, since PATCH requests may only send one of them.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $coupon = Coupon::find($this->route('id'));

            $type = $this->input('discount_type', $coupon->discount_type ?? null);
            $value = $this->input('discount_value', $coupon->discount_value ?? null);

            if ($type === 'percentage' && $value !== null && (float) $value > 100) {
                $validator->errors()->add('discount_value', 'A percentage discount cannot exceed 100.');
            }
        });
    }
}