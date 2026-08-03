<?php

namespace App\Http\Requests;

use App\Models\Reseller;
use App\Models\Technician;
use App\Models\User;
use App\Support\ResellerContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTechnicianRequest extends FormRequest
{
    public function authorize(): bool
    {
        $hasContext = app(ResellerContext::class)->hasReseller();
        $reseller = $hasContext ? app(ResellerContext::class)->reseller() : $this->resellerFromInput();

        return $this->user()->can('create', [Technician::class, $reseller]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $hasContext = app(ResellerContext::class)->hasReseller();
        $tenantId = $this->user()->tenant_id;

        return [
            'user_id' => [
                'required',
                Rule::exists(User::class, 'id')->where('tenant_id', $tenantId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'reseller_id' => [
                'nullable',
                Rule::exists(Reseller::class, 'id')->where('tenant_id', $tenantId),
                Rule::prohibitedIf($hasContext),
            ],
        ];
    }

    private function resellerFromInput(): ?Reseller
    {
        $resellerId = $this->input('reseller_id');

        return $resellerId ? Reseller::find($resellerId) : null;
    }
}
