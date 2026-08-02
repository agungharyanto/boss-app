<?php

namespace App\Http\Requests;

use App\Enums\CustomerStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCustomerStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('customer'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::enum(CustomerStatus::class)],
        ];
    }

    /**
     * Configure the validator instance to also enforce the lifecycle
     * transition rules (RULE BOSS-006 keeps this next to input validation
     * since it needs the route-bound customer's current status).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('status')) {
                return;
            }

            $customer = $this->route('customer');
            $target = CustomerStatus::from($this->input('status'));

            if (! $customer->status->canTransitionTo($target)) {
                $validator->errors()->add(
                    'status',
                    "Tidak bisa mengubah status dari {$customer->status->label()} ke {$target->label()}."
                );
            }
        });
    }
}
