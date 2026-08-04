<?php

namespace App\Http\Requests;

use App\Enums\VpnProtocol;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ProvisionVpnAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('nas'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'protocol' => ['sometimes', new Enum(VpnProtocol::class)],
        ];
    }

    public function protocol(): VpnProtocol
    {
        return $this->has('protocol')
            ? VpnProtocol::from($this->string('protocol')->toString())
            : VpnProtocol::OpenVpn;
    }
}
