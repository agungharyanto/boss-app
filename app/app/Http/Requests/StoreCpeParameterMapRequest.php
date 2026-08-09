<?php

namespace App\Http\Requests;

use App\Enums\CpeParameterConversionFormula;
use App\Models\CpeParameterMap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreCpeParameterMapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', CpeParameterMap::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'oui' => ['required', 'string', 'max:32'],
            'product_class' => ['required', 'string', 'max:255'],
            'parameter_key' => [
                'required',
                'string',
                'max:255',
                Rule::unique(CpeParameterMap::class)->where(
                    fn ($query) => $query->where('oui', $this->input('oui'))
                        ->where('product_class', $this->input('product_class'))
                ),
            ],
            'parameter_path' => ['required', 'string', 'max:1000'],
            'value_type' => ['nullable', 'string', 'max:64'],
            'conversion_formula' => ['required', new Enum(CpeParameterConversionFormula::class)],
            'conversion_params' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
