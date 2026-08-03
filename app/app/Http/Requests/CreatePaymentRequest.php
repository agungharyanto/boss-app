<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Invoice::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // v0.3.5 Fase H: dynamic catalog (payment_gateway_channels),
            // not a fixed `in:` list of 3 values anymore. Only checks the
            // code EXISTS — whether it's actually enabled is checked in
            // PaymentService::createPaymentFor() (a disabled-but-existing
            // channel needs a clearer domain error than a bare 422).
            'channel_type' => ['required', 'string', Rule::exists('payment_gateway_channels', 'code')],
        ];
    }
}
