<?php

namespace App\Http\Requests;

use App\Models\Subscription;
use App\Models\WorkOrder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subscription = Subscription::withoutGlobalScopes()->find($this->input('subscription_id'));

        if ($subscription === null) {
            // Let validation (exists rule below) produce the real 422 —
            // authorize() only needs to not silently allow a bogus id.
            return $this->user()->can('viewAny', WorkOrder::class);
        }

        // Passing WorkOrder::class first resolves WorkOrderPolicy (not
        // SubscriptionPolicy, which is what passing $subscription alone
        // would resolve) — $subscription is the extra arg to ::create().
        return $this->user()->can('create', [WorkOrder::class, $subscription]);
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
