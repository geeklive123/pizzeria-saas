<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company.name' => ['required', 'string', 'max:255'],
            'company.legal_name' => ['nullable', 'string', 'max:255'],
            'company.tax_id' => ['nullable', 'string', 'max:255'],
            'company.phone' => ['nullable', 'string', 'max:255'],
            'company.email' => ['nullable', 'email', 'max:255'],
            'branch.name' => ['required', 'string', 'max:255'],
            'branch.address' => ['nullable', 'string', 'max:1000'],
            'branch.phone' => ['nullable', 'string', 'max:255'],
            'printers' => ['required', 'array:kitchen,customer_ticket'],
            'printers.kitchen.windows_printer_name' => ['required', 'string', 'max:255'],
            'printers.kitchen.is_active' => ['nullable', 'boolean'],
            'printers.kitchen.auto_print' => ['nullable', 'boolean'],
            'printers.kitchen.copies' => ['required', 'integer', 'between:1,5'],
            'printers.customer_ticket.windows_printer_name' => ['required', 'string', 'max:255'],
            'printers.customer_ticket.is_active' => ['nullable', 'boolean'],
            'printers.customer_ticket.copies' => ['required', 'integer', 'between:1,5'],
        ];
    }
}
