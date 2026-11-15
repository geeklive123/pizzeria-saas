<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class OnlineOrderRequest extends StorefrontCheckoutRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['fulfillment'] = ['required', Rule::in(['pickup', 'delivery'])];
        $rules['idempotency_key'] = ['required', 'uuid:4'];
        $rules['latitude'] = ['required_if:fulfillment,delivery', 'nullable', 'numeric', 'between:-90,90'];
        $rules['longitude'] = ['required_if:fulfillment,delivery', 'nullable', 'numeric', 'between:-180,180'];
        $rules['location_confirmed'] = ['required_if:fulfillment,delivery', 'accepted_if:fulfillment,delivery'];
        $rules['reference'] = ['required_if:fulfillment,delivery', 'nullable', 'string', 'max:500'];
        foreach (['customer_name', 'address', 'reference', 'notes'] as $field) {
            $rules[$field][] = 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';
        }

        return $rules;
    }
}
