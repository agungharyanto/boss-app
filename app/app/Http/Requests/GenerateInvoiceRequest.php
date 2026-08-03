<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateInvoiceRequest extends FormRequest
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
            'subscription_id' => [
                'required',
                Rule::exists(Subscription::class, 'id')->where('tenant_id', $this->user()->tenant_id),
            ],
        ];
    }
}
