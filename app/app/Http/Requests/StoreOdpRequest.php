<?php

namespace App\Http\Requests;

use App\Models\Odp;
use App\Models\Reseller;
use App\Support\ResellerContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOdpRequest extends FormRequest
{
    public function authorize(): bool
    {
        $hasContext = app(ResellerContext::class)->hasReseller();
        $reseller = $hasContext ? app(ResellerContext::class)->reseller() : $this->resellerFromInput();

        return $this->user()->can('create', [Odp::class, $reseller]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $hasContext = app(ResellerContext::class)->hasReseller();
        $tenantId = $this->user()->tenant_id;

        return [
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('odps', 'code')->where('tenant_id', $tenantId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'total_ports' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
            // Only used when the caller has no resolved reseller context (an
            // ISP admin creating on a reseller's behalf, or a direct/
            // no-reseller ODP when left blank) — same pattern as
            // StoreResellerPackagePricingRequest.
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
