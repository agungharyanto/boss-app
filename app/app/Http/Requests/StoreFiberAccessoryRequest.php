<?php

namespace App\Http\Requests;

use App\Models\FiberCable;
use App\Models\Splitter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * v0.16.0 — Core Network Infrastructure Management, Langkah 2.
 * "fiber_cable_id XOR splitter_id" is a cross-field rule (not a DB
 * constraint, see FiberAccessory's own docblock) — enforced here via
 * Laravel's own prohibits/required_without_all rule combination rather
 * than a manual withValidator() check, since it's expressible cleanly as
 * declarative rules.
 */
class StoreFiberAccessoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('network_infrastructure.manage');
    }

    public function rules(): array
    {
        return [
            'fiber_cable_id' => [
                'nullable', 'integer', 'required_without:splitter_id', 'prohibits:splitter_id',
                Rule::exists(FiberCable::class, 'id'),
            ],
            'splitter_id' => [
                'nullable', 'integer', 'required_without:fiber_cable_id',
                Rule::exists(Splitter::class, 'id'),
            ],
            'accessory_type' => ['required', 'string', Rule::in(['pin_adaptor', 'connector', 'splice_fusion', 'splice_mechanical'])],
            'expected_loss_db' => ['nullable', 'numeric'],
            'measured_loss_db' => ['nullable', 'numeric'],
            'location_note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'fiber_cable_id.required_without' => 'Aksesori harus terhubung ke salah satu: kabel atau splitter.',
            'fiber_cable_id.prohibits' => 'Aksesori tidak boleh terhubung ke kabel DAN splitter sekaligus — pilih salah satu.',
            'splitter_id.required_without' => 'Aksesori harus terhubung ke salah satu: kabel atau splitter.',
        ];
    }
}
