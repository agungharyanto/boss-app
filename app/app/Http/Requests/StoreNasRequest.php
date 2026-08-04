<?php

namespace App\Http\Requests;

use App\Models\Nas;
use App\Models\Reseller;
use App\Support\ResellerContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNasRequest extends FormRequest
{
    public function authorize(): bool
    {
        $hasContext = app(ResellerContext::class)->hasReseller();
        $reseller = $hasContext ? app(ResellerContext::class)->reseller() : $this->resellerFromInput();

        return $this->user()->can('create', [Nas::class, $reseller]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $hasContext = app(ResellerContext::class)->hasReseller();
        $tenantId = $this->user()->tenant_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            // mikrotik_ip sengaja TIDAK bisa diisi manual di v0.6.1 — baru
            // terisi otomatis lewat VPN provisioning (v0.6.2).
            'api_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'api_username' => ['nullable', 'string', 'max:255'],
            'api_password' => ['nullable', 'string', 'max:255'],
            'radius_secret' => ['required', 'string', 'max:255'],
            'coa_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'timezone' => ['nullable', 'string', 'max:64'],
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
