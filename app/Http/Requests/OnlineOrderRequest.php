<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class OnlineOrderRequest extends StorefrontCheckoutRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['fulfillment'] = ['required', Rule::in(['pickup'])];
        $rules['idempotency_key'] = ['required', 'uuid:4'];
        unset($rules['address'], $rules['reference']);

        return $rules;
    }
}
