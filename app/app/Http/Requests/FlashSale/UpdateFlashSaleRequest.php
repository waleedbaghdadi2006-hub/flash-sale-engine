<?php

namespace App\Http\Requests\FlashSale;

use App\Models\FlashSale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFlashSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin' || $this->user()?->role === 'staff';
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after:starts_at'],
            'status' => ['sometimes', Rule::in([
                FlashSale::STATUS_PENDING,
                FlashSale::STATUS_ACTIVE,
                FlashSale::STATUS_ENDED,
                FlashSale::STATUS_CANCELLED,
            ])],
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /** @var FlashSale $flashSale */
            $flashSale = $this->route('flash_sale') ?? $this->route('flashSale');

            if (! $flashSale) {
                return;
            }

            // Once a sale is active or has ended, its timing shouldn't move —
            // that would retroactively change what customers already bought under.
            if ($flashSale->status !== FlashSale::STATUS_PENDING
                && ($this->filled('starts_at') || $this->filled('ends_at'))) {
                $validator->errors()->add(
                    'starts_at',
                    'The sale window can only be changed while the sale is still pending.'
                );
            }
        });
    }
}
