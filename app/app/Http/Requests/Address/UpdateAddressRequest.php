<?php

namespace App\Http\Requests\Address;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership is enforced in AddressController::update() (the query
        // is scoped to $request->user()->id before this request ever
        // resolves the model), so a plain `true` here is safe.
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'nullable', 'string', 'max:50'],
            'recipient_name' => ['sometimes', 'required', 'string', 'max:200'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'line1' => ['sometimes', 'required', 'string', 'max:255'],
            'line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'required', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'required', 'string', 'max:20'],
            'country' => ['sometimes', 'required', 'string', 'size:2'],
            'is_default_shipping' => ['sometimes', 'boolean'],
            'is_default_billing' => ['sometimes', 'boolean'],
        ];
    }
}