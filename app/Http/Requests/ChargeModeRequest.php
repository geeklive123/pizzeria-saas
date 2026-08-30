<?php

namespace App\Http\Requests;

use App\Enums\TableChargeMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChargeModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['table_charge_mode' => ['required', Rule::enum(TableChargeMode::class)]];
    }
}
